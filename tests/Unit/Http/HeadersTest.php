<?php

namespace DDTrace\Tests\Unit\Http;

use DDTrace\Http\Headers;
use PHPUnit\Framework;


final class HeadersTest extends Framework\TestCase
{
    public function testHeadersMapToColonSeparatedValuesEmptyArray()
    {
        $this->assertEmpty(Headers::headersMapToColonSeparatedValues([]));
    }

    public function testHeadersMapToColonSeparatedValuesValues()
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

    public function testColonSeparatedValuesToHeadersMapEmptyArray()
    {
        $this->assertEmpty(Headers::colonSeparatedValuesToHeadersMap([]));
    }

    public function testColonSeparatedValuesToHeadersMapValues()
    {
        $this->assertSame(
            [
                'key1' => 'value1',
                'key2' => 'value2',
            ],
            Headers::colonSeparatedValuesToHeadersMap([
                'key1: value1',
                'key2: value2',
            ])
        );
    }
}
