<?php

namespace Tests\Desktop;

use App\Jobs\ConvertDocument;
use App\Models\Conversion;
use App\Models\User;
use App\Services\Conversions\FormatRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class DesktopDatabaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_desktop_mode_migrates_local_metadata_and_queue_tables_to_sqlite(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame('sqlite', config('queue.connections.database.connection'));

        foreach (['users', 'conversions', 'system_settings', 'jobs', 'failed_jobs'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Expected the $table table to be migrated.");
        }
    }

    public function test_desktop_mode_opens_a_local_workspace_without_account_routes(): void
    {
        $this->get('/')->assertForbidden();
        $this->withHeader('X-OfficeConverters-Desktop', config('app.desktop_token'));
        $this->get('/')->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertOk()->assertDontSee('Sign out')->assertDontSee('Create your free workspace');

        $user = User::query()->sole();
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->email_verified_at);
        $this->assertFalse($user->is_admin);
        $this->assertFalse(Route::has('register'));
        $this->assertFalse(Route::has('login'));
        $this->assertFalse(Route::has('account'));
        $this->assertFalse(Route::has('admin'));

        $this->get('/dashboard')->assertOk();
        $this->get('/register')->assertNotFound();
        $this->get('/account')->assertNotFound();
        $this->get('/admin')->assertNotFound();
    }

    public function test_desktop_workspace_rejects_wrong_tokens_and_non_loopback_requests(): void
    {
        $this->withHeader('X-OfficeConverters-Desktop', 'incorrect-token')
            ->get('/dashboard')->assertForbidden();
        $this->withHeader('X-OfficeConverters-Desktop', config('app.desktop_token'))
            ->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
            ->get('/dashboard')->assertForbidden();
        $this->assertSame(0, User::query()->count());
    }

    public function test_desktop_sqlite_queue_completes_a_real_tabular_conversion(): void
    {
        $this->withHeader('X-OfficeConverters-Desktop', config('app.desktop_token'));
        Storage::fake('local');

        $this->post('/conversions', [
            'files' => [UploadedFile::fake()->createWithContent('sample.csv', "Name,Amount\ncafé,12.50\n")],
            'target_format' => 'xlsx',
            'request_key' => (string) Str::uuid(),
            'accept_limitations' => '1',
        ])->assertRedirect();

        $conversion = Conversion::query()->sole();
        $this->assertSame('queued', $conversion->status);
        $this->assertSame(1, DB::table('jobs')->count());

        (new ConvertDocument($conversion->id))->handle(app(FormatRegistry::class));
        $conversion->refresh();

        $this->assertSame('completed', $conversion->status, $conversion->error_message ?? '');
        $this->assertSame('xlsx', $conversion->resultExtension());
        $this->assertGreaterThan(20, Storage::disk('local')->size($conversion->output_path));
        $this->assertStringStartsWith('PK', Storage::disk('local')->get($conversion->output_path));
    }
}
