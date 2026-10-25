<?php

namespace App\Services\Conversions;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class LocalEngineConverter implements Converter
{
    public static function cleanupWorkspaces(string $directory): void
    {
        $record = $directory.DIRECTORY_SEPARATOR.'engine-workspaces.json';
        if (! is_file($record)) {
            return;
        }
        $paths = json_decode(file_get_contents($record), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($paths)) {
            throw new RuntimeException('Invalid temporary workspace record.');
        }
        foreach ($paths as $path) {
            if (! is_string($path) || ! preg_match('/^oc-(work|profile)-[a-z0-9_]+$/i', basename($path))
                || realpath(dirname($path)) !== realpath(sys_get_temp_dir()) || is_link($path)) {
                throw new RuntimeException('Unsafe temporary workspace record.');
            }
            if (is_dir($path) && ! (new Filesystem)->deleteDirectory($path)) {
                throw new RuntimeException('Could not clean a private conversion workspace.');
            }
        }
        if (! unlink($record)) {
            throw new RuntimeException('Could not remove the temporary workspace record.');
        }
    }

    /** @return array<string, mixed> */
    public function health(): array
    {
        if (! config('conversions.local.enabled')) {
            return ['ready' => false, 'reason' => 'Local engines are disabled by configuration.'];
        }

        return Cache::remember('conversion-local-engine-health-v1', 60, function (): array {
            foreach (['python', 'libreoffice', 'tesseract'] as $tool) {
                if (! is_file(config('conversions.local.'.$tool))) {
                    return ['ready' => false, 'reason' => 'Missing configured '.$tool.' executable.'];
                }
            }
            $process = $this->process(['health' => true]);
            $process->setTimeout(25);
            try {
                $process->run();
            } catch (ProcessTimedOutException) {
                Log::warning('Local engine health probe timed out.');

                return ['ready' => false, 'reason' => 'Local engine health probe timed out.'];
            }
            $data = json_decode($process->getOutput(), true);
            if (! $process->isSuccessful() || ! is_array($data) || ($data['ready'] ?? false) !== true) {
                Log::warning('Local engine health probe failed.');

                return ['ready' => false, 'reason' => 'Local engines failed their runtime health probe. Check installation and language models.'];
            }

            return $data;
        });
    }

    private function process(array $request): Process
    {
        $process = new Process([
            config('conversions.local.python'), '-I', base_path('scripts/convert_document.py'),
        ], base_path(), ['OMP_THREAD_LIMIT' => '1']);
        $process->setInput(json_encode(array_merge(config('conversions.local'), [
            'timeout' => config('conversions.engine_timeout'),
            'max_pages' => config('conversions.max_pages'),
            'max_rows' => config('conversions.max_rows'),
            'max_columns' => config('conversions.max_columns'),
            'max_uncompressed_bytes' => config('conversions.max_uncompressed_bytes'),
        ], $request), JSON_THROW_ON_ERROR));
        $process->setTimeout(config('conversions.engine_timeout') + 10);

        return $process;
    }

    public function convert(string $input, string $source, string $output, string $target): ConversionResult
    {
        if (! ($this->health()['ready'] ?? false)) {
            throw new ConversionException('The required local conversion engines are unavailable. Contact the administrator.');
        }
        $process = $this->process(compact('input', 'source', 'output', 'target'));
        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw new ConversionException('The conversion exceeded its time limit.');
        }
        $data = json_decode($process->getOutput(), true);
        if (! $process->isSuccessful()) {
            if ($process->getExitCode() === 2 && is_string($data['error'] ?? null)) {
                throw new ConversionException($data['error']);
            }
            Log::error('Local conversion process failed.', ['exit_code' => $process->getExitCode(), 'category' => $data['category'] ?? 'process_failure']);
            throw new RuntimeException('Local conversion process failed.');
        }
        $extension = $data['extension'] ?? '';
        if (! is_string($extension) || ! preg_match('/^[a-z0-9]{2,8}$/', $extension)
            || ! is_string($data['file'] ?? null)
            || ! is_file($data['file'])
            || realpath($data['file']) !== realpath(preg_replace('/\.[^.\\\\\/]+$/', '.'.$extension, $output))
            || ! is_array($data['preview'] ?? null) || ! is_array($data['warnings'] ?? null)) {
            throw new RuntimeException('Invalid local engine response.');
        }
        foreach ($data['preview'] as $row) {
            if (! is_array($row) || count($row) > 10 || array_any($row, fn ($cell) => ! is_string($cell))) {
                throw new RuntimeException('Invalid local engine preview.');
            }
        }
        if (count($data['preview']) > 10 || array_any($data['warnings'], fn ($warning) => ! is_string($warning))) {
            throw new RuntimeException('Invalid local engine metadata.');
        }

        return new ConversionResult($data['file'], $extension, $data['preview'], $data['warnings']);
    }
}
