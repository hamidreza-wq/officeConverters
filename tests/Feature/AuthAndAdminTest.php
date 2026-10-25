<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AuthAndAdminTest extends TestCase
{
    use DatabaseTransactions;

    public function test_registration_email_verification_login_and_logout(): void
    {
        Notification::fake();
        $this->post('/register', [
            'name' => 'New User', 'email' => 'new@example.test',
            'password' => 'LongPassword123!', 'password_confirmation' => 'LongPassword123!',
            'is_admin' => true,
        ])->assertRedirect(route('verification.notice'));
        $user = User::where('email', 'new@example.test')->sole();
        $this->assertFalse($user->is_admin);
        Notification::assertSentTo($user, VerifyEmail::class);
        $this->get(URL::temporarySignedRoute('verification.verify', now()->addMinutes(30), [
            'id' => $user->id, 'hash' => sha1($user->email),
        ]))->assertRedirect(route('dashboard'));
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->post('/logout')->assertRedirect('/');
        $this->assertGuest();
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => $user->email, 'password' => 'LongPassword123!'])->assertRedirect(route('dashboard'));
        $this->get('/dashboard')->assertOk();
    }

    public function test_password_reset_updates_hashed_password(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->post('/forgot-password', ['email' => $user->email])->assertRedirect();
        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use (&$token): bool {
            $token = $notification->token;

            return true;
        });
        $this->post('/reset-password', [
            'email' => $user->email, 'token' => $token,
            'password' => 'NewLongPassword123!', 'password_confirmation' => 'NewLongPassword123!',
        ])->assertRedirect(route('login'));
        $this->assertTrue(Hash::check('NewLongPassword123!', $user->fresh()->password));
    }

    public function test_admin_access_and_reauthentication_are_enforced(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/admin')->assertForbidden();
        $this->patch('/admin', ['conversions_enabled' => 0, 'password' => 'password'])->assertForbidden();
        $user->forceFill(['is_admin' => true])->save();
        $this->get('/admin')->assertOk()->assertSee('Database:');
        $this->patch('/admin', ['conversions_enabled' => 0, 'password' => 'incorrect'])->assertSessionHasErrors('password');
        $this->patch('/admin', ['conversions_enabled' => 0, 'password' => 'password'])->assertRedirect();
        $this->assertFalse(SystemSetting::conversionsEnabled());
        $this->assertTrue(DB::table('audit_logs')->where('action', 'conversions.paused')->exists());
    }

    public function test_security_headers_and_guest_protection(): void
    {
        $this->get('/')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'DENY');
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/tools')->assertOk()->assertSee('What each engine can do');
        $this->get('/privacy')->assertOk();
    }
}
