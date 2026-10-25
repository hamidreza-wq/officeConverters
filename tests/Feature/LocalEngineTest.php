<?php

namespace Tests\Feature;

use App\Jobs\ConvertDocument;
use App\Models\Conversion;
use App\Models\User;
use App\Services\Conversions\ConversionException;
use App\Services\Conversions\FormatRegistry;
use App\Services\Conversions\LocalEngineConverter;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use ZipArchive;

class LocalEngineTest extends TestCase
{
    use DatabaseTransactions;

    private static ?string $fixtures = null;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->assertTrue(app(LocalEngineConverter::class)->health()['ready'], 'Install the documented local engines before running this integration suite.');
        if (self::$fixtures === null) {
            self::$fixtures = storage_path('framework/testing/engine-fixtures-'.Str::uuid());
            $process = new Process([
                config('conversions.local.python'), '-I', base_path('scripts/test_conversion_engines.py'),
                '--fixtures', self::$fixtures,
            ], base_path(), [
                'CONVERSION_LIBREOFFICE' => config('conversions.local.libreoffice'),
                'CONVERSION_TESSERACT' => config('conversions.local.tesseract'),
            ]);
            $process->setTimeout(120);
            $process->mustRun();
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$fixtures !== null) {
            (new Filesystem)->deleteDirectory(self::$fixtures);
            self::$fixtures = null;
        }
        parent::tearDownAfterClass();
    }

    public static function workflows(): array
    {
        return [
            'Word to PDF' => ['docx', 'pdf', 'sample', 'pdf'],
            'PowerPoint to PDF' => ['pptx', 'pdf', 'sample', 'pdf'],
            'PDF to Word' => ['pdf', 'docx', 'sample', 'docx'],
            'PDF tables to Excel' => ['pdf', 'xlsx', 'sample', 'xlsx'],
            'Scanned PDF tables to Excel' => ['pdf', 'xlsx', 'scanned', 'xlsx'],
            'PDF page images' => ['pdf', 'png', 'sample', 'png'],
            'PDF partial-grid statement to CSV' => ['pdf', 'csv', 'partial-grid', 'zip'],
            'Image OCR to searchable PDF' => ['png', 'ocrpdf', 'sample', 'pdf'],
            'Animated image frames' => ['gif', 'png', 'animated', 'zip'],
            'All workbook sheets to CSV' => ['xlsx', 'csv', 'complex', 'zip'],
            'Workbook formatting to ODS' => ['xlsx', 'ods', 'complex', 'ods'],
        ];
    }

    #[DataProvider('workflows')]
    public function test_real_upload_queue_preview_and_download(string $source, string $target, string $fixture, string $extension): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('conversions.store'), [
            'files' => [new UploadedFile(self::$fixtures.DIRECTORY_SEPARATOR.$fixture.'.'.$source, $fixture.'.'.$source, null, null, true)],
            'target_format' => $target, 'request_key' => (string) Str::uuid(), 'accept_limitations' => 1,
        ])->assertRedirect();
        $conversion = Conversion::where('user_id', $user->id)->sole();
        (new ConvertDocument($conversion->id))->handle(app(FormatRegistry::class));
        $conversion->refresh();
        $this->assertSame('completed', $conversion->status, $conversion->error_message ?? '');
        $this->assertSame($extension, $conversion->resultExtension());
        $this->assertNotEmpty($conversion->warnings);
        Storage::disk('local')->assertMissing('conversions/'.$conversion->id.'/engine-workspaces.json');
        $this->get(route('conversions.show', $conversion))->assertOk()->assertSee('Review before using your result:');
        $this->get(route('conversions.download', $conversion))->assertOk()->assertDownload($fixture.'.'.$extension);
        $content = $this->get(route('conversions.download', $conversion))->streamedContent();
        $this->assertGreaterThan(20, strlen($content));
        if ($extension === 'pdf') {
            $this->assertStringStartsWith('%PDF-', $content);
        }
        if ($source === 'pdf' && $target === 'xlsx') {
            $this->assertTrue(array_any($conversion->preview, fn (array $row) => in_array('Sample', $row, true)));
        }
        if ($fixture === 'partial-grid') {
            $this->assertSame(['Date', 'Description', 'Type', 'Money In', 'Money Out', 'Balance'], $conversion->preview[0]);
            $this->assertSame(['', '12.50', '987.50'], array_slice($conversion->preview[1], 3));
            $zip = new ZipArchive;
            $this->assertTrue($zip->open(Storage::disk('local')->path($conversion->output_path)));
            $this->assertSame(2, $zip->numFiles);
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $csv = $zip->getFromIndex($index);
                $this->assertStringContainsString('Sample payment', $csv);
                $this->assertStringContainsString('Sample credit', $csv);
                $this->assertStringNotContainsString('hidden label', $csv);
                $this->assertStringNotContainsString('Footer', $csv);
            }
            $zip->close();
        }
        if ($fixture === 'complex' && $target === 'csv') {
            $zip = new ZipArchive;
            $this->assertTrue($zip->open(Storage::disk('local')->path($conversion->output_path)));
            $this->assertSame(2, $zip->numFiles);
            $values = '';
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $values .= $zip->getFromIndex($index);
            }
            $zip->close();
            $this->assertStringContainsString('Total,15', $values);
            $this->assertStringContainsString('Cost,2', $values);
            $response = $this->post(route('conversions.batch'), ['conversion_ids' => [$conversion->id]])->assertDownload('converted-documents.zip');
            $path = $response->baseResponse->getFile()->getPathname();
            $zip->open($path);
            $this->assertStringEndsWith('.zip', $zip->getNameIndex(0));
            $zip->close();
            unlink($path);
        }
    }

    public function test_zip_batches_convert_all_entries_and_download_as_zip(): void
    {
        $path = Storage::disk('local')->path('documents.zip');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFile(self::$fixtures.DIRECTORY_SEPARATOR.'sample.docx', 'report.docx');
        $zip->addFile(self::$fixtures.DIRECTORY_SEPARATOR.'sample.png', 'scan.png');
        $zip->close();
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('conversions.store'), [
            'files' => [new UploadedFile($path, 'documents.zip', null, null, true)],
            'target_format' => 'pdf', 'request_key' => (string) Str::uuid(), 'accept_limitations' => 1,
        ])->assertRedirect();
        $conversion = Conversion::where('user_id', $user->id)->sole();
        (new ConvertDocument($conversion->id))->handle(app(FormatRegistry::class));
        $conversion->refresh();
        $this->assertSame('completed', $conversion->status, $conversion->error_message ?? '');
        $this->assertSame('zip', $conversion->resultExtension());
        $this->get(route('conversions.download', $conversion))->assertDownload('documents.zip');
        $this->assertTrue($zip->open(Storage::disk('local')->path($conversion->output_path)));
        $this->assertSame(3, $zip->numFiles);
        $this->assertSame(2, count(array_filter(iterator_to_array((function () use ($zip) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                yield $zip->getNameIndex($i);
            }
        })()), fn (string $name) => str_ends_with($name, '.pdf'))));
        $zip->close();
    }

    public function test_unsafe_zip_fails_without_extracting_or_skipping_entries(): void
    {
        $path = Storage::disk('local')->path('unsafe.zip');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('../escape.docx', 'unsafe');
        $zip->close();
        $output = Storage::disk('local')->path('result.pdf');
        $this->expectException(ConversionException::class);
        app(LocalEngineConverter::class)->convert($path, 'zip', $output, 'pdf');
    }

    public function test_registry_hides_missing_engines_and_ui_has_compatible_targets(): void
    {
        $this->get(route('tools'))->assertOk()->assertSee('PDF &amp; OCR', false)->assertDontSee('NOT AVAILABLE');
        $this->actingAs(User::factory()->create())->get(route('dashboard', ['target' => 'pdf']))
            ->assertOk()->assertSee('data-capabilities=', false)->assertSee('.docx')->assertSee('Searchable PDF (English OCR)');
        config(['conversions.local.enabled' => false]);
        $registry = app(FormatRegistry::class);
        $this->assertFalse($registry->supports('pdf', 'docx'));
        $this->assertTrue($registry->supports('csv', 'xlsx'));
        $this->assertCount(12, $registry->pairs());
        $this->get(route('tools'))->assertOk()->assertSee('engines are unavailable');
    }

    public function test_timeout_fails_explicitly_and_removes_private_workspaces(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('conversions.store'), [
            'files' => [new UploadedFile(self::$fixtures.DIRECTORY_SEPARATOR.'sample.docx', 'sample.docx', null, null, true)],
            'target_format' => 'pdf', 'request_key' => (string) Str::uuid(), 'accept_limitations' => 1,
        ])->assertRedirect();
        $conversion = Conversion::where('user_id', $user->id)->sole();
        config(['conversions.engine_timeout' => 0]);
        (new ConvertDocument($conversion->id))->handle(app(FormatRegistry::class));
        $this->assertSame('failed', $conversion->fresh()->status);
        $this->assertStringContainsString('time limit', $conversion->fresh()->error_message);
        Storage::disk('local')->assertMissing('conversions/'.$conversion->id.'/engine-workspaces.json');
    }

    public function test_temporary_cleanup_rejects_paths_outside_its_own_workspace_prefix(): void
    {
        $directory = Storage::disk('local')->path('test-cleanup');
        Storage::disk('local')->makeDirectory('test-cleanup');
        file_put_contents($directory.DIRECTORY_SEPARATOR.'engine-workspaces.json', json_encode([sys_get_temp_dir()], JSON_THROW_ON_ERROR));
        $this->expectException(\RuntimeException::class);
        LocalEngineConverter::cleanupWorkspaces($directory);
    }
}
