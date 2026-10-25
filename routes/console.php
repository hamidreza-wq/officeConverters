<?php

use App\Jobs\ConvertDocument;
use App\Models\Conversion;
use App\Models\User;
use App\Services\Conversions\FormatRegistry;
use App\Services\Conversions\LocalEngineConverter;
use Composer\InstalledVersions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

Artisan::command('converters:health', function (): int {
    try {
        $version = DB::selectOne('SELECT VERSION() AS version, DATABASE() AS db');
        $engine = str_contains(strtolower($version->version), 'mariadb') ? 'MariaDB' : 'MySQL';
        $this->info("{$engine} {$version->version}; database {$version->db}; PDO driver ".DB::connection()->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME));
        $this->info(count(app(FormatRegistry::class)->pairs()).' available conversion pairs; OpenSpout '.InstalledVersions::getPrettyVersion('openspout/openspout'));
        $this->line('Worker last seen: '.(Cache::get('conversion-worker-heartbeat') ?? 'not observed; start queue:work'));
        $health = app(LocalEngineConverter::class)->health();
        $this->line('Local engines: '.json_encode($health, JSON_THROW_ON_ERROR));

        return 0;
    } catch (Throwable $error) {
        Log::error('Health check failed.', ['exception_type' => $error::class]);
        $this->error('MySQL/MariaDB health check failed. Check the server, credentials and migrations. No database fallback was used.');

        return 1;
    }
})->purpose('Verify MySQL/MariaDB identity, implemented engines and worker heartbeat');

Artisan::command('converters:admin {email}', function (): int {
    $user = User::where('email', $this->argument('email'))->first();
    if (! $user || ! $user->hasVerifiedEmail()) {
        $this->error('Register and verify this account before granting administrator access.');

        return 1;
    }
    $user->forceFill(['is_admin' => true])->save();
    DB::table('audit_logs')->insert(['user_id' => $user->id, 'action' => 'admin.granted.cli', 'subject' => (string) $user->id, 'created_at' => now()]);
    $this->info('Administrator access granted and audited.');

    return 0;
})->purpose('Grant admin access to an existing verified account, without a default password');

Artisan::command('converters:cleanup', function (): void {
    $count = 0;
    Conversion::where('expires_at', '<=', now())->where('status', '!=', 'expired')->select('id')->chunkById(100, function ($records) use (&$count): void {
        foreach ($records as $record) {
            DB::transaction(function () use ($record, &$count): void {
                $conversion = Conversion::lockForUpdate()->find($record->id);
                if (! $conversion || $conversion->status === 'processing') {
                    return;
                }
                LocalEngineConverter::cleanupWorkspaces(Storage::disk('local')->path('conversions/'.$conversion->id));
                Storage::disk('local')->deleteDirectory('conversions/'.$conversion->id);
                $conversion->update([
                    'status' => 'expired', 'preview' => null, 'output_path' => null,
                    'output_bytes' => null, 'error_code' => null, 'error_message' => null, 'warnings' => null,
                ]);
                $count++;
            });
        }
    });
    Conversion::where('status', 'expired')->where('expires_at', '<=', now()->subDays(7))->delete();
    DB::table('conversion_usage_logs')->where('created_at', '<', now()->subDays(2))->delete();
    $disk = Storage::disk('local');
    foreach ($disk->files('downloads') as $path) {
        if ($disk->lastModified($path) < now()->subHour()->timestamp) {
            $disk->delete($path);
        }
    }
    $this->info("Expired and removed $count document directories. Expired metadata is retained for up to seven days.");
})->purpose('Remove expired private files and previews, then expire history');

Artisan::command('converters:recover', function (): void {
    $count = 0;
    Conversion::where('status', 'processing')->where('started_at', '<', now()->subMinutes(10))->select('id')->chunkById(100, function ($records) use (&$count): void {
        foreach ($records as $record) {
            DB::transaction(function () use ($record, &$count): void {
                $conversion = Conversion::lockForUpdate()->find($record->id);
                if (! $conversion || $conversion->status !== 'processing') {
                    return;
                }
                if ($conversion->attempts >= 6 || $conversion->expires_at->isPast()) {
                    $conversion->update(['status' => 'failed', 'error_code' => 'worker_interrupted', 'error_message' => 'Worker interrupted. Retry limits or retention window exceeded.']);
                } else {
                    $conversion->update(['status' => 'queued']);
                    ConvertDocument::dispatch($conversion->id)->onConnection('database');
                }
                $count++;
            });
        }
    });
    $this->info("Recovered $count interrupted conversions.");
})->purpose('Recover jobs abandoned by a terminated worker');

Schedule::command('converters:recover')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('converters:cleanup')->everyTenMinutes()->withoutOverlapping();
