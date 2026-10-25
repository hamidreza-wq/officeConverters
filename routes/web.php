<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BatchDownloadController;
use App\Http\Controllers\ConversionController;
use App\Services\Conversions\FormatRegistry;
use App\Services\Conversions\LocalEngineConverter;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => config('app.desktop_mode') ? redirect()->route('dashboard') : view('home'))->name('home');
Route::get('/tools', fn (FormatRegistry $registry) => view('tools', ['pairs' => $registry->pairs()]))->name('tools');
Route::view('/privacy', 'privacy')->name('privacy');

if (config('app.desktop_mode')) {
    Route::get('/desktop/ready', function (LocalEngineConverter $engine): JsonResponse {
        return response()->json([
            'ready' => (bool) ($engine->health()['ready'] ?? false),
            'launch' => hash('sha256', config('app.desktop_token')),
        ]);
    });
}

if (! config('app.desktop_mode')) {
    Route::middleware('guest')->group(function (): void {
        Route::view('/register', 'auth.form', ['mode' => 'register'])->name('register');
        Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
        Route::view('/login', 'auth.form', ['mode' => 'login'])->name('login');
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
        Route::view('/forgot-password', 'auth.form', ['mode' => 'forgot'])->name('password.request');
        Route::post('/forgot-password', [AuthController::class, 'forgot'])->middleware('throttle:5,1')->name('password.email');
        Route::get('/reset-password/{token}', fn (string $token) => view('auth.form', ['mode' => 'reset', 'token' => $token]))->name('password.reset');
        Route::post('/reset-password', [AuthController::class, 'reset'])->middleware('throttle:5,1')->name('password.update');
    });
}

Route::middleware(config('app.desktop_mode') ? [] : ['auth'])->group(function (): void {
    if (! config('app.desktop_mode')) {
        Route::view('/account', 'account')->name('account');
        Route::get('/account/export', [AccountController::class, 'export'])->middleware('throttle:5,1')->name('account.export');
        Route::delete('/account', [AccountController::class, 'destroy'])->middleware('throttle:5,1')->name('account.destroy');
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        Route::view('/verify-email', 'auth.verify')->name('verification.notice');
        Route::get('/verify-email/{id}/{hash}', [AuthController::class, 'verify'])->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
        Route::post('/email/verification-notification', [AuthController::class, 'resend'])->middleware('throttle:1,1')->name('verification.send');
    }
    Route::middleware(config('app.desktop_mode') ? [] : ['verified'])->group(function (): void {
        Route::post('/downloads/batch', BatchDownloadController::class)->middleware('throttle:uploads')->name('conversions.batch');
        Route::get('/dashboard', [ConversionController::class, 'index'])->name('dashboard');
        Route::post('/conversions', [ConversionController::class, 'store'])->middleware('throttle:uploads')->name('conversions.store');
        Route::get('/conversions/{conversion}', [ConversionController::class, 'show'])->name('conversions.show');
        Route::get('/conversions/{conversion}/status', [ConversionController::class, 'status'])->name('conversions.status');
        Route::get('/conversions/{conversion}/download', [ConversionController::class, 'download'])->name('conversions.download');
        Route::post('/conversions/{conversion}/retry', [ConversionController::class, 'retry'])->middleware('throttle:uploads')->name('conversions.retry');
        Route::delete('/conversions/{conversion}', [ConversionController::class, 'destroy'])->name('conversions.destroy');

        if (! config('app.desktop_mode')) {
            Route::middleware('can:admin')->group(function (): void {
                Route::get('/admin', [AdminController::class, 'index'])->name('admin');
                Route::patch('/admin', [AdminController::class, 'update'])->middleware('throttle:5,1')->name('admin.update');
            });
        }
    });
});
