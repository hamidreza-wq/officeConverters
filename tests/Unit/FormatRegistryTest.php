<?php

namespace Tests\Unit;

use App\Services\Conversions\ConversionException;
use App\Services\Conversions\FormatRegistry;
use PHPUnit\Framework\TestCase;

class FormatRegistryTest extends TestCase
{
    public function test_only_implemented_pairs_are_exposed(): void
    {
        $registry = new FormatRegistry;
        $this->assertTrue($registry->supports('csv', 'xlsx'));
        $this->assertFalse($registry->supports('csv', 'csv'));
        $this->expectException(ConversionException::class);
        $registry->converter('csv', 'csv');
    }
}
