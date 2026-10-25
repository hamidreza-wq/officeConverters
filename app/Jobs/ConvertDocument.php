<?php

namespace App\Jobs;

use App\Models\Conversion;
use App\Services\Conversions\ConversionException;
use App\Services\Conversions\FormatRegistry;
use App\Services\Conversions\LocalEngineConverter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ConvertDocument implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 150;

    public bool $failOnTimeout = true;

    public function __construct(public string $conversionId) {}

    public function backoff(): array
    {
        return [10, 30, 90];
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->conversionId))->releaseAfter(5)->expireAfter(240)];
    }

    public function handle(FormatRegistry $registry): void
    {
        $conversion = DB::transaction(function (): ?Conversion {
            $record = Conversion::lockForUpdate()->find($this->conversionId);
            if (! $record || in_array($record->status, ['completed', 'failed', 'expired'], true) || $record->expires_at->isPast()) {
                return null;
            }
            $record->update([
                'status' => 'processing', 'started_at' => now(), 'attempts' => $record->attempts + 1,
                'error_code' => null, 'error_message' => null,
            ]);

            return $record;
        });
        if (! $conversion) {
            return;
        }
        $disk = Storage::disk('local');
        $output = 'conversions/'.$conversion->id.'/result.'.$conversion->target_format;
        try {
            $result = $registry->converter($conversion->source_format, $conversion->target_format)->convert(
                $disk->path($conversion->input_path), $conversion->source_format,
                $disk->path($output), $conversion->target_format
            );
            $output = 'conversions/'.$conversion->id.'/'.basename($result->path);
            if (! $disk->exists($output) || $disk->size($output) === 0) {
                throw new RuntimeException('Converter produced no output.');
            }
            $conversion->update([
                'status' => 'completed', 'output_path' => $output, 'output_bytes' => $disk->size($output),
                'preview' => $result->preview, 'output_extension' => $result->extension,
                'warnings' => $result->warnings, 'completed_at' => now(),
            ]);
        } catch (ConversionException $error) {
            $disk->delete($output);
            $conversion->update([
                'status' => 'failed', 'error_code' => 'invalid_document', 'error_message' => $error->getMessage(),
            ]);
            Log::notice('Document rejected by converter.', ['conversion_id' => $conversion->id]);
        } catch (Throwable $error) {
            $disk->delete($output);
            $conversion->update([
                'status' => 'queued', 'error_code' => 'engine_error',
                'error_message' => 'The engine could not complete this attempt. Automatic retry is pending.',
            ]);
            Log::error('Conversion engine failed.', ['conversion_id' => $conversion->id, 'exception_type' => $error::class]);
            throw new RuntimeException('Conversion engine failed; see the conversion ID in the application log.');
        } finally {
            LocalEngineConverter::cleanupWorkspaces($disk->path('conversions/'.$conversion->id));
        }
    }

    public function failed(?Throwable $exception): void
    {
        Conversion::whereKey($this->conversionId)->whereIn('status', ['queued', 'processing'])->update([
            'status' => 'failed', 'error_code' => 'engine_error',
            'error_message' => 'Conversion failed after retries or exceeded worker limits. Retry or contact the administrator.',
        ]);
    }
}
