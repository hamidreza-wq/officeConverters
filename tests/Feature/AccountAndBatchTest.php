<?php

namespace Tests\Feature;

use App\Jobs\ConvertDocument;
use App\Models\Conversion;
use App\Models\User;
use App\Services\Conversions\FormatRegistry;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

class AccountAndBatchTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function completed(User $user): Conversion
    {
        $this->actingAs($user)->post(route('conversions.store'), [
            'files' => [UploadedFile::fake()->createWithContent('data.csv', "name,value\nhello,00012\n")],
            'target_format' => 'json', 'request_key' => (string) Str::uuid(), 'accept_limitations' => 1,
        ])->assertRedirect();
        $conversion = Conversion::where('user_id', $user->id)->latest('created_at')->latest('id')->firstOrFail();
        (new ConvertDocument($conversion->id))->handle(app(FormatRegistry::class));
        $this->assertSame('completed', $conversion->fresh()->status);

        return $conversion->fresh();
    }

    public function test_batch_zip_contains_real_outputs_and_blocks_other_users(): void
    {
        $user = User::factory()->create();
        $conversion = $this->completed($user);
        $response = $this->post(route('conversions.batch'), ['conversion_ids' => [$conversion->id]])->assertOk()->assertDownload('converted-documents.zip');
        $path = $response->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $this->assertSame(1, $zip->numFiles);
        $this->assertSame([['name', 'value'], ['hello', '00012']], json_decode($zip->getFromIndex(0), true, flags: JSON_THROW_ON_ERROR));
        $zip->close();
        unlink($path);
        $this->actingAs(User::factory()->create())->post(route('conversions.batch'), ['conversion_ids' => [$conversion->id]])->assertForbidden();
        $this->actingAs($user);
        $conversion->update(['expires_at' => now()->subSecond()]);
        $this->post(route('conversions.batch'), ['conversion_ids' => [$conversion->id]])->assertGone();
    }

    public function test_account_export_excludes_secrets_and_storage_paths(): void
    {
        $user = User::factory()->create();
        $this->completed($user);
        $this->get(route('account'))->assertOk();
        $response = $this->get(route('account.export'))->assertDownload('office-converters-account.json');
        $data = json_decode($response->streamedContent(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($user->email, $data['account']['email']);
        $this->assertCount(1, $data['conversions']);
        $this->assertArrayNotHasKey('password', $data['account']);
        $this->assertArrayNotHasKey('input_path', $data['conversions'][0]);
    }

    public function test_account_deletion_requires_password_then_removes_all_private_data(): void
    {
        $user = User::factory()->create();
        $conversion = $this->completed($user);
        $this->delete(route('account.destroy'), ['password' => 'incorrect'])->assertSessionHasErrors('password');
        $this->delete(route('account.destroy'), ['password' => 'password'])->assertRedirect(route('home'));
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('conversions', ['id' => $conversion->id]);
        Storage::disk('local')->assertMissing($conversion->input_path);
        Storage::disk('local')->assertMissing($conversion->output_path);
        $this->assertGuest();
    }

    public function test_processing_document_blocks_account_deletion(): void
    {
        $user = User::factory()->create();
        $conversion = $this->completed($user);
        $conversion->update(['status' => 'processing']);
        $this->delete(route('account.destroy'), ['password' => 'password'])->assertConflict();
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }
}
