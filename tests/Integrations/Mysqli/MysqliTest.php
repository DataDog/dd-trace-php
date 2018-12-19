<?php

namespace DDTrace\Tests\Integrations\Mysqli;

use DDTrace\Integrations\IntegrationsLoader;
use DDTrace\Tests\Common\IntegrationTestCase;
use DDTrace\Tests\Common\SpanAssertion;


final class MysqliTest extends IntegrationTestCase
{
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
        $this->setUpDatabase();
    }

    protected function tearDown()
    {
        $this->clearDatabase();
        parent::tearDown();
    }

    public function testProceduralConnect()
    {
        $traces = $this->isolateTracer(function () {
            $mysqli = \mysqli_connect(self::$host, self::$user, self::$password, self::$db);
            $mysqli->close();
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('mysqli_connect', 'mysqli', 'sql', 'mysqli_connect')
                ->withExactTags(self::baseTags()),
        ]);
    }

    public function testProceduralConnectError()
    {
        $traces = $this->isolateTracer(function () {
            try {
                \mysqli_connect(self::$host, 'wrong');
                $this->fail('should not be possible to connect to wrong host');
            } catch (\Exception $ex) {
            }
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
        $traces = $this->isolateTracer(function () {
            $mysqli = new \mysqli(self::$host, self::$user, self::$password, self::$db);
            $mysqli->close();
        });

        $this->assertSpans($traces, [
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

        $this->assertSpans($traces, [
            SpanAssertion::exists('mysqli_connect'),
            SpanAssertion::build('mysqli_query', 'mysqli', 'sql', 'SELECT * from tests')
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

        $this->assertSpans($traces, [
            SpanAssertion::exists('mysqli.__construct'),
            SpanAssertion::build('mysqli.query', 'mysqli', 'sql', 'SELECT * from tests')
                ->withExactTags(self::baseTags()),
        ]);
    }

    public function testProceduralCommit()
    {
        $traces = $this->isolateTracer(function () {
            $mysqli = \mysqli_connect(self::$host, self::$user, self::$password, self::$db);
            \mysqli_query($mysqli, "INSERT INTO tests (id, name) VALUES (100, 'Tom'");
            \mysqli_commit($mysqli);
            $mysqli->close();
        });

        $this->assertSpans($traces, [
            SpanAssertion::exists('mysqli_connect'),
            SpanAssertion::exists('mysqli_query'),
            SpanAssertion::build('mysqli_commit', 'mysqli', 'sql', "INSERT INTO tests (id, name) VALUES (100, 'Tom'")
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

        $this->assertSpans($traces, [
            SpanAssertion::exists('mysqli.__construct'),
            SpanAssertion::build('mysqli.prepare', 'mysqli', 'sql', 'INSERT INTO tests (id, name) VALUES (?, ?)')
                ->withExactTags(self::baseTags()),
            SpanAssertion::build('mysqli_stmt.execute', 'mysqli', 'sql', 'INSERT INTO tests (id, name) VALUES (?, ?)'),
        ]);
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

        $this->assertSpans($traces, [
            SpanAssertion::exists('mysqli_connect'),
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

    /**
     * @dataProvider fetchScenarios
     */
    public function testConstructorFetchMethod($method, $args, $expected)
    {
        $mysqli = new \mysqli(self::$host, self::$user, self::$password, self::$db);
        $result = $mysqli->query('SELECT * from tests');

        $traces = $this->isolateTracer(function () use (&$fetched, $method, $args, $result) {
            // At the moment we have a bug that we are not able to correctly trace when a traced function is called
            // through call_user_function* functions. This can be removed once we fix this limitation.
            $argsCount = count($args);
            if ($argsCount == 0) {
                $fetched = $result->$method();
            } elseif ($argsCount == 1) {
                $fetched = $result->$method($args[0]);
            } elseif ($argsCount == 2) {
                $fetched = $result->$method($args[0], $args[1]);
            } else {
                $this->fail('You should add here the case for args count: ' . $argsCount);
            }
        });

        $mysqli->close();

        if (is_callable($expected)) {
            $expected($fetched);
        } else {
            $this->assertEquals($expected, $fetched);
        }

        $this->assertSpans(
            $traces,
            [
                SpanAssertion::build('mysqli_result.' . $method, 'mysqli', 'sql', 'SELECT * from tests')
                    ->withExactTags(self::baseTags()),
            ]
        );
    }

    /**
     * @dataProvider fetchScenarios
     */
    public function testConstructorStatementFetchMethod($method, $args, $expected)
    {
        $mysqli = new \mysqli(self::$host, self::$user, self::$password, self::$db);
        $stmt = $mysqli->prepare('SELECT * from tests');
        $stmt->execute();
        $result = $stmt->get_result();

        $traces = $this->isolateTracer(function () use (&$fetched, $method, $args, $result) {
            // At the moment we have a bug that we are not able to correctly trace when a traced function is called
            // through call_user_function* functions. This can be removed once we fix this limitation.
            $argsCount = count($args);
            if ($argsCount == 0) {
                $fetched = $result->$method();
            } elseif ($argsCount == 1) {
                $fetched = $result->$method($args[0]);
            } elseif ($argsCount == 2) {
                $fetched = $result->$method($args[0], $args[1]);
            } else {
                $this->fail('You should add here the case for args count: ' . $argsCount);
            }
        });

        $mysqli->close();

        if (is_callable($expected)) {
            $expected($fetched);
        } else {
            $this->assertEquals($expected, $fetched);
        }

        $this->assertSpans(
            $traces,
            [
                SpanAssertion::build('mysqli_result.' . $method, 'mysqli', 'sql', 'SELECT * from tests')
                ->withExactTags(self::baseTags()),
            ]
        );
    }

    /**
     * @dataProvider fetchScenarios
     */
    public function testProceduralFetchMethod($method, $args, $expected)
    {
        $mysqli = \mysqli_connect(self::$host, self::$user, self::$password, self::$db);
        $result = \mysqli_query($mysqli, 'SELECT * from tests');
        $methodName = 'mysqli_' . $method;
        $fetched = null;

        $traces = $this->isolateTracer(function () use (&$fetched, $methodName, $args, $result) {
            // At the moment we have a bug that we are not able to correctly trace when a traced function is called
            // through call_user_function* functions. This can be removed once we fix this limitation.
            $argsCount = count($args);
            if ($argsCount == 0) {
                $fetched = $methodName($result);
            } elseif ($argsCount == 1) {
                $fetched = $methodName($result, $args[0]);
            } elseif ($argsCount == 2) {
                $fetched = $methodName($result, $args[0], $args[1]);
            } else {
                $this->fail('You should add here the case for args count: ' . $argsCount);
            }
        });

        $mysqli->close();

        if (is_callable($expected)) {
            $expected($fetched);
        } else {
            $this->assertEquals($expected, $fetched);
        }

        $this->assertSpans(
            $traces,
            [
                SpanAssertion::build('mysqli_' . $method, 'mysqli', 'sql', 'SELECT * from tests')
                ->withExactTags(self::baseTags()),
            ]
        );
    }

    /**
     * @dataProvider fetchScenarios
     */
    public function testProceduralStatementFetchMethod($method, $args, $expected)
    {
        $mysqli = \mysqli_connect(self::$host, self::$user, self::$password, self::$db);
        $stmt = \mysqli_prepare($mysqli, 'SELECT * from tests');
        \mysqli_stmt_execute($stmt);
        $result = \mysqli_stmt_get_result($stmt);
        $methodName = 'mysqli_' . $method;
        $fetched = null;

        $traces = $this->isolateTracer(function () use (&$fetched, $methodName, $args, $result) {
            // At the moment we have a bug that we are not able to correctly trace when a traced function is called
            // through call_user_function* functions. This can be removed once we fix this limitation.
            $argsCount = count($args);
            if ($argsCount == 0) {
                $fetched = $methodName($result);
            } elseif ($argsCount == 1) {
                $fetched = $methodName($result, $args[0]);
            } elseif ($argsCount == 2) {
                $fetched = $methodName($result, $args[0], $args[1]);
            } else {
                $this->fail('You should add here the case for args count: ' . $argsCount);
            }
        });

        $mysqli->close();

        if (is_callable($expected)) {
            $expected($fetched);
        } else {
            $this->assertEquals($expected, $fetched);
        }

        $this->assertSpans(
            $traces,
            [
                SpanAssertion::build('mysqli_' . $method, 'mysqli', 'sql', 'SELECT * from tests')
                ->withExactTags(self::baseTags()),
            ]
        );
    }

    public function fetchScenarios()
    {
        return [
            [
                'fetch_all',
                [ MYSQLI_NUM ],
                [ [ 1, 'Tom' ] ],
            ],
            [
                'fetch_array',
                [ MYSQLI_NUM ],
                [ 1, 'Tom' ],
            ],
            [
                'fetch_assoc',
                [],
                [ 'id' => 1, 'name' => 'Tom' ],
            ],
            [
                'fetch_field_direct',
                [ 1 ],
                function ($fetched) {
                    $this->assertTrue(is_object($fetched));
                },
            ],
            [
                'fetch_field',
                [],
                function ($fetched) {
                    $this->assertTrue(is_object($fetched));
                },
            ],
            [
                'fetch_fields',
                [],
                function ($fetched) {
                    $this->assertTrue(is_array($fetched));
                },
            ],
            [
                'fetch_object',
                [],
                function ($fetched) {
                    $this->assertTrue(is_object($fetched));
                },
            ],
            [
                'fetch_row',
                [],
                [1, 'Tom'],
            ],
        ];
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
            $mysqli->query("DROP TABLE tests");
            $mysqli->commit();
            $mysqli->close();
        });
    }
}
