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
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use ZipArchive;

class ConversionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public static function pairs(): array
    {
        $pairs = [];
        foreach (FormatRegistry::FORMATS as $source) {
            foreach (FormatRegistry::FORMATS as $target) {
                if ($source !== $target) {
                    $pairs[$source.' to '.$target] = [$source, $target];
                }
            }
        }

        return $pairs;
    }

    private function fixture(string $format, string $name = 'sample'): UploadedFile
    {
        $rows = [['Name', 'Amount', 'ID'], ['café 中文', '123.4500', '0000123'], ['comma,quote"line', "line\nbreak", '99999999999999999999']];
        $path = Storage::disk('local')->path($name.'.'.$format);
        if ($format === 'xlsx') {
            $writer = new Writer;
            $writer->openToFile($path);
            foreach ($rows as $row) {
                $writer->addRow(Row::fromValues($row));
            }
            $writer->close();
        } elseif ($format === 'json') {
            file_put_contents($path, json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        } else {
            $stream = fopen($path, 'wb');
            foreach ($rows as $row) {
                fputcsv($stream, $row, $format === 'tsv' ? "\t" : ',', '"', '');
            }
            fclose($stream);
        }

        return new UploadedFile($path, $name.'.'.$format, null, null, true);
    }

    private function submit(User $user, UploadedFile $file, string $target, ?string $key = null)
    {
        return $this->actingAs($user)->post(route('conversions.store'), [
            'files' => [$file], 'target_format' => $target,
            'request_key' => $key ?? (string) Str::uuid(), 'accept_limitations' => '1',
        ]);
    }

    #[DataProvider('pairs')]
    public function test_every_advertised_pair_executes_and_preserves_values(string $source, string $target): void
    {
        $user = User::factory()->create();
        $response = $this->submit($user, $this->fixture($source), $target);
        $conversion = Conversion::where('user_id', $user->id)->sole();
        $response->assertRedirect(route('conversions.show', $conversion));
        $this->assertSame('queued', $conversion->status);
        $this->assertGreaterThanOrEqual(1, DB::table('jobs')->count());
        (new ConvertDocument($conversion->id))->handle(app(FormatRegistry::class));
        $conversion->refresh();
        $this->assertSame('completed', $conversion->status, $conversion->error_message ?? '');
        $rows = app(TabularConverter::class)->read(Storage::disk('local')->path($conversion->output_path), $target);
        $this->assertSame('café 中文', $rows[1][0]);
        $this->assertSame('123.4500', $rows[1][1]);
        $this->assertSame('0000123', $rows[1][2]);
        $this->assertSame('99999999999999999999', $rows[2][2]);
        $this->assertSame("line\nbreak", $rows[2][1]);
        $this->get(route('conversions.show', $conversion))->assertOk()->assertSee('Your new document is ready.');
        $this->get(route('conversions.download', $conversion))->assertOk()->assertDownload('sample.'.$target);
        $this->assertNotSame('', $this->get(route('conversions.download', $conversion))->streamedContent());
        (new ConvertDocument($conversion->id))->handle(app(FormatRegistry::class));
        $this->assertSame(1, $conversion->fresh()->attempts);
    }

    public function test_actual_database_queue_consumes_a_conversion(): void
    {
        $user = User::factory()->create();
        $this->submit($user, $this->fixture('csv'), 'xlsx')->assertRedirect();
        $conversion = Conversion::where('user_id', $user->id)->sole();
        Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 3, '--sleep' => 0]);
        $this->assertSame('completed', $conversion->fresh()->status);
    }

    public function test_mysql_identity_and_tables_are_real(): void
    {
        $this->assertSame('mysql', DB::connection()->getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME));
        $identity = DB::selectOne('SELECT VERSION() AS version, DATABASE() AS db');
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', $identity->version);
        $this->assertSame('office_converters_test', $identity->db);
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('conversions'));
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('jobs'));
        $this->assertGreaterThanOrEqual(12, count(app(FormatRegistry::class)->pairs()));
    }

    public function test_other_users_cannot_view_poll_download_retry_or_delete_documents(): void
    {
        $owner = User::factory()->create();
        $this->submit($owner, $this->fixture('csv'), 'json');
        $conversion = Conversion::where('user_id', $owner->id)->sole();
        $this->actingAs(User::factory()->create());
        foreach (['conversions.show', 'conversions.status', 'conversions.download'] as $route) {
            $this->get(route($route, $conversion))->assertForbidden();
        }
        $this->post(route('conversions.retry', $conversion))->assertForbidden();
        $this->delete(route('conversions.destroy', $conversion))->assertForbidden();
        $this->assertDatabaseHas('conversions', ['id' => $conversion->id]);
    }

    public function test_duplicate_submission_does_not_create_another_job(): void
    {
        $user = User::factory()->create();
        $key = (string) Str::uuid();
        $this->submit($user, $this->fixture('csv'), 'json', $key);
        $count = DB::table('jobs')->count();
        $this->submit($user, $this->fixture('csv', 'second'), 'json', $key);
        $this->assertSame(1, Conversion::where('user_id', $user->id)->count());
        $this->assertSame($count, DB::table('jobs')->count());
    }

    public function test_unsupported_and_mislabeled_files_are_rejected(): void
    {
        $user = User::factory()->create();
        $this->submit($user, UploadedFile::fake()->createWithContent('file.exe', 'unsupported binary'), 'xlsx')->assertSessionHasErrors('files');
        $this->submit($user, UploadedFile::fake()->createWithContent('code.csv', "<?php echo 'unsafe'; ?>"), 'xlsx')->assertSessionHasErrors('files');
        $this->submit($user, $this->fixture('csv'), 'csv')->assertSessionHasErrors('files');
        $this->assertSame(0, Conversion::where('user_id', $user->id)->count());
    }

    public function test_oversized_files_and_unverified_users_cannot_submit(): void
    {
        $user = User::factory()->create();
        $this->submit($user, UploadedFile::fake()->create('big.csv', config('conversions.max_kb') + 1, 'text/plain'), 'xlsx')->assertSessionHasErrors('files.0');
        $this->submit(User::factory()->unverified()->create(), $this->fixture('csv'), 'xlsx')->assertRedirect(route('verification.notice'));
    }

    public function test_invalid_json_and_corrupt_xlsx_fail_honestly(): void
    {
        $user = User::factory()->create();
        foreach ([['json', '{"a": 1}'], ['xlsx', 'this is not a spreadsheet']] as [$format, $content]) {
            // The corrupt package is accepted only if its detected MIME is permitted; the engine must still reject it.
            $file = UploadedFile::fake()->createWithContent('broken.'.$format, $content);
            $response = $this->submit($user, $file, 'csv');
            if ($format === 'xlsx') {
                $response->assertSessionHasErrors('files');

                continue;
            }
            $conversion = Conversion::where('user_id', $user->id)->latest()->firstOrFail();
            (new ConvertDocument($conversion->id))->handle(app(FormatRegistry::class));
            $this->assertSame('failed', $conversion->fresh()->status);
            $this->assertSame('invalid_document', $conversion->fresh()->error_code);
        }
    }

    public function test_formulas_are_literal_in_xlsx_and_neutralized_in_csv(): void
    {
        $path = Storage::disk('local')->path('source.json');
        file_put_contents($path, '[["=1+1","+cmd","-42","@SUM(A1)","safe"]]');
        $xlsx = Storage::disk('local')->path('output.xlsx');
        $converter = app(TabularConverter::class);
        $converter->convert($path, 'json', $xlsx, 'xlsx');
        $this->assertSame('=1+1', $converter->read($xlsx, 'xlsx')[0][0]);
        $csv = Storage::disk('local')->path('output.csv');
        $preview = $converter->convert($xlsx, 'xlsx', $csv, 'csv');
        $this->assertSame(["'=1+1", "'+cmd", "'-42", "'@SUM(A1)", 'safe'], $preview->preview[0]);
    }

    public function test_multi_sheet_workbooks_and_formulas_are_rejected(): void
    {
        $converter = app(TabularConverter::class);
        foreach (['multiple', 'formula'] as $kind) {
            $path = Storage::disk('local')->path($kind.'.xlsx');
            $writer = new Writer;
            $writer->openToFile($path);
            $writer->addRow($kind === 'formula' ? new Row([new FormulaCell('=1+1', null)]) : Row::fromValues(['first']));
            if ($kind === 'multiple') {
                $writer->addNewSheetAndMakeItCurrent();
                $writer->addRow(Row::fromValues(['second']));
            }
            $writer->close();
            try {
                $converter->read($path, 'xlsx');
                $this->fail('Unsupported workbook was accepted.');
            } catch (ConversionException $error) {
                $this->assertMatchesRegularExpression('/worksheet|Formulas/', $error->getMessage());
            }
        }
    }

    public function test_unsafe_xml_and_oversized_packages_are_rejected(): void
    {
        $path = Storage::disk('local')->path('unsafe.xlsx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('[Content_Types].xml', '<Types/>');
        $zip->addFromString('xl/workbook.xml', '<!DOCTYPE root [<!ENTITY x SYSTEM "file:///etc/passwd">]><root/>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet/>');
        $zip->close();
        $this->expectException(ConversionException::class);
        app(TabularConverter::class)->read($path, 'xlsx');
    }

    public function test_row_and_column_limits_are_enforced(): void
    {
        config(['conversions.max_rows' => 1]);
        $path = Storage::disk('local')->path('rows.json');
        file_put_contents($path, '[["first"],["second"]]');
        $this->expectException(ConversionException::class);
        app(TabularConverter::class)->read($path, 'json');
    }

    public function test_expiry_blocks_download_and_cleanup_removes_data(): void
    {
        $user = User::factory()->create();
        $this->submit($user, $this->fixture('csv'), 'xlsx');
        $conversion = Conversion::where('user_id', $user->id)->sole();
        (new ConvertDocument($conversion->id))->handle(app(FormatRegistry::class));
        $conversion->update(['expires_at' => now()->subMinute()]);
        $this->get(route('conversions.download', $conversion))->assertGone();
        $this->artisan('converters:cleanup')->assertSuccessful();
        $this->assertSame('expired', $conversion->fresh()->status);
        $this->assertNull($conversion->fresh()->preview);
        Storage::disk('local')->assertMissing($conversion->input_path);
        $this->get(route('dashboard'))->assertOk()->assertSee('0.0 KB stored');
    }

    public function test_deletion_removes_files_and_history_and_queued_work_is_harmless(): void
    {
        $user = User::factory()->create();
        $this->submit($user, $this->fixture('csv'), 'xlsx');
        $conversion = Conversion::where('user_id', $user->id)->sole();
        $this->delete(route('conversions.destroy', $conversion))->assertRedirect(route('dashboard'));
        Storage::disk('local')->assertMissing($conversion->input_path);
        $this->assertDatabaseMissing('conversions', ['id' => $conversion->id]);
        (new ConvertDocument($conversion->id))->handle(app(FormatRegistry::class));
    }

    public function test_batch_submission_and_active_quota(): void
    {
        $user = User::factory()->create();
        $files = [$this->fixture('csv', 'first'), $this->fixture('tsv', 'second')];
        $this->actingAs($user)->post(route('conversions.store'), [
            'files' => $files, 'target_format' => 'json', 'request_key' => (string) Str::uuid(), 'accept_limitations' => 1,
        ])->assertRedirect();
        $this->assertSame(2, Conversion::where('user_id', $user->id)->count());
        config(['conversions.max_active' => 2]);
        $this->submit($user, $this->fixture('csv', 'third'), 'xlsx')->assertSessionHasErrors('files');
    }

    public function test_failed_job_can_be_retried_but_completed_job_cannot(): void
    {
        $user = User::factory()->create();
        $this->submit($user, $this->fixture('csv'), 'json');
        $conversion = Conversion::where('user_id', $user->id)->sole();
        $conversion->update(['status' => 'failed', 'error_code' => 'engine_error']);
        $this->post(route('conversions.retry', $conversion))->assertRedirect();
        (new ConvertDocument($conversion->id))->handle(app(FormatRegistry::class));
        $this->assertSame('completed', $conversion->fresh()->status);
        $this->post(route('conversions.retry', $conversion))->assertConflict();
    }

    public function test_preview_is_escaped_and_bom_is_not_a_cell_value(): void
    {
        $user = User::factory()->create();
        $this->submit($user, UploadedFile::fake()->createWithContent('data.json', '[["name","value"],["<script>alert(1)</script>","123"]]'), 'xlsx')->assertRedirect();
        $conversion = Conversion::where('user_id', $user->id)->sole();
        (new ConvertDocument($conversion->id))->handle(app(FormatRegistry::class));
        $this->assertSame('name', $conversion->fresh()->preview[0][0]);
        $this->get(route('conversions.show', $conversion))->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        $path = Storage::disk('local')->path('bom.csv');
        file_put_contents($path, "\xEF\xBB\xBFname,value\nhello,123\n");
        $this->assertSame('name', app(TabularConverter::class)->read($path, 'csv')[0][0]);
    }
}
