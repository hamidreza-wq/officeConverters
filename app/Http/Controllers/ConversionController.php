<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreConversionRequest;
use App\Jobs\ConvertDocument;
use App\Models\Conversion;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Conversions\FormatRegistry;
use App\Services\Conversions\LocalEngineConverter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;
use Throwable;

class ConversionController extends Controller
{
    public function index(Request $request)
    {
        return view('dashboard', [
            'conversions' => Conversion::where('user_id', $request->user()->id)->latest()->paginate(15),
            'enabled' => SystemSetting::conversionsEnabled(),
            'storageBytes' => Conversion::where('user_id', $request->user()->id)->where('status', '!=', 'expired')->sum(DB::raw('input_bytes + COALESCE(output_bytes, 0)')),
        ]);
    }

    public function store(StoreConversionRequest $request, FormatRegistry $registry)
    {
        abort_unless(SystemSetting::conversionsEnabled(), 503, 'New conversions are temporarily paused.');
        $target = $request->validated('target_format');
        $uploads = $request->file('files');
        foreach ($uploads as $index => $upload) {
            $source = strtolower($upload->getClientOriginalExtension());
            $name = $upload->getClientOriginalName();
            if (strlen($name) > 200 || preg_match('/[\/\\\\\x00-\x1F]/', $name) || ! $registry->supports($source, $target)) {
                throw ValidationException::withMessages(['files' => 'Unsupported filename or conversion. Select different supported source and target formats.']);
            }
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($upload->getRealPath());
            $allowed = $registry->mimeTypes($source);
            if (! in_array($mime, $allowed, true)) {
                throw ValidationException::withMessages(['files' => 'The detected file content does not match an accepted format.']);
            }
            if (in_array($source, ['docx', 'pptx', 'odt', 'ods', 'odp', 'xlsx'], true)
                && $mime !== 'application/zip' && ! str_starts_with($mime, 'application/vnd.')
                && $mime !== 'application/octet-stream') {
                throw ValidationException::withMessages(['files' => 'Office package signature does not match the extension.']);
            }
        }

        $stored = [];
        try {
            $ids = DB::transaction(function () use ($request, $uploads, $target, &$stored): array {
                User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
                $keys = [];
                foreach ($uploads as $index => $upload) {
                    $keys[] = Uuid::uuid5(Uuid::NAMESPACE_URL, $request->user()->id.'|'.$request->validated('request_key').'|'.$index)->toString();
                }
                $existing = Conversion::where('user_id', $request->user()->id)->whereIn('request_key', $keys)->pluck('id')->all();
                if (count($existing) === count($keys)) {
                    return $existing;
                }
                $active = Conversion::where('user_id', $request->user()->id)->whereIn('status', ['queued', 'processing'])->count();
                if ($active + count($uploads) > config('conversions.max_active')) {
                    throw ValidationException::withMessages(['files' => 'You have reached the active conversion limit. Wait for existing jobs to finish.']);
                }
                $used = DB::table('conversion_usage_logs')->where('user_id', $request->user()->id)->where('created_at', '>=', now()->subDay())->sum('quantity');
                if ($used + count($uploads) > 100) {
                    throw ValidationException::withMessages(['files' => 'Daily conversion quota reached (100 documents).']);
                }
                $ids = [];
                foreach ($uploads as $index => $upload) {
                    $source = strtolower($upload->getClientOriginalExtension());
                    $id = (string) Str::uuid();
                    $path = $upload->storeAs('conversions/'.$id, 'input.'.$source, 'local');
                    $stored[] = 'conversions/'.$id;
                    $conversion = new Conversion([
                        'user_id' => $request->user()->id, 'request_key' => $keys[$index],
                        'original_name' => $upload->getClientOriginalName(), 'source_format' => $source,
                        'target_format' => $target, 'mime_type' => (new \finfo(FILEINFO_MIME_TYPE))->file($upload->getRealPath()), 'input_bytes' => $upload->getSize(),
                        'checksum' => hash_file('sha256', Storage::disk('local')->path($path)),
                        'input_path' => $path, 'expires_at' => now()->addHours(config('conversions.retention_hours')),
                    ]);
                    $conversion->id = $id;
                    $conversion->save();
                    // The database queue shares this transaction, so the record and queued work commit together.
                    ConvertDocument::dispatch($id)->onConnection('database');
                    $ids[] = $id;
                }
                DB::table('conversion_usage_logs')->insert([
                    'user_id' => $request->user()->id, 'quantity' => count($uploads), 'created_at' => now(),
                ]);

                return $ids;
            });
        } catch (Throwable $error) {
            foreach ($stored as $directory) {
                Storage::disk('local')->deleteDirectory($directory);
            }
            throw $error;
        }

        return redirect()->route('conversions.show', $ids[0])->with('status', count($ids).' document(s) queued. Watch all results in your dashboard.');
    }

    public function show(Conversion $conversion)
    {
        Gate::authorize('view', $conversion);

        return view('conversions.show', compact('conversion'));
    }

    public function status(Conversion $conversion)
    {
        Gate::authorize('view', $conversion);

        return response()->json([
            'status' => $conversion->status, 'error' => $conversion->error_message,
            'available' => $conversion->available(),
        ]);
    }

    public function download(Conversion $conversion)
    {
        Gate::authorize('view', $conversion);
        abort_unless($conversion->available(), 410, 'This result is unavailable or expired.');
        abort_unless(Storage::disk('local')->exists($conversion->output_path), 410, 'Result file is no longer available.');
        $name = Str::slug(pathinfo($conversion->original_name, PATHINFO_FILENAME)) ?: 'converted';

        return Storage::disk('local')->download($conversion->output_path, $name.'.'.$conversion->resultExtension(), ['X-Content-Type-Options' => 'nosniff']);
    }

    public function retry(Conversion $conversion)
    {
        Gate::authorize('view', $conversion);
        abort_unless(SystemSetting::conversionsEnabled(), 503, 'New conversions are temporarily paused.');
        DB::transaction(function () use ($conversion): void {
            User::whereKey($conversion->user_id)->lockForUpdate()->firstOrFail();
            $record = Conversion::lockForUpdate()->findOrFail($conversion->id);
            abort_unless($record->status === 'failed' && $record->expires_at->isFuture(), 409, 'Only unexpired failed jobs can be retried.');
            abort_unless($record->attempts < 6, 409, 'Maximum retry attempts reached. Delete this file and contact support.');
            $active = Conversion::where('user_id', $record->user_id)->whereIn('status', ['queued', 'processing'])->count();
            abort_if($active >= config('conversions.max_active'), 409, 'Active conversion limit reached. Wait for existing jobs to finish.');
            $record->update(['status' => 'queued', 'error_code' => null, 'error_message' => null]);
            ConvertDocument::dispatch($record->id)->onConnection('database');
        });

        return back()->with('status', 'Conversion queued for retry.');
    }

    public function destroy(Conversion $conversion)
    {
        Gate::authorize('delete', $conversion);
        DB::transaction(function () use ($conversion): void {
            $record = Conversion::lockForUpdate()->findOrFail($conversion->id);
            abort_if($record->status === 'processing', 409, 'Wait for processing to finish before deleting.');
            LocalEngineConverter::cleanupWorkspaces(Storage::disk('local')->path('conversions/'.$record->id));
            Storage::disk('local')->deleteDirectory('conversions/'.$record->id);
            $record->delete();
        });

        return redirect()->route('dashboard')->with('status', 'Document, result and history deleted.');
    }
}
