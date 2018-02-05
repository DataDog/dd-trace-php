<?php

namespace DDTrace\Tests;

use DDTrace\MicroTime;

final class MicroTimeTest extends \PHPUnit_Framework_TestCase
{
    public function testNowHasTheExpectedLength()
    {
        $now = MicroTime\now();
        $this->assertEquals(16, strlen((string) $now));
    }

    /**
     * @dataProvider microtimeProvider
     */
    public function testIsValidHasTheExpectedOutput($microtime, $isValid)
    {
        $this->assertEquals($isValid, MicroTime\isValid($microtime));
    }

    public function microtimeProvider()
    {
        return [
            [MicroTime\now(), true],
            [1234567890123456, true],
            [123456789012345, false],
            ['1234567890123456', false],
            ['123456789012345', false],
            ['123456789012345a', false],
            ['abcdefgh12345678', false]
        ];
    }
}
