<?php

declare(strict_types=1);

namespace App\Services\Conversions;

use DateTimeInterface;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;
use XMLReader;
use ZipArchive;

class TabularConverter implements Converter
{
    public function convert(string $input, string $source, string $output, string $target): ConversionResult
    {
        $rows = $this->read($input, $source);
        if ($target === 'xlsx') {
            $writer = new Writer;
            $writer->openToFile($output);
            try {
                foreach ($rows as $row) {
                    $writer->addRow(new Row(array_map(fn (string $value) => new StringCell($value, null), $row)));
                }
            } finally {
                $writer->close();
            }
        } elseif ($target === 'json') {
            if (file_put_contents($output, json_encode($rows, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
                throw new ConversionException('Could not write the output.');
            }
        } else {
            $stream = fopen($output, 'wb');
            if ($stream === false) {
                throw new ConversionException('Could not create the output.');
            }
            try {
                foreach ($rows as $row) {
                    $safe = array_map(fn (string $cell) => preg_match('/^[\s]*[=+\-@\t\r]/u', $cell) ? "'".$cell : $cell, $row);
                    if (fputcsv($stream, $safe, $target === 'tsv' ? "\t" : ',', '"', '') === false) {
                        throw new ConversionException('Could not write the output.');
                    }
                }
            } finally {
                fclose($stream);
            }
        }

        // Preview the actual emitted values, including CSV formula neutralization.
        return new ConversionResult($output, $target,
            array_map(fn (array $row) => array_slice($row, 0, 10), array_slice($this->read($output, $target), 0, 10)),
            [FormatRegistry::TABULAR_WARNING]);
    }

    /** @return list<list<string>> */
    public function read(string $path, string $format): array
    {
        if (filesize($path) > config('conversions.max_uncompressed_bytes')) {
            throw new ConversionException('Expanded data exceeds the conversion limit.');
        }
        $rows = [];
        if ($format === 'xlsx') {
            $this->inspectWorkbook($path);
            $reader = new Reader(new Options(SHOULD_PRESERVE_EMPTY_ROWS: true));
            $reader->open($path);
            try {
                foreach ($reader->getSheetIterator() as $sheet) {
                    foreach ($sheet->getRowIterator() as $row) {
                        $values = [];
                        foreach ($row->toArray() as $index => $cell) {
                            if ($index >= config('conversions.max_columns')) {
                                throw new ConversionException('Too many columns.');
                            }
                            while (count($values) < $index) {
                                $values[] = '';
                            }
                            $values[] = $cell instanceof DateTimeInterface ? $cell->format('Y-m-d\TH:i:sP') : $this->textValue($cell);
                        }
                        $this->append($rows, $values);
                    }
                }
            } finally {
                $reader->close();
            }
        } else {
            $content = file_get_contents($path);
            if ($content === false || ! mb_check_encoding($content, 'UTF-8') || str_contains($content, "\0")) {
                throw new ConversionException('Only valid UTF-8 text without binary bytes is accepted.');
            }
            $hasBom = str_starts_with($content, "\xEF\xBB\xBF");
            $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
            if ($format === 'json') {
                try {
                    $data = json_decode($content, false, 64, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    throw new ConversionException('Invalid JSON. Supply an array of arrays of strings.');
                }
                if (! is_array($data) || ! array_is_list($data)) {
                    throw new ConversionException('JSON must be an array of arrays of strings.');
                }
                foreach ($data as $row) {
                    if (! is_array($row) || ! array_is_list($row) || $row === [] || array_any($row, fn ($cell) => ! is_string($cell))) {
                        throw new ConversionException('JSON rows must be nonempty arrays containing only strings; objects, numbers and nested values require an explicit schema.');
                    }
                    $this->append($rows, $row);
                }
            } else {
                $this->validateDelimited($content, $format === 'tsv' ? "\t" : ',');
                $stream = fopen($path, 'rb');
                if ($stream === false) {
                    throw new ConversionException('Could not read the upload.');
                }
                try {
                    if ($hasBom) {
                        fseek($stream, 3);
                    }
                    while (($row = fgetcsv($stream, null, $format === 'tsv' ? "\t" : ',', '"', '')) !== false) {
                        $this->append($rows, array_map(fn ($value) => $value ?? '', $row));
                    }
                } finally {
                    fclose($stream);
                }
            }
        }
        if ($rows === []) {
            throw new ConversionException('The document contains no rows.');
        }

        return $rows;
    }

    private function textValue(mixed $value): string
    {
        if (is_string($value) || is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return '';
        }

        throw new ConversionException('The workbook contains a cell type that cannot be preserved.');
    }

    private function validateDelimited(string $content, string $delimiter): void
    {
        $quoted = false;
        $afterQuote = false;
        $fieldStart = true;
        $length = strlen($content);
        for ($index = 0; $index < $length; $index++) {
            $character = $content[$index];
            if ($quoted) {
                if ($character === '"') {
                    if ($index + 1 < $length && $content[$index + 1] === '"') {
                        $index++;
                    } else {
                        $quoted = false;
                        $afterQuote = true;
                    }
                }

                continue;
            }
            if ($character === $delimiter || $character === "\n" || $character === "\r") {
                $fieldStart = true;
                $afterQuote = false;

                continue;
            }
            if ($afterQuote || ($character === '"' && ! $fieldStart)) {
                throw new ConversionException('Invalid CSV/TSV quoting. Quote complete fields and escape embedded quotes by doubling them.');
            }
            if ($character === '"') {
                $quoted = true;
            }
            $fieldStart = false;
        }
        if ($quoted) {
            throw new ConversionException('Invalid CSV/TSV: an opened quoted field is not closed.');
        }
    }

    private function append(array &$rows, array $row): void
    {
        if (count($rows) >= config('conversions.max_rows') || count($row) > config('conversions.max_columns')) {
            throw new ConversionException('Document exceeds the permitted row or column limit.');
        }
        foreach ($row as $cell) {
            if (strlen($cell) > config('conversions.max_cell_bytes') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $cell)) {
                throw new ConversionException('A cell exceeds the size limit or contains unsupported control characters.');
            }
        }
        $rows[] = $row;
    }

    private function inspectWorkbook(string $path): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new ConversionException('The file is not a valid, unencrypted XLSX workbook.');
        }
        try {
            if ($zip->numFiles > 500 || $zip->locateName('[Content_Types].xml') === false || $zip->locateName('xl/workbook.xml') === false) {
                throw new ConversionException('Invalid or oversized XLSX package.');
            }
            $size = 0;
            $sheets = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->statIndex($i);
                $name = $entry['name'];
                $size += $entry['size'];
                if ($size > config('conversions.max_uncompressed_bytes') || str_contains($name, '..') || str_starts_with($name, '/') || str_contains($name, '\\')) {
                    throw new ConversionException('Unsafe or excessively expanded XLSX package.');
                }
                if (preg_match('~(vbaProject|externalLinks|embeddings|charts|pivotTables|xl/media/)~i', $name)) {
                    throw new ConversionException('Macros, links, charts, images and embedded objects are not supported in this stage.');
                }
                if (preg_match('~^xl/worksheets/sheet[^/]*\.xml$~', $name)) {
                    $sheets++;
                }
                if (str_ends_with($name, '.xml') || str_ends_with($name, '.rels') || str_ends_with($name, '.vml')) {
                    $xml = $zip->getFromIndex($i);
                    if ($xml === false || ! mb_check_encoding($xml, 'UTF-8') || str_contains($xml, "\0") || preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) {
                        throw new ConversionException('Unsafe or unreadable workbook XML.');
                    }
                    $parser = new XMLReader;
                    $previousErrors = libxml_use_internal_errors(true);
                    libxml_clear_errors();
                    try {
                        if (! $parser->XML($xml, null, LIBXML_NONET)) {
                            throw new ConversionException('Invalid workbook XML.');
                        }
                        while ($parser->read()) {
                            if (in_array($parser->nodeType, [XMLReader::DOC_TYPE, XMLReader::ENTITY, XMLReader::ENTITY_REF], true)) {
                                throw new ConversionException('Workbook XML entities are not allowed.');
                            }
                            if ($parser->nodeType !== XMLReader::ELEMENT) {
                                continue;
                            }
                            if (in_array($parser->localName, ['f', 'mergeCell', 'hyperlink', 'comment', 'twoCellAnchor', 'oneCellAnchor', 'absoluteAnchor', 'shape'], true)
                                || ($parser->localName === 'Relationship' && $parser->getAttribute('TargetMode') === 'External')) {
                                throw new ConversionException('Formulas, merged cells and external links cannot be preserved. Export a plain values-only workbook first.');
                            }
                            if ($parser->localName === 'dimension' && preg_match('/([A-Z]+)([0-9]+)$/', $parser->getAttribute('ref') ?? '', $match)) {
                                $column = 0;
                                foreach (str_split($match[1]) as $letter) {
                                    $column = $column * 26 + ord($letter) - 64;
                                }
                                if ($column > config('conversions.max_columns') || (int) $match[2] > config('conversions.max_rows')) {
                                    throw new ConversionException('Workbook dimensions exceed the permitted limits.');
                                }
                            }
                        }
                        if (libxml_get_errors() !== []) {
                            throw new ConversionException('The workbook contains malformed XML.');
                        }
                    } finally {
                        $parser->close();
                        libxml_clear_errors();
                        libxml_use_internal_errors($previousErrors);
                    }
                }
            }
            if ($sheets !== 1) {
                throw new ConversionException('Exactly one worksheet is required; multiple sheets will never be silently discarded.');
            }
        } finally {
            $zip->close();
        }
    }
}
