<?php

namespace DDTrace\Tests\Integrations\Mysqli;

use DDTrace\Integrations\IntegrationsLoader;
use DDTrace\Tests\Common\IntegrationTestCase;
use DDTrace\Tests\Common\SpanAssertion;

class MysqliTest extends IntegrationTestCase
{
    const IS_SANDBOX = false;

    private static $host = 'mysql_integration';
    private static $db = 'test';
    private static $port = '3306';
    private static $user = 'test';
    private static $password = 'test';

    public static function setUpBeforeClass()
    {
        parent::setUpBeforeClass();
        IntegrationsLoader::load();
    }

    protected function setUp()
    {
        parent::setUp();
        $this->clearDatabase();
        $this->setUpDatabase();
    }

    public function testProceduralConnect()
    {
        $traces = $this->isolateTracer(function () {
            $mysqli = \mysqli_connect(self::$host, self::$user, self::$password, self::$db);
            $mysqli->close();
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build('mysqli_connect', 'mysqli', 'sql', 'mysqli_connect')
                ->withExactTags(self::baseTags()),
        ]);
    }

    public function testProceduralConnectErrorThrowsException()
    {
        $traces = $this->isolateTracer(function () {
            try {
                $mysqli = \mysqli_connect(self::$host, 'wrong');
                $this->fail('should not be possible to connect to wrong host');
            } catch (\Exception $ex) {
            }
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build('mysqli_connect', 'mysqli', 'sql', 'mysqli_connect')
                ->setError()
                ->withExactTags(self::baseTags())
                ->withExistingTagsNames([
                    'error.msg',
                    'error.type',
                    'error.stack',
                ]),
        ]);
    }

    public function testProceduralConnectDontReportError()
    {
        $this->disableTranslateWarningsIntoErrors();

        $traces = $this->isolateTracer(function () {
            $mysqli = \mysqli_connect(self::$host, 'wrong');
            $this->assertFalse($mysqli);
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build('mysqli_connect', 'mysqli', 'sql', 'mysqli_connect')
                ->setError()
                ->withExactTags(self::baseTags())
                ->withExistingTagsNames([
                    'error.msg',
                    'error.type',
                    'error.stack',
                ]),
        ]);
    }

    public function testConstructorConnect()
    {
        $traces = $this->isolateTracer(function () {
            $mysqli = new \mysqli(self::$host, self::$user, self::$password, self::$db);
            $mysqli->close();
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build('mysqli.__construct', 'mysqli', 'sql', 'mysqli.__construct')
                ->withExactTags(self::baseTags()),
        ]);
    }

    public function testProceduralQuery()
    {
        $traces = $this->isolateTracer(function () {
            $mysqli = \mysqli_connect(self::$host, self::$user, self::$password, self::$db);
            \mysqli_query($mysqli, 'SELECT * from tests');
            $mysqli->close();
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('mysqli_connect', 'mysqli_connect'),
            SpanAssertion::build('mysqli_query', 'mysqli', 'sql', 'SELECT * from tests')
                ->setTraceAnalyticsCandidate()
                ->withExactTags(self::baseTags()),
        ]);
    }

    public function testProceduralQueryRealConnect()
    {
        $traces = $this->isolateTracer(function () {
            $mysqli = \mysqli_init();
            \mysqli_real_connect($mysqli, self::$host, self::$user, self::$password, self::$db);
            \mysqli_query($mysqli, 'SELECT * from tests');
            $mysqli->close();
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build('mysqli_real_connect', 'mysqli', 'sql', 'mysqli_real_connect')
                ->setTraceAnalyticsCandidate()
                ->withExactTags(self::baseTags()),
            SpanAssertion::build('mysqli_query', 'mysqli', 'sql', 'SELECT * from tests')
                ->setTraceAnalyticsCandidate()
                ->withExactTags(self::baseTags()),
        ]);
    }

    public function testConstructorQuery()
    {
        $traces = $this->isolateTracer(function () {
            $mysqli = new \mysqli(self::$host, self::$user, self::$password, self::$db);
            $mysqli->query('SELECT * from tests');
            $mysqli->close();
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('mysqli.__construct', 'mysqli.__construct'),
            SpanAssertion::build('mysqli.query', 'mysqli', 'sql', 'SELECT * from tests')
                ->setTraceAnalyticsCandidate()
                ->withExactTags(self::baseTags()),
        ]);
    }

    public function testEmptyConstructorQuery()
    {
        $traces = $this->isolateTracer(function () {
            $mysqli = new \mysqli();
            $mysqli->real_connect(self::$host, self::$user, self::$password, self::$db);
            $mysqli->query('SELECT * from tests');
            $mysqli->close();
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('mysqli.__construct', 'mysqli.__construct'),
            SpanAssertion::build('mysqli.real_connect', 'mysqli', 'sql', 'mysqli.real_connect')
                ->setTraceAnalyticsCandidate()
                ->withExactTags(self::baseTags()),
            SpanAssertion::build('mysqli.query', 'mysqli', 'sql', 'SELECT * from tests')
                ->setTraceAnalyticsCandidate()
                ->withExactTags(self::baseTags()),
        ]);
    }

    public function testProceduralCommit()
    {
        $query = "INSERT INTO tests (id, name) VALUES (100, 'Tom')";
        $traces = $this->isolateTracer(function () use ($query) {
            $mysqli = \mysqli_connect(self::$host, self::$user, self::$password, self::$db);
            \mysqli_query($mysqli, $query);
            \mysqli_commit($mysqli);
            $mysqli->close();
        });

        $this->assertOneRowInDatabase('tests', [ 'id' => 100 ]);
        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('mysqli_connect', 'mysqli_connect'),
            SpanAssertion::exists('mysqli_query', $query),
            SpanAssertion::build('mysqli_commit', 'mysqli', 'sql', $query)
                ->withExactTags(self::baseTags()),
        ]);
    }

    public function testConstructorPreparedStatement()
    {
        $traces = $this->isolateTracer(function () {
            $mysqli = new \mysqli(self::$host, self::$user, self::$password, self::$db);
            $stmt = $mysqli->prepare("INSERT INTO tests (id, name) VALUES (?, ?)");
            $id = 100;
            $name = 100;
            $stmt->bind_param('is', $id, $name);
            $stmt->execute();
            $mysqli->close();
        });

        $this->assertOneRowInDatabase('tests', [ 'id' => 100 ]);
        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('mysqli.__construct', 'mysqli.__construct'),
            SpanAssertion::build('mysqli.prepare', 'mysqli', 'sql', 'INSERT INTO tests (id, name) VALUES (?, ?)')
                ->withExactTags(self::baseTags()),
            SpanAssertion::build('mysqli_stmt.execute', 'mysqli', 'sql', 'INSERT INTO tests (id, name) VALUES (?, ?)')
                ->setTraceAnalyticsCandidate(),
        ]);
    }

    public function testLimitedTracerConstructorQuery()
    {
        $traces = $this->isolateLimitedTracer(function () {
            $mysqli = new \mysqli(self::$host, self::$user, self::$password, self::$db);
            $mysqli->query('SELECT * from tests');
            $mysqli->close();
        });

        $this->assertEmpty($traces);
    }

    public function testLimitedTracerProceduralCommit()
    {
        $traces = $this->isolateLimitedTracer(function () {
            $mysqli = \mysqli_connect(self::$host, self::$user, self::$password, self::$db);
            \mysqli_query($mysqli, "INSERT INTO tests (id, name) VALUES (100, 'From Test')");
            \mysqli_commit($mysqli);
            $mysqli->close();
        });

        $this->assertOneRowInDatabase('tests', [ 'id' => 100 ]);
        $this->assertEmpty($traces);
    }

    public function testLimitedTracerConstructorPreparedStatement()
    {
        $traces = $this->isolateLimitedTracer(function () {
            $mysqli = new \mysqli(self::$host, self::$user, self::$password, self::$db);
            $stmt = $mysqli->prepare("INSERT INTO tests (id, name) VALUES (?, ?)");
            $id = 100;
            $name = 100;
            $stmt->bind_param('is', $id, $name);
            $stmt->execute();
            $mysqli->close();
        });

        $this->assertOneRowInDatabase('tests', [ 'id' => 100 ]);
        $this->assertEmpty($traces);
    }

    public function testProceduralPreparedStatement()
    {
        $traces = $this->isolateTracer(function () {
            $mysqli = \mysqli_connect(self::$host, self::$user, self::$password, self::$db);
            $stmt = \mysqli_prepare($mysqli, "INSERT INTO tests (id, name) VALUES (?, ?)");
            $id = 100;
            $name = 100;
            $stmt->bind_param('is', $id, $name);

            \mysqli_stmt_execute($stmt);
            $mysqli->close();
        });

        $this->assertOneRowInDatabase('tests', [ 'id' => 100 ]);
        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('mysqli_connect', 'mysqli_connect'),
            SpanAssertion::build('mysqli_prepare', 'mysqli', 'sql', 'INSERT INTO tests (id, name) VALUES (?, ?)')
                ->withExactTags(self::baseTags()),
            SpanAssertion::build('mysqli_stmt_execute', 'mysqli', 'sql', 'INSERT INTO tests (id, name) VALUES (?, ?)'),
        ]);
    }

    public function testConstructorConnectError()
    {
        $traces = $this->isolateTracer(function () {
            try {
                new \mysqli(self::$host, 'wrong');
                $this->fail('should not be possible to connect to wrong host');
            } catch (\Exception $ex) {
            }
        });

        $this->assertFlameGraph($traces, [
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
            $mysqli->close();
        });
    }

    private function clearDatabase()
    {
        $this->isolateTracer(function () {
            $mysqli = new \mysqli(self::$host, self::$user, self::$password, self::$db);
            $mysqli->query("DROP TABLE IF EXISTS tests");
            $mysqli->commit();
            $mysqli->close();
        });
    }

    private function assertOneRowInDatabase($table, $wheres)
    {
        $results = $this->queryDatabaseAllAssociative($table, $wheres);
        $this->assertCount(1, $results);
    }

    private function queryDatabaseAllAssociative($table, $wheres)
    {
        $conditions = [];
        foreach ($wheres as $key => $value) {
            $conditions[] = "$key = '$value'";
        }
        $inlineWhere = $conditions ? 'WHERE ' . implode('AND', $conditions) : '';
        $mysqli = new \mysqli(self::$host, self::$user, self::$password, self::$db);
        $result = $mysqli->query("SELECT * FROM $table $inlineWhere");
        if (false === $result) {
            $message = mysqli_error($mysqli);
            $this->fail("Error while checking database rows: $message");
        }
        return $result->fetch_all(MYSQLI_ASSOC);
    }
}
