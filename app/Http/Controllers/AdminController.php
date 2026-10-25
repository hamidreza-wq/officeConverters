<?php

namespace App\Http\Controllers;

use App\Models\Conversion;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AdminController extends Controller
{
    public function index()
    {
        return view('admin', [
            'counts' => Conversion::selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status'),
            'users' => User::count(),
            'enabled' => SystemSetting::conversionsEnabled(),
            'audit' => DB::table('audit_logs')->latest('id')->limit(20)->get(),
            'queued' => DB::table('jobs')->count(),
            'failed' => DB::table('failed_jobs')->count(),
            'version' => DB::selectOne('SELECT VERSION() AS version')->version,
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate(['conversions_enabled' => ['required', 'boolean'], 'password' => ['required', 'string']]);
        if (! Hash::check($data['password'], $request->user()->password)) {
            throw ValidationException::withMessages(['password' => 'Current password is incorrect.']);
        }
        DB::transaction(function () use ($request, $data): void {
            SystemSetting::updateOrCreate(['key' => 'conversions_enabled'], ['value' => (bool) $data['conversions_enabled']]);
            DB::table('audit_logs')->insert([
                'user_id' => $request->user()->id, 'action' => $data['conversions_enabled'] ? 'conversions.resumed' : 'conversions.paused',
                'subject' => 'platform', 'created_at' => now(),
            ]);
        });

        return back()->with('status', 'Conversion availability updated and audited.');
    }
}
