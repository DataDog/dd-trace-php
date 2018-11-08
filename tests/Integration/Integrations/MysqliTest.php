<?php

namespace DDTrace\Tests\Integration\Integrations;

use DDTrace\Integrations\Mysqli as MysqliIntegration;
use DDTrace\Tests\Integration\Common\IntegrationTestCase;
use DDTrace\Tests\Integration\Common\SpanAssertion;


final class MysqliTest extends IntegrationTestCase
{
    private static $host = 'mysql_integration';
    private static $db = 'test';
    private static $port = '3306';
    private static $user = 'test';
    private static $password = 'test';

    public static function setUpBeforeClass()
    {
        MysqliIntegration::load();
    }

    protected function setUp()
    {
        parent::setUp();
        $this->setUpDatabase();
    }

    protected function tearDown()
    {
        $this->clearDatabase();
        parent::tearDown();
    }

    public function testProceduralConnect()
    {
        $traces = $this->isolateTracer(function() {
            \mysqli_connect(self::$host, self::$user, self::$password, self::$db);
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('mysqli_connect', 'mysqli', 'sql', 'mysqli_connect')
                ->withExactTags(self::baseTags()),
        ]);
    }

    public function testProceduralConnectError()
    {
        $traces = $this->isolateTracer(function() {
            try {
                \mysqli_connect(self::$host, 'wrong');
                $this->fail('should not be possible to connect to wrong host');
            } catch (\Exception $ex) {}
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('mysqli_connect', 'mysqli', 'sql', 'mysqli_connect')
                ->setError()
                ->withExistingTagsNames([
                    'error.msg',
                    'error.type',
                    'error.stack',
                ]),
        ]);
    }

    public function testConstructorConnect()
    {
        $traces = $this->isolateTracer(function() {
            new \mysqli(self::$host, self::$user, self::$password, self::$db);
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('mysqli.__construct', 'mysqli', 'sql', 'mysqli.__construct')
                ->withExactTags(self::baseTags()),
        ]);
    }

    public function testProceduralQuery()
    {
        $traces = $this->isolateTracer(function() {
            $mysqli = \mysqli_connect(self::$host, self::$user, self::$password, self::$db);
            \mysqli_query($mysqli, 'SELECT * from tests');
        });

        $this->assertSpans($traces, [
            SpanAssertion::exists('mysqli_connect'),
            SpanAssertion::build('mysqli_query', 'mysqli', 'sql', 'SELECT * from tests')
                ->withExactTags(self::baseTags()),
        ]);
    }

    public function testConstructorQuery()
    {
        $traces = $this->isolateTracer(function() {
            $mysqli = new \mysqli(self::$host, self::$user, self::$password, self::$db);
            $mysqli->query('SELECT * from tests');
        });

        $this->assertSpans($traces, [
            SpanAssertion::exists('mysqli.__construct'),
            SpanAssertion::build('mysqli.query', 'mysqli', 'sql', 'SELECT * from tests')
                ->withExactTags(self::baseTags()),
        ]);
    }

    public function testProceduralCommit()
    {
        $traces = $this->isolateTracer(function() {
            $mysqli = \mysqli_connect(self::$host, self::$user, self::$password, self::$db);
            \mysqli_query($mysqli, "INSERT INTO tests (id, name) VALUES (100, 'Tom'");
            \mysqli_commit($mysqli);
        });

        $this->assertSpans($traces, [
            SpanAssertion::exists('mysqli_connect'),
            SpanAssertion::exists('mysqli_query'),
            SpanAssertion::build('mysqli_commit', 'mysqli', 'sql', 'mysqli_commit')
                ->withExactTags(self::baseTags()),
        ]);
    }

    public function testConstructorPreparedStatement()
    {
        $traces = $this->isolateTracer(function() {
            $mysqli = new \mysqli(self::$host, self::$user, self::$password, self::$db);
            $stmt = $mysqli->prepare("INSERT INTO tests (id, name) VALUES (?, ?)");
            $id = 100;
            $name = 100;
            $stmt->bind_param('is', $id, $name);
            $stmt->execute();
        });

        $this->assertSpans($traces, [
            SpanAssertion::exists('mysqli.__construct'),
            SpanAssertion::build('mysqli.prepare', 'mysqli', 'sql', 'INSERT INTO tests (id, name) VALUES (?, ?)')
                ->withExactTags(self::baseTags()),
            SpanAssertion::build('mysqli_stmt.execute', 'mysqli', 'sql', 'mysqli_stmt.execute'),
        ]);
    }

    public function testProceduralPreparedStatement()
    {
        $traces = $this->isolateTracer(function() {
            $mysqli = \mysqli_connect(self::$host, self::$user, self::$password, self::$db);
            $stmt = \mysqli_prepare($mysqli, "INSERT INTO tests (id, name) VALUES (?, ?)");
            $id = 100;
            $name = 100;
            $stmt->bind_param('is', $id, $name);

            \mysqli_stmt_execute($stmt);
        });

        $this->assertSpans($traces, [
            SpanAssertion::exists('mysqli_connect'),
            SpanAssertion::build('mysqli_prepare', 'mysqli', 'sql', 'INSERT INTO tests (id, name) VALUES (?, ?)')
                ->withExactTags(self::baseTags()),
            SpanAssertion::build('mysqli_stmt_execute', 'mysqli', 'sql', 'mysqli_stmt_execute'),
        ]);
    }

    public function testConstructorConnectError()
    {
        $traces = $this->isolateTracer(function() {
            try {
                new \mysqli(self::$host, 'wrong');
                $this->fail('should not be possible to connect to wrong host');
            } catch (\Exception $ex) {}
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('mysqli.__construct', 'mysqli', 'sql', 'mysqli.__construct')
                ->setError()
                ->withExistingTagsNames([
                    'error.msg',
                    'error.type',
                    'error.stack',
                ]),
        ]);
    }

    private function baseTags()
    {
        return [
            'out.host' => self::$host,
            'out.port' => self::$port,
            'db.type' => 'mysql',
        ];
    }

    private function setUpDatabase()
    {
        $this->isolateTracer(function () {
            $mysqli = new \mysqli(self::$host, self::$user, self::$password, self::$db);
            $mysqli->query("
                CREATE TABLE tests (
                    id integer not null primary key,
                    name varchar(100)
                )
            ");
            $mysqli->query("INSERT INTO tests (id, name) VALUES (1, 'Tom')");
            $mysqli->commit();
        });
    }

    private function clearDatabase()
    {
        $this->isolateTracer(function () {
            $mysqli = new \mysqli(self::$host, self::$user, self::$password, self::$db);
            $mysqli->query("DROP TABLE tests");
            $mysqli->commit();
        });
    }
}
