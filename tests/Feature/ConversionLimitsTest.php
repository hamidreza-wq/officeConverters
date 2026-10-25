<?php

namespace Tests\Feature;

use App\Jobs\ConvertDocument;
use App\Models\Conversion;
use App\Models\User;
use App\Services\Conversions\ConversionException;
use App\Services\Conversions\FormatRegistry;
use App\Services\Conversions\TabularConverter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;
use ZipArchive;

class ConversionLimitsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_exact_row_and_column_boundaries_and_unicode_values(): void
    {
        config(['conversions.max_rows' => 2, 'conversions.max_columns' => 2]);
        $path = Storage::disk('local')->path('boundary.json');
        file_put_contents($path, '[["a","b"],["c","d"]]');
        $this->assertCount(2, app(TabularConverter::class)->read($path, 'json'));
        file_put_contents($path, '[["a","b","c"]]');
        $this->expectException(ConversionException::class);
        app(TabularConverter::class)->read($path, 'json');
    }

    public function test_invalid_utf8_cannot_be_converted(): void
    {
        $path = Storage::disk('local')->path('invalid.csv');
        file_put_contents($path, "name\n\xC3\x28");
        $this->expectException(ConversionException::class);
        app(TabularConverter::class)->read($path, 'csv');
    }

    public function test_broken_csv_quoting_is_rejected_instead_of_repaired_silently(): void
    {
        $path = Storage::disk('local')->path('broken.csv');
        file_put_contents($path, "name,value\n\"unclosed,value\n");
        $this->expectException(ConversionException::class);
        app(TabularConverter::class)->read($path, 'csv');
    }

    public function test_malformed_workbook_xml_is_a_document_error(): void
    {
        $path = Storage::disk('local')->path('malformed.xlsx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('[Content_Types].xml', '<Types/>');
        $zip->addFromString('xl/workbook.xml', '<workbook/>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet><sheetData></worksheet>');
        $zip->close();
        $this->expectException(ConversionException::class);
        app(TabularConverter::class)->read($path, 'xlsx');
    }

    public function test_expansion_limit_prevents_zip_bombs_before_parsing(): void
    {
        config(['conversions.max_uncompressed_bytes' => 1024]);
        $path = Storage::disk('local')->path('bomb.xlsx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('[Content_Types].xml', '<Types/>');
        $zip->addFromString('xl/workbook.xml', '<workbook/>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet>'.str_repeat(' ', 2000).'</worksheet>');
        $zip->close();
        $this->expectException(ConversionException::class);
        app(TabularConverter::class)->read($path, 'xlsx');
    }

    private function queued(): Conversion
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('conversions.store'), [
            'files' => [UploadedFile::fake()->createWithContent('sample.csv', "name,value\nhello,12\n")],
            'target_format' => 'json', 'request_key' => (string) Str::uuid(), 'accept_limitations' => 1,
        ])->assertRedirect();

        return Conversion::where('user_id', $user->id)->sole();
    }

    public function test_timeout_failure_is_explicit_and_retry_limits_are_enforced(): void
    {
        $conversion = $this->queued();
        $conversion->update(['status' => 'processing', 'attempts' => 6]);
        (new ConvertDocument($conversion->id))->failed(new \RuntimeException('Worker timeout'));
        $this->assertSame('failed', $conversion->fresh()->status);
        $this->assertSame('engine_error', $conversion->fresh()->error_code);
        $this->post(route('conversions.retry', $conversion))->assertConflict();
    }

    public function test_missing_input_is_retried_without_logging_document_contents(): void
    {
        $conversion = $this->queued();
        Storage::disk('local')->delete($conversion->input_path);
        try {
            (new ConvertDocument($conversion->id))->handle(app(FormatRegistry::class));
            $this->fail('Missing input was not reported.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Conversion engine failed; see the conversion ID in the application log.', $error->getMessage());
        }
        $this->assertSame('queued', $conversion->fresh()->status);
        $this->assertSame('engine_error', $conversion->fresh()->error_code);
    }

    public function test_abandoned_processing_jobs_are_recovered(): void
    {
        $conversion = $this->queued();
        $conversion->update(['status' => 'processing', 'attempts' => 1, 'started_at' => now()->subMinutes(11)]);
        $this->artisan('converters:recover')->assertSuccessful();
        $this->assertSame('queued', $conversion->fresh()->status);
        (new ConvertDocument($conversion->id))->handle(app(FormatRegistry::class));
        $this->assertSame('completed', $conversion->fresh()->status);
    }

    public function test_deleting_history_does_not_reset_the_daily_quota(): void
    {
        $conversion = $this->queued();
        $userId = $conversion->user_id;
        $this->delete(route('conversions.destroy', $conversion))->assertRedirect();
        $this->assertSame(1, (int) DB::table('conversion_usage_logs')->where('user_id', $userId)->sum('quantity'));
        DB::table('conversion_usage_logs')->insert(['user_id' => $userId, 'quantity' => 99, 'created_at' => now()]);
        $this->post(route('conversions.store'), [
            'files' => [UploadedFile::fake()->createWithContent('another.csv', "name,value\nhello,12\n")],
            'target_format' => 'json', 'request_key' => (string) Str::uuid(), 'accept_limitations' => 1,
        ])->assertSessionHasErrors('files');
    }

    public function test_native_xlsx_dates_numbers_and_booleans_have_explicit_text_values(): void
    {
        $path = Storage::disk('local')->path('typed.xlsx');
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValuesWithStyles([
            new \DateTimeImmutable('2026-10-25T00:00:00+00:00'), 123, true, '00012',
        ], [0 => new Style(format: 'yyyy-mm-dd')]));
        $writer->close();
        $values = app(TabularConverter::class)->read($path, 'xlsx');
        $this->assertSame(['2026-10-25T00:00:00+00:00', '123', 'true', '00012'], $values[0]);
    }
}
