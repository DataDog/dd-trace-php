<?php

namespace DDTrace\Tests\Unit;

use DDTrace\Obfuscation;

final class ObfuscationTest extends BaseTestCase
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

    /**
     * @dataProvider providerDsnStrings
     */
    public function testDsnStringSecretsWillBeObfuscated($dsn, $expectedDsn)
    {
        $obfuscated = Obfuscation::dsn($dsn);
        $this->assertSame($expectedDsn, $obfuscated);
    }

    public function providerDsnStrings()
    {
        return [
            ['foo://host', 'foo://host'],
            ['foo://host:port', 'foo://host:port'],
            ['foo://host1:port1,host2:port2/db', 'foo://host1:port1,host2:port2/db'],
            ['foo://user:pass@host', 'foo://?:?@host'],
            ['foo://user:pass@host:port', 'foo://?:?@host:port'],
            ['foo://user:pass@host1:port1,host2:port2/db', 'foo://?:?@host1:port1,host2:port2/db'],
        ];
    }
}
