<?php

namespace App\Services\Conversions;

readonly class ConversionResult
{
    /** @param list<list<string>> $preview
     * @param  list<string>  $warnings
     */
    public function __construct(
        public string $path,
        public string $extension,
        public array $preview = [],
        public array $warnings = [],
    ) {}
}
