<?php

namespace DDTrace\Tests\Unit\Integrations\PDO;

use DDTrace\Integrations\PDO\PDOIntegration;
use DDTrace\Tag;
use DDTrace\Tests\Common\BaseTestCase;
use ReflectionMethod;

final class PDOIntegrationTest extends BaseTestCase
{
    /**
     * @dataProvider dsnDbNameCases
     */
    public function testParseDsnDbNameQuoteHandling($dsn, $expectedDbName)
    {
        $method = new ReflectionMethod(PDOIntegration::class, 'parseDsn');
        $method->setAccessible(true);
        $tags = $method->invoke(null, $dsn);

        if ($expectedDbName === null) {
            $this->assertArrayNotHasKey(Tag::DB_NAME, $tags);
        } else {
            $this->assertSame($expectedDbName, $tags[Tag::DB_NAME]);
        }
    }

    public function dsnDbNameCases()
    {
        // libpq strips paired wrapping single quotes from key=value pairs, so the tracer must too —
        // otherwise `dbname='milk'` produces `db.name='milk'`, which the agent sanitizer rewrites
        // as `_milk`, splitting the inferred-entity peer.* tuple from other tracers (APMS-19464).
        return [
            'unquoted dbname'                 => ['pgsql:host=h;dbname=milk',   'milk'],
            'paired single quotes stripped'   => ["pgsql:host=h;dbname='milk'", 'milk'],
            'double quotes preserved as-is'   => ['pgsql:host=h;dbname="milk"', '"milk"'],
            'unpaired leading quote preserved'  => ["pgsql:host=h;dbname='milk", "'milk"],
            'unpaired trailing quote preserved' => ["pgsql:host=h;dbname=milk'", "milk'"],
            'empty quoted dbname omitted'     => ["pgsql:host=h;dbname=''",     null],
            'database= alias also stripped'   => ["mysql:host=h;database='foo'", 'foo'],
        ];
    }
}
