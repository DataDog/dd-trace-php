<?php

namespace DDTrace\Tests;

use DDTrace\Time;
use PHPUnit\Framework;

final class TimeTest extends Framework\TestCase
{
    public function testNowHasTheExpectedLength()
    {
        $now = Time::now();
        $this->assertEquals(16, strlen((string) $now));
    }

    /**
     * @dataProvider microtimeProvider
     */
    public function testIsValidHasTheExpectedOutput($microtime, $isValid)
    {
        $this->assertEquals($isValid, Time::isValid($microtime));
    }

    public function microtimeProvider()
    {
        return [
            [Time::now(), true],
            [1234567890123456, true],
            [123456789012345, false],
            ['1234567890123456', false],
            ['123456789012345', false],
            ['123456789012345a', false],
            ['abcdefgh12345678', false]
        ];
    }
}
