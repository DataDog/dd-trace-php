<?php

namespace DDTrace\Tests\Unit\Http;

use DDTrace\Http\Headers;
use PHPUnit\Framework;


final class HeadersTest extends Framework\TestCase
{
    public function test_headersMapToColonSeparatedValues_emptyArray()
    {
        $this->assertEmpty(Headers::headersMapToColonSeparatedValues([]));
    }

    public function test_headersMapToColonSeparatedValues_null()
    {
        $this->assertEmpty(Headers::headersMapToColonSeparatedValues(null));
    }

    public function test_headersMapToColonSeparatedValues_values()
    {
        $this->assertSame(
            [
                'key1: value1',
                'key2: value2',
            ],
            Headers::headersMapToColonSeparatedValues([
                'key1' => 'value1',
                'key2' => 'value2',
            ])
        );
    }
}
