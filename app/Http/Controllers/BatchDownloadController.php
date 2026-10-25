<?php

namespace App\Http\Controllers;

use App\Models\Conversion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

class BatchDownloadController extends Controller
{
    public function __invoke(Request $request)
    {
        $data = $request->validate([
            'conversion_ids' => ['required', 'array', 'min:1', 'max:5'],
            'conversion_ids.*' => ['required', 'uuid', 'distinct'],
        ]);
        $records = Conversion::where('user_id', $request->user()->id)->whereIn('id', $data['conversion_ids'])->get();
        abort_unless($records->count() === count($data['conversion_ids']), 403, 'Only your own results can be downloaded.');
        foreach ($records as $record) {
            abort_unless($record->available(), 410, 'One or more results have expired or are unavailable.');
        }
        $disk = Storage::disk('local');
        $disk->makeDirectory('downloads');
        $path = $disk->path('downloads/'.Str::uuid().'.zip');
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RuntimeException('Could not create the download archive.');
        }
        try {
            foreach ($records as $index => $record) {
                abort_unless($disk->exists($record->output_path), 410, 'One or more result files have been removed.');
                $name = Str::slug(pathinfo($record->original_name, PATHINFO_FILENAME)) ?: 'converted';
                if (! $zip->addFile($disk->path($record->output_path), ($index + 1).'-'.$name.'.'.$record->resultExtension())) {
                    throw new RuntimeException('Could not add a result to the archive.');
                }
            }
            if (! $zip->close()) {
                throw new RuntimeException('Could not finalize the download archive.');
            }
        } catch (Throwable $error) {
            unset($zip);
            if (is_file($path)) {
                unlink($path);
            }
            throw $error;
        }

        return response()->download($path, 'converted-documents.zip', ['Content-Type' => 'application/zip'])->deleteFileAfterSend(true);
    }
}
