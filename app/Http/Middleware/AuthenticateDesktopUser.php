<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateDesktopUser
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('app.desktop_mode')) {
            return $next($request);
        }
        abort_unless(in_array($request->server('REMOTE_ADDR'), ['127.0.0.1', '::1'], true), 403);
        $desktopToken = (string) config('app.desktop_token');
        if (! hash_equals($desktopToken, (string) $request->header('X-OfficeConverters-Desktop'))) {
            abort(403);
        }

        $user = User::query()->firstOrCreate(
            ['email' => 'local-user@officeconverters.invalid'],
            ['name' => 'Local User', 'password' => Str::random(64)],
        );
        if (! $user->hasVerifiedEmail()) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        Auth::guard()->setUser($user);

        return $next($request);
    }
}
