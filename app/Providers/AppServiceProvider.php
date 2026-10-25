<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use LogicException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (config('database.default') !== 'mysql') {
            throw new LogicException('This application requires DB_CONNECTION=mysql; no alternative database is supported.');
        }
        Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $event): void {
            $version = (string) $event->connection->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION);
            if ($event->connection->getDriverName() !== 'mysql'
                || version_compare($version, str_contains(strtolower($version), 'mariadb') ? '10.4.0' : '8.0.0', '<')) {
                throw new LogicException('MySQL 8.0+ or MariaDB 10.4+ using the mysql connection is required.');
            }
        });
        Gate::define('admin', fn (User $user) => $user->is_admin);
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(30)->by($request->ip()),
        ]);
        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(10)->by($request->user()->id));
        Queue::looping(fn () => Cache::put('conversion-worker-heartbeat', now()->toIso8601String(), 120));
    }
}
