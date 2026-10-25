<?php

namespace App\Services\Conversions;

class FormatRegistry
{
    public const FORMATS = ['csv', 'tsv', 'json', 'xlsx'];

    public const TABULAR_WARNING = 'Tabular exports contain values, not formatting or formulas. JSON must be an array of arrays of strings. CSV/TSV formula-like values receive an apostrophe prefix, changing those values. XLSX output uses literal string cells.';

    public const WARNING = 'Conversions are best-effort, not perfect replicas. PDF-to-Word reconstructs editable text, not the original layout; PDF-to-PowerPoint uses page images. English OCR can misread text, numbers and table columns. Multi-page images, multiple tables and multiple worksheet text exports return ZIP files rather than discard content. Office formats preserve features where the engine supports them, but fonts, charts and formulas may change. Macros, external links, encrypted files and unsafe or unsupported ZIP entries are rejected. Review every result.';

    public static function warning(): string
    {
        return self::WARNING.' Maximum '.number_format(config('conversions.max_rows')).' rows and '.number_format(config('conversions.max_columns')).' columns.';
    }

    /** @return list<string> */
    public function mimeTypes(string $source): array
    {
        if (in_array($source, ['xlsx', 'docx', 'pptx', 'odt', 'ods', 'odp', 'zip'], true)) {
            return [
                'application/zip', 'application/octet-stream',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'application/vnd.oasis.opendocument.text', 'application/vnd.oasis.opendocument.spreadsheet',
                'application/vnd.oasis.opendocument.presentation',
            ];
        }
        if ($source === 'pdf') {
            return ['application/pdf'];
        }
        if ($source === 'rtf') {
            return ['text/rtf', 'application/rtf', 'text/plain'];
        }
        $images = ['png' => ['image/png'], 'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'],
            'webp' => ['image/webp'], 'bmp' => ['image/bmp', 'image/x-ms-bmp'], 'tiff' => ['image/tiff'], 'gif' => ['image/gif']];
        if (isset($images[$source])) {
            return $images[$source];
        }

        return ['text/plain', 'text/csv', 'text/tab-separated-values', 'application/json', 'text/json', 'application/csv'];
    }

    public function supports(string $source, string $target): bool
    {
        if ($source === $target) {
            return false;
        }
        if (in_array($source, self::FORMATS, true) && in_array($target, self::FORMATS, true)) {
            return true;
        }

        return $this->localReady() && in_array($target, $this->localTargets()[$source] ?? [], true);
    }

    public function localReady(): bool
    {
        return (bool) app(LocalEngineConverter::class)->health()['ready'];
    }

    /** @return array<string, list<string>> */
    public function localTargets(): array
    {
        $groups = json_decode(file_get_contents(base_path('scripts/conversion-capabilities.json')), true, flags: JSON_THROW_ON_ERROR);
        $matrix = array_merge(...array_values($groups));
        $matrix['zip'] = array_values(array_unique(array_merge(self::FORMATS, ...array_values($matrix))));

        return $matrix;
    }

    /** @return list<string> */
    public function sources(): array
    {
        return array_values(array_unique(array_column($this->pairs(), 'source')));
    }

    /** @return list<string> */
    public function targets(): array
    {
        return array_values(array_unique(array_column($this->pairs(), 'target')));
    }

    public function converter(string $source, string $target): Converter
    {
        if (! $this->supports($source, $target)) {
            throw new ConversionException('This conversion has no installed and verified engine.');
        }

        if ($this->localReady() && ($source === 'xlsx' || ! in_array($source, self::FORMATS, true))) {
            return app(LocalEngineConverter::class);
        }

        return app(TabularConverter::class);
    }

    /** @return list<array<string, mixed>> */
    public function pairs(): array
    {
        $pairs = [];
        $local = $this->localReady() ? $this->localTargets() : [];
        foreach (array_unique(array_merge(self::FORMATS, array_keys($local))) as $source) {
            foreach (array_unique(array_merge(in_array($source, self::FORMATS, true) ? self::FORMATS : [], $local[$source] ?? [])) as $target) {
                if ($this->supports($source, $target)) {
                    $pairs[] = [
                        'source' => $source, 'target' => $target,
                        'engine' => isset($local[$source]) ? 'Local LibreOffice / PDF / OCR / image adapters' : 'OpenSpout + bounded UTF-8 adapter',
                        'mime_types' => $this->mimeTypes($source),
                        'ocr_required' => $target === 'ocrpdf' || in_array($source, ['png', 'jpg', 'jpeg', 'webp', 'bmp', 'tiff', 'gif'], true) && $target === 'txt',
                        'potentially_lossy' => true, 'queued' => true,
                        'external_service' => false, 'max_kb' => config('conversions.max_kb'), 'limitations' => self::warning(),
                    ];
                }
            }
        }

        return $pairs;
    }
}
