<?php

namespace DDTrace\Tests\Integrations\Mysqli;

use DDTrace\Tag;
use DDTrace\Tests\Common\IntegrationTestCase;
use DDTrace\Tests\Common\SpanAssertion;

class MysqliTest extends IntegrationTestCase
{
    private static $host = 'mysql_integration';
    public static $database = 'mysqlitest';
    private static $port = '3306';
    private static $user = 'test';
    private static $password = 'test';

    public static function ddSetUpBeforeClass()
    {
        parent::ddSetUpBeforeClass();
    }

    protected function ddSetUp()
    {
        parent::ddSetUp();
        $this->clearDatabase();
        $this->setUpDatabase();
    }

    protected function envsToCleanUpAtTearDown()
    {
        return [
            'DD_TRACE_PEER_SERVICE_DEFAULTS_ENABLED',
            'DD_TRACE_REMOVE_INTEGRATION_SERVICE_NAMES_ENABLED',
            'DD_SERVICE',
            'DD_SERVICE_MAPPING',
        ];
    }

    public function testProceduralConnect()
    {
        $traces = $this->isolateTracer(function () {
            $mysqli = \mysqli_connect(self::$host, self::$user, self::$password, self::$database);
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
                \mysqli_connect(self::$host, 'wrong');
                $this->fail('should not be possible to connect to wrong host');
            } catch (\Exception $ex) {
            }
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build('mysqli_connect', 'mysqli', 'sql', 'mysqli_connect')
                ->setError()
                ->withExactTags(self::baseTags(false))
                ->withExistingTagsNames([
                    Tag::ERROR_MSG,
                    'error.type',
                    'error.stack',
                ]),
        ]);
    }

    public function testProceduralConnectDontReportError()
    {
        $this->disableTranslateWarningsIntoErrors();

        $traces = $this->isolateTracer(function () {
            \mysqli_report(MYSQLI_REPORT_OFF); // exceptions are default since PHP 8.1+
            $mysqli = \mysqli_connect(self::$host, 'wrong');
            $this->assertFalse($mysqli);
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build('mysqli_connect', 'mysqli', 'sql', 'mysqli_connect')
                ->setError()
                ->withExactTags(self::baseTags(false))
                ->withExistingTagsNames([
                    Tag::ERROR_MSG,
                    'error.type',
                    'error.stack',
                ]),
        ]);
    }

    public function testConstructorConnect()
    {
        $traces = $this->isolateTracer(function () {
            $mysqli = new \mysqli(self::$host, self::$user, self::$password, self::$database);
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
            $mysqli = \mysqli_connect(self::$host, self::$user, self::$password, self::$database);
            \mysqli_query($mysqli, 'SELECT * from tests');
            $mysqli->close();
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('mysqli_connect', 'mysqli_connect'),
            SpanAssertion::build('mysqli_query', 'mysqli', 'sql', 'SELECT * from tests')
                ->withExactTags(self::baseTags(true, false))
                ->withExactMetrics([
                    Tag::DB_ROW_COUNT => 1,
                    '_dd.agent_psr' => 1.0,
                    '_sampling_priority_v1' => 1.0,
                ]),
        ]);
    }

    public function testProceduralQueryPeerServiceEnabled()
    {
        $this->putEnvAndReloadConfig(['DD_TRACE_PEER_SERVICE_DEFAULTS_ENABLED=true']);
        $traces = $this->isolateTracer(function () {
            $mysqli = \mysqli_connect(self::$host, self::$user, self::$password, self::$database);
            \mysqli_query($mysqli, 'SELECT * from tests');
            $mysqli->close();
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('mysqli_connect', 'mysqli_connect'),
            SpanAssertion::build('mysqli_query', 'mysqli', 'sql', 'SELECT * from tests')
                ->withExactTags(self::baseTags(true, true))
                ->withExactMetrics([
                    Tag::DB_ROW_COUNT => 1,
                    '_dd.agent_psr' => 1.0,
                    '_sampling_priority_v1' => 1.0,
                ]),
        ]);
    }

    public function testProceduralExecuteQuery()
    {
        if (PHP_VERSION_ID < 80200) {
            $this->markTestSkipped("mysqli_execute_query is a new function introduced in PHP 8.2");
        }

        $traces = $this->isolateTracer(function () {
            $mysqli = \mysqli_connect(self::$host, self::$user, self::$password, self::$database);
            \mysqli_execute_query($mysqli, 'SELECT * from tests WHERE 1 = ?', [1]);
            $mysqli->close();
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('mysqli_connect', 'mysqli_connect'),
            SpanAssertion::build('mysqli_execute_query', 'mysqli', 'sql', 'SELECT * from tests WHERE 1 = ?')
                ->withExactTags(self::baseTags()),
        ]);
    }

    public function testProceduralExecuteQueryPeerServiceEnabled()
    {
        if (PHP_VERSION_ID < 80200) {
            $this->markTestSkipped("mysqli_execute_query is a new function introduced in PHP 8.2");
        }

        $this->putEnvAndReloadConfig(['DD_TRACE_PEER_SERVICE_DEFAULTS_ENABLED=true']);

        $traces = $this->isolateTracer(function () {
            $mysqli = \mysqli_connect(self::$host, self::$user, self::$password, self::$database);
            \mysqli_execute_query($mysqli, 'SELECT * from tests WHERE 1 = ?', [1]);
            $mysqli->close();
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('mysqli_connect', 'mysqli_connect'),
            SpanAssertion::build('mysqli_execute_query', 'mysqli', 'sql', 'SELECT * from tests WHERE 1 = ?')
                ->withExactTags(self::baseTags(true, true)),
        ]);
    }

    public function testProceduralQueryRealConnect()
    {
        $traces = $this->isolateTracer(function () {
            $mysqli = \mysqli_init();
            \mysqli_real_connect($mysqli, self::$host, self::$user, self::$password, self::$database);
            \mysqli_query($mysqli, 'SELECT * from tests');
            $mysqli->close();
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build('mysqli_real_connect', 'mysqli', 'sql', 'mysqli_real_connect')
                ->withExactTags(self::baseTags()),
            SpanAssertion::build('mysqli_query', 'mysqli', 'sql', 'SELECT * from tests')
                ->withExactTags(self::baseTags())
                ->withExactMetrics([
                    Tag::DB_ROW_COUNT => 1,
                    '_dd.agent_psr' => 1.0,
                    '_sampling_priority_v1' => 1.0,
                ]),
        ]);
    }

    public function testProceduralQueryRealConnectPeerServiceEnabled()
    {
        $this->putEnvAndReloadConfig(['DD_TRACE_PEER_SERVICE_DEFAULTS_ENABLED=true']);

        $traces = $this->isolateTracer(function () {
            $mysqli = \mysqli_init();
            \mysqli_real_connect($mysqli, self::$host, self::$user, self::$password, self::$database);
            \mysqli_query($mysqli, 'SELECT * from tests');
            $mysqli->close();
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build('mysqli_real_connect', 'mysqli', 'sql', 'mysqli_real_connect')
                ->withExactTags(self::baseTags()),
            SpanAssertion::build('mysqli_query', 'mysqli', 'sql', 'SELECT * from tests')
                ->withExactTags(self::baseTags(true, true))
                ->withExactMetrics([
                    Tag::DB_ROW_COUNT => 1,
                    '_dd.agent_psr' => 1.0,
                    '_sampling_priority_v1' => 1.0,
                ]),
        ]);
    }

    public function testConstructorQuery()
    {
        $traces = $this->isolateTracer(function () {
            $mysqli = new \mysqli(self::$host, self::$user, self::$password, self::$database);
            $mysqli->query('SELECT * from tests');
            $mysqli->close();
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('mysqli.__construct', 'mysqli.__construct'),
            SpanAssertion::build('mysqli.query', 'mysqli', 'sql', 'SELECT * from tests')
                ->withExactTags(self::baseTags())
                ->withExactMetrics([
                    Tag::DB_ROW_COUNT => 1,
                    '_dd.agent_psr' => 1.0,
                    '_sampling_priority_v1' => 1.0,
                ]),
        ]);
    }

    public function testConstructorQueryPeerServiceEnabled()
    {
        $this->putEnvAndReloadConfig(['DD_TRACE_PEER_SERVICE_DEFAULTS_ENABLED=true']);

        $traces = $this->isolateTracer(function () {
            $mysqli = new \mysqli(self::$host, self::$user, self::$password, self::$database);
            $mysqli->query('SELECT * from tests');
            $mysqli->close();
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('mysqli.__construct', 'mysqli.__construct'),
            SpanAssertion::build('mysqli.query', 'mysqli', 'sql', 'SELECT * from tests')
                ->withExactTags(self::baseTags(true, true))
                ->withExactMetrics([
                    Tag::DB_ROW_COUNT => 1,
                    '_dd.agent_psr' => 1.0,
                    '_sampling_priority_v1' => 1.0,
                ]),
        ]);
    }

    public function testEmptyConstructorQuery()
    {
        $traces = $this->isolateTracer(function () {
            $mysqli = new \mysqli();
            $mysqli->real_connect(self::$host, self::$user, self::$password, self::$database);
            $mysqli->query('SELECT * from tests');
            $mysqli->close();
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('mysqli.__construct', 'mysqli.__construct'),
            SpanAssertion::build('mysqli.real_connect', 'mysqli', 'sql', 'mysqli.real_connect')
                ->withExactTags(self::baseTags()),
            SpanAssertion::build('mysqli.query', 'mysqli', 'sql', 'SELECT * from tests')
                ->withExactTags(self::baseTags())
                ->withExactMetrics([
                    Tag::DB_ROW_COUNT => 1,
                    '_dd.agent_psr' => 1.0,
                    '_sampling_priority_v1' => 1.0,
                ]),
        ]);
    }

    public function testEmptyConstructorQueryPeerServiceEnabled()
    {
        $this->putEnvAndReloadConfig(['DD_TRACE_PEER_SERVICE_DEFAULTS_ENABLED=true']);

        $traces = $this->isolateTracer(function () {
            $mysqli = new \mysqli();
            $mysqli->real_connect(self::$host, self::$user, self::$password, self::$database);
            $mysqli->query('SELECT * from tests');
            $mysqli->close();
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('mysqli.__construct', 'mysqli.__construct'),
            SpanAssertion::build('mysqli.real_connect', 'mysqli', 'sql', 'mysqli.real_connect')
                ->withExactTags(self::baseTags()),
            SpanAssertion::build('mysqli.query', 'mysqli', 'sql', 'SELECT * from tests')
                ->withExactTags(self::baseTags(true, true))
                ->withExactMetrics([
                    Tag::DB_ROW_COUNT => 1,
                    '_dd.agent_psr' => 1.0,
                    '_sampling_priority_v1' => 1.0,
                ]),
        ]);
    }

    public function testProceduralCommit()
    {
        $query = "INSERT INTO tests (id, name) VALUES (100, 'Tom')";
        $traces = $this->isolateTracer(function () use ($query) {
            $mysqli = \mysqli_connect(self::$host, self::$user, self::$password, self::$database);
            \mysqli_query($mysqli, $query);
            \mysqli_commit($mysqli);
            $mysqli->close();
        });

        $this->assertOneRowInDatabase('tests', ['id' => 100]);
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
            $mysqli = new \mysqli(self::$host, self::$user, self::$password, self::$database);
            $stmt = $mysqli->prepare("INSERT INTO tests (id, name) VALUES (?, ?)");
            $id = 100;
            $name = 100;
            $stmt->bind_param('is', $id, $name);
            $stmt->execute();
            $mysqli->close();
        });

        $this->assertOneRowInDatabase('tests', ['id' => 100]);
        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('mysqli.__construct', 'mysqli.__construct'),
            SpanAssertion::build('mysqli.prepare', 'mysqli', 'sql', 'INSERT INTO tests (id, name) VALUES (?, ?)')
                ->withExactTags(self::baseTags()),
            SpanAssertion::build('mysqli_stmt.execute', 'mysqli', 'sql', 'INSERT INTO tests (id, name) VALUES (?, ?)')
                ->withExactTags(self::baseTags())
        ]);
    }

    public function testConstructorPreparedStatementPeerServiceEnabled()
    {
        $this->putEnvAndReloadConfig(['DD_TRACE_PEER_SERVICE_DEFAULTS_ENABLED=true']);

        $traces = $this->isolateTracer(function () {
            $mysqli = new \mysqli(self::$host, self::$user, self::$password, self::$database);
            $stmt = $mysqli->prepare("INSERT INTO tests (id, name) VALUES (?, ?)");
            $id = 100;
            $name = 100;
            $stmt->bind_param('is', $id, $name);
            $stmt->execute();
            $mysqli->close();
        });

        $this->assertOneRowInDatabase('tests', ['id' => 100]);
        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('mysqli.__construct', 'mysqli.__construct'),
            SpanAssertion::build('mysqli.prepare', 'mysqli', 'sql', 'INSERT INTO tests (id, name) VALUES (?, ?)')
                ->withExactTags(self::baseTags()),
            SpanAssertion::build('mysqli_stmt.execute', 'mysqli', 'sql', 'INSERT INTO tests (id, name) VALUES (?, ?)')
                ->withExactTags(self::baseTags(true, true))
        ]);
    }

    public function testProceduralSelectDbPeerServiceEnabled()
    {
        $this->putEnvAndReloadConfig(['DD_TRACE_PEER_SERVICE_DEFAULTS_ENABLED=true', 'DD_TRACE_GENERATE_ROOT_SPAN=true']);

        $traces = $this->isolateTracer(function () {
            $mysqli = \mysqli_connect(self::$host, self::$user, self::$password, self::$database);
            \mysqli_select_db($mysqli, 'information_schema');
            \mysqli_query($mysqli, 'SELECT * from columns limit 1');
            $mysqli->close();
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('mysqli_connect', 'mysqli_connect'),
            SpanAssertion::build('mysqli_query', 'mysqli', 'sql', 'SELECT * from columns limit 1')
                ->withExactTags(array_merge(
                    self::baseTags(true, true),
                    [
                        'db.name' => 'information_schema',
                        '_dd.peer.service.source' => 'db.name',
                        'peer.service' => 'information_schema',
                        '_dd.base_service' => 'phpunit',
                    ]
                ))
                ->withExactMetrics([
                    Tag::DB_ROW_COUNT => 1,
                ]),
        ], true, false);
    }

    public function testConstructorSelectDbPeerServiceEnabled()
    {
        $this->putEnvAndReloadConfig(['DD_TRACE_PEER_SERVICE_DEFAULTS_ENABLED=true', 'DD_TRACE_GENERATE_ROOT_SPAN=true']);

        $traces = $this->isolateTracer(function () {
            $mysqli = new \mysqli(self::$host, self::$user, self::$password, self::$database);
            $mysqli->select_db('information_schema');
            $mysqli->query('SELECT * from columns limit 1');
            $mysqli->close();
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('mysqli.__construct', 'mysqli.__construct'),
            SpanAssertion::build('mysqli.query', 'mysqli', 'sql', 'SELECT * from columns limit 1')
                ->withExactTags(array_merge(
                    self::baseTags(true, true),
                    [
                        'db.name' => 'information_schema',
                        '_dd.peer.service.source' => 'db.name',
                        'peer.service' => 'information_schema',
                        '_dd.base_service' => 'phpunit'
                    ]
                ))
                ->withExactMetrics([
                    Tag::DB_ROW_COUNT => 1,
                ]),
        ], true, false);
    }

    public function testLimitedTracerConstructorQuery()
    {
        $traces = $this->isolateLimitedTracer(function () {
            $mysqli = new \mysqli(self::$host, self::$user, self::$password, self::$database);
            $mysqli->query('SELECT * from tests');
            $mysqli->close();
        });

        $this->assertEmpty($traces);
    }

    public function testLimitedTracerProceduralCommit()
    {
        $traces = $this->isolateLimitedTracer(function () {
            $mysqli = \mysqli_connect(self::$host, self::$user, self::$password, self::$database);
            \mysqli_query($mysqli, "INSERT INTO tests (id, name) VALUES (100, 'From Test')");
            \mysqli_commit($mysqli);
            $mysqli->close();
        });

        $this->assertOneRowInDatabase('tests', ['id' => 100]);
        $this->assertEmpty($traces);
    }

    public function testLimitedTracerConstructorPreparedStatement()
    {
        $traces = $this->isolateLimitedTracer(function () {
            $mysqli = new \mysqli(self::$host, self::$user, self::$password, self::$database);
            $stmt = $mysqli->prepare("INSERT INTO tests (id, name) VALUES (?, ?)");
            $id = 100;
            $name = 100;
            $stmt->bind_param('is', $id, $name);
            $stmt->execute();
            $mysqli->close();
        });

        $this->assertOneRowInDatabase('tests', ['id' => 100]);
        $this->assertEmpty($traces);
    }

    public function testProceduralPreparedStatement()
    {
        $traces = $this->isolateTracer(function () {
            $mysqli = \mysqli_connect(self::$host, self::$user, self::$password, self::$database);
            $stmt = \mysqli_prepare($mysqli, "INSERT INTO tests (id, name) VALUES (?, ?)");
            $id = 100;
            $name = 100;
            $stmt->bind_param('is', $id, $name);

            \mysqli_stmt_execute($stmt);
            $mysqli->close();
        });

        $this->assertOneRowInDatabase('tests', ['id' => 100]);
        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('mysqli_connect', 'mysqli_connect'),
            SpanAssertion::build('mysqli_prepare', 'mysqli', 'sql', 'INSERT INTO tests (id, name) VALUES (?, ?)')
                ->withExactTags(self::baseTags()),
            SpanAssertion::build('mysqli_stmt_execute', 'mysqli', 'sql', 'INSERT INTO tests (id, name) VALUES (?, ?)')
                ->withExactTags(self::baseTags()),
        ], true, false);
    }

    public function testProceduralPreparedStatementPeerServiceEnabled()
    {
        $this->putEnvAndReloadConfig(['DD_TRACE_PEER_SERVICE_DEFAULTS_ENABLED=true']);

        $traces = $this->isolateTracer(function () {
            $mysqli = \mysqli_connect(self::$host, self::$user, self::$password, self::$database);
            $stmt = \mysqli_prepare($mysqli, "INSERT INTO tests (id, name) VALUES (?, ?)");
            $id = 100;
            $name = 100;
            $stmt->bind_param('is', $id, $name);

            \mysqli_stmt_execute($stmt);
            $mysqli->close();
        });

        $this->assertOneRowInDatabase('tests', ['id' => 100]);
        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('mysqli_connect', 'mysqli_connect'),
            SpanAssertion::build('mysqli_prepare', 'mysqli', 'sql', 'INSERT INTO tests (id, name) VALUES (?, ?)')
                ->withExactTags(self::baseTags()),
            SpanAssertion::build('mysqli_stmt_execute', 'mysqli', 'sql', 'INSERT INTO tests (id, name) VALUES (?, ?)')
                ->withExactTags(self::baseTags(true, true)),
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
                    Tag::ERROR_MSG,
                    'error.type',
                    'error.stack',
                    Tag::SPAN_KIND,
                    Tag::COMPONENT,
                    Tag::DB_SYSTEM,
                ]),
        ]);
    }

    public function testNoFakeServices()
    {
        $this->putEnvAndReloadConfig([
            'DD_SERVICE=configured_service',
            'DD_TRACE_REMOVE_INTEGRATION_SERVICE_NAMES_ENABLED=true',
            'DD_TRACE_GENERATE_ROOT_SPAN=true'
        ]);

        $traces = $this->isolateTracer(function () {
            $mysqli = new \mysqli(self::$host, self::$user, self::$password, self::$database);
            $mysqli->query('SELECT * from tests');
            $mysqli->close();
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('mysqli.__construct', 'mysqli.__construct'),
            SpanAssertion::build('mysqli.query', 'configured_service', 'sql', 'SELECT * from tests')
                ->withExactTags(self::baseTags())
                ->withExactMetrics([
                    Tag::DB_ROW_COUNT => 1,
                ]),
        ], true, false);
    }

    public function testServiceMappedSplitByDomain()
    {
        self::putEnv('DD_TRACE_DB_CLIENT_SPLIT_BY_INSTANCE=true');
        self::putEnv('DD_SERVICE_MAPPING=mysqli:my-mysqli');
        $traces = $this->isolateTracer(function () {
            new \mysqli(self::$host, self::$user, self::$password, self::$database);
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('mysqli.__construct', 'my-mysqli-mysql_integration', 'sql', 'mysqli.__construct', SpanAssertion::NOT_TESTED)
        ]);
    }

    private function baseTags($expectDbName = true, $expectPeerService = false)
    {
        $tags = [
            'out.host' => self::$host,
            'out.port' => self::$port,
            'db.type' => 'mysql',
            Tag::SPAN_KIND => 'client',
            Tag::COMPONENT => 'mysqli',
            Tag::DB_SYSTEM => 'mysql',
        ];

        if ($expectDbName) {
            $tags['db.name'] = self::$database;
        }

        if ($expectPeerService) {
            $tags['peer.service'] = self::$database;
            $tags['_dd.peer.service.source'] = 'db.name';
        }

        return $tags;
    }

    private function setUpDatabase()
    {
        $this->isolateTracer(function () {
            $mysqli = new \mysqli(self::$host, self::$user, self::$password, self::$database);
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
            $mysqli = new \mysqli(self::$host, self::$user, self::$password, self::$database);
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
        $mysqli = new \mysqli(self::$host, self::$user, self::$password, self::$database);
        $result = $mysqli->query("SELECT * FROM $table $inlineWhere");
        if (false === $result) {
            $message = mysqli_error($mysqli);
            $this->fail("Error while checking database rows: $message");
        }
        return $result->fetch_all(MYSQLI_ASSOC);
    }
}
