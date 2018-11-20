<?php

namespace DDTrace\Tests\Unit;

use PHPUnit\Framework;
use DDTrace\Obfuscation;

final class ObfuscationTest extends Framework\TestCase
{
    public function testAStringWillBeObfuscated()
    {
        $obfuscated = Obfuscation::toObfuscatedString('foo-secret');
        $this->assertSame(Obfuscation::REPLACEMENT, $obfuscated);
    }

    public function testAnArrayWillBeObfuscatedWithDefaultGlue()
    {
        $obfuscated = Obfuscation::toObfuscatedString([
            'foo-secret-one',
            'foo-secret-two',
        ]);
        $this->assertSame(
            Obfuscation::REPLACEMENT . Obfuscation::DEFAULT_GLUE . Obfuscation::REPLACEMENT,
            $obfuscated
        );
    }

    public function testObfuscatedArraysCanHaveACustomGlue()
    {
        $obfuscated = Obfuscation::toObfuscatedString([
            'foo-secret-one',
            'foo-secret-two',
        ], '|');
        $this->assertSame(
            Obfuscation::REPLACEMENT . '|' . Obfuscation::REPLACEMENT,
            $obfuscated
        );
    }
}
