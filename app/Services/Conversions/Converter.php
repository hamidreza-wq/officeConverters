<?php

namespace App\Services\Conversions;

interface Converter
{
    public function convert(string $input, string $source, string $output, string $target): ConversionResult;
}
