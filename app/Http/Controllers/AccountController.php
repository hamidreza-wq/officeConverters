<?php

namespace App\Http\Controllers;

use App\Models\Conversion;
use App\Models\User;
use App\Services\Conversions\LocalEngineConverter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AccountController extends Controller
{
    public function destroy(Request $request)
    {
        $request->validate(['password' => ['required', 'string']]);
        if (! Hash::check($request->input('password'), $request->user()->password)) {
            throw ValidationException::withMessages(['password' => 'Current password is incorrect.']);
        }
        DB::transaction(function () use ($request): void {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $conversions = Conversion::where('user_id', $user->id)->lockForUpdate()->get();
            abort_if($conversions->contains('status', 'processing'), 409, 'Wait for active processing to finish before deleting your account.');
            foreach ($conversions as $conversion) {
                LocalEngineConverter::cleanupWorkspaces(Storage::disk('local')->path('conversions/'.$conversion->id));
                Storage::disk('local')->deleteDirectory('conversions/'.$conversion->id);
            }
            DB::table('sessions')->where('user_id', $user->id)->delete();
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            DB::table('audit_logs')->where('user_id', $user->id)->where('subject', (string) $user->id)->update(['subject' => null]);
            $user->delete();
        });
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home')->with('status', 'Your account, documents and conversion history have been deleted.');
    }

    public function export(Request $request)
    {
        $user = $request->user();

        return response()->streamDownload(function () use ($user): void {
            echo '{"account":'.json_encode($user->only(['name', 'email', 'created_at', 'email_verified_at']), JSON_THROW_ON_ERROR).',"conversions":[';
            $first = true;
            foreach (Conversion::where('user_id', $user->id)->cursor() as $conversion) {
                echo $first ? '' : ',';
                echo json_encode($conversion->only([
                    'id', 'original_name', 'source_format', 'target_format', 'input_bytes', 'output_bytes',
                    'checksum', 'status', 'error_code', 'output_extension', 'warnings', 'created_at', 'completed_at', 'expires_at',
                ]), JSON_THROW_ON_ERROR);
                $first = false;
            }
            echo ']}';
        }, 'office-converters-account.json', ['Content-Type' => 'application/json']);
    }
}
