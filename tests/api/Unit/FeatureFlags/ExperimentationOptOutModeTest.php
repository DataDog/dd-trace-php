<?php

namespace DDTrace\Tests\Api\Unit\FeatureFlags;

use DDTrace\FeatureFlags\ExperimentationOptOutMode;
use PHPUnit\Framework\TestCase;

final class ExperimentationOptOutModeTest extends TestCase
{
    /**
     * @dataProvider modeProvider
     */
    public function testExplicitMode($value, $expected)
    {
        $this->assertSame($expected, ExperimentationOptOutMode::resolve($value));
    }

    public static function modeProvider()
    {
        return array(
            array('disabled', 'disabled'),
            array('immediate', 'immediate'),
            array(' IMMEDIATE ', 'immediate'),
            array('', 'disabled'),
        );
    }

    /**
     * @dataProvider invalidModeProvider
     */
    public function testUnsupportedModeThrows($value)
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Experimentation opt-out mode must be disabled or immediate');
        ExperimentationOptOutMode::resolve($value);
    }

    public static function invalidModeProvider()
    {
        return array(array('unknown'), array(true), array(1), array(array()));
    }
}
