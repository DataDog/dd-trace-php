<?php

namespace DDTrace\Tests\Integrations\SQLSRV;

use DDTrace\Integrations\SQLSRV\SQLSRVIntegration;
use DDTrace\Tag;
use DDTrace\Tests\Common\IntegrationTestCase;
use DDTrace\Tests\Common\SpanAssertion;

class SQLSRVTest extends IntegrationTestCase
{
    private static $host = 'sqlsrv_integration';
    private static $port = '1433';
    private static $db = 'master';
    private static $user = 'sa';
    private static $password = 'Password12!';

    // phpcs:disable
    const ERROR_CONNECT = 'SQL Error: 1045. Driver error: 28000. Driver-specific error data: Access denied for user \'sa\'@\'%\' (using password: YES)';
    const ERROR_QUERY_17 = 'SQL error: 208. Driver error: 42S02. Driver-specific error data: [Microsoft][ODBC Driver 17 for SQL Server][SQL Server]Invalid object name \'non_existing_table\'.';
    const ERROR_QUERY_18 = 'SQL error: 208. Driver error: 42S02. Driver-specific error data: [Microsoft][ODBC Driver 18 for SQL Server][SQL Server]Invalid object name \'non_existing_table\'.';
    const ERROR_PREPARE_17 = 'SQL error: 208. Driver error: 42S02. Driver-specific error data: [Microsoft][ODBC Driver 17 for SQL Server][SQL Server]Invalid object name \'non_existing_table\'. | SQL error: 8180. Driver error: 42000. Driver-specific error data: [Microsoft][ODBC Driver 17 for SQL Server][SQL Server]Statement(s) could not be prepared.';
    const ERROR_PREPARE_18 = 'SQL error: 208. Driver error: 42S02. Driver-specific error data: [Microsoft][ODBC Driver 18 for SQL Server][SQL Server]Invalid object name \'non_existing_table\'. | SQL error: 8180. Driver error: 42000. Driver-specific error data: [Microsoft][ODBC Driver 18 for SQL Server][SQL Server]Statement(s) could not be prepared.';
    // phpcs:enable

    private static function getArchitecture()
    {
        return php_uname('m');
    }

    public static function ddSetUpBeforeClass()
    {
        parent::ddSetUpBeforeClass();
        self::putenv('DD_SQLSRV_ANALYTICS_ENABLED=true');
    }

    public static function ddTearDownAfterClass()
    {
        parent::ddTearDownAfterClass();
        self::putenv('DD_SQLSRV_ANALYTICS_ENABLED');
    }

    protected function ddSetUp()
    {
        parent::ddSetUp();
        $this->setUpDatabase();
    }

    protected function ddTearDown()
    {
        $this->clearDatabase();
        parent::ddTearDown();
    }

    protected function envsToCleanUpAtTearDown()
    {
        return [
            'DD_TRACE_PEER_SERVICE_DEFAULTS_ENABLED',
            'DD_TRACE_REMOVE_INTEGRATION_SERVICE_NAMES_ENABLED',
            'DD_SERVICE',
        ];
    }

    public function testConnectOk()
    {
        $traces = $this->isolateTracer(function () {
            $conn = $this->createConnection();
            sqlsrv_close($conn);
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build('sqlsrv_connect', 'sqlsrv', 'sql', 'sqlsrv_connect')
                ->withExactTags(self::baseTags())
        ]);
    }

    public function testConnectError()
    {
        $traces = $this->isolateTracer(function () {
            $conn = sqlsrv_connect(self::$host, ['PWD' => 'wrong_password']);
            $this->assertFalse($conn);
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('sqlsrv_connect')
                ->setError('SQLSRV error', SQLSRVTest::ERROR_CONNECT)
        ]);
    }

    public function testQueryOk()
    {
        $query = 'SELECT * FROM tests WHERE id=1';
        $traces = $this->isolateTracer(function () use ($query) {
            $conn = $this->createConnection();
            sqlsrv_query($conn, $query, [], ['Scrollable' => 'static']);
            sqlsrv_close($conn);
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('sqlsrv_connect'),
            SpanAssertion::build('sqlsrv_query', 'sqlsrv', 'sql', $query)
                ->withExactTags(self::baseTags($query))
                ->withExactMetrics([
                    Tag::DB_ROW_COUNT => 1.0,
                    Tag::ANALYTICS_KEY => 1.0,
                    '_dd.agent_psr' => 1.0,
                    '_sampling_priority_v1' => 1.0,
                ])
        ]);
    }

    public function testQueryOkPeerServiceEnabled()
    {
        $this->putEnvAndReloadConfig(['DD_TRACE_PEER_SERVICE_DEFAULTS_ENABLED=true']);

        $query = 'SELECT * FROM tests WHERE id=1';
        $traces = $this->isolateTracer(function () use ($query) {
            $conn = $this->createConnection();
            sqlsrv_query($conn, $query, [], ['Scrollable' => 'static']);
            sqlsrv_close($conn);
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('sqlsrv_connect'),
            SpanAssertion::build('sqlsrv_query', 'sqlsrv', 'sql', $query)
                ->withExactTags(self::baseTags($query, true))
                ->withExactMetrics([
                    Tag::DB_ROW_COUNT => 1.0,
                    Tag::ANALYTICS_KEY => 1.0,
                    '_dd.agent_psr' => 1.0,
                    '_sampling_priority_v1' => 1.0,
                ])
        ]);
    }

    public function testQueryError()
    {
        $query = "SELECT * FROM non_existing_table";
        $traces = $this->isolateTracer(function () use ($query) {
            $conn = $this->createConnection();
            sqlsrv_query($conn, $query);
            sqlsrv_close($conn);
        });

        // No metrics expected, as the default 'forward' cursor type is used
        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('sqlsrv_connect'),
            SpanAssertion::build('sqlsrv_query', 'sqlsrv', 'sql', $query)
                ->withExactTags(self::baseTags($query))
                ->setError(
                    'SQLSRV error',
                    self::getArchitecture() === 'x86_64' ? SQLSRVTest::ERROR_QUERY_17 : SQLSRVTest::ERROR_QUERY_18
                )->withExactMetrics([
                    Tag::ANALYTICS_KEY => 1.0,
                    '_dd.agent_psr' => 1.0,
                    '_sampling_priority_v1' => 1.0,
                ])
        ]);
    }

    public function testQueryErrorPeerServiceEnabled()
    {
        $this->putEnvAndReloadConfig(['DD_TRACE_PEER_SERVICE_DEFAULTS_ENABLED=true']);

        $query = "SELECT * FROM non_existing_table";
        $traces = $this->isolateTracer(function () use ($query) {
            $conn = $this->createConnection();
            sqlsrv_query($conn, $query);
            sqlsrv_close($conn);
        });

        // No metrics expected, as the default 'forward' cursor type is used
        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('sqlsrv_connect'),
            SpanAssertion::build('sqlsrv_query', 'sqlsrv', 'sql', $query)
                ->withExactTags(self::baseTags($query, true))
                ->setError(
                    'SQLSRV error',
                    self::getArchitecture() === 'x86_64' ? SQLSRVTest::ERROR_QUERY_17 : SQLSRVTest::ERROR_QUERY_18
                )->withExactMetrics([
                    Tag::ANALYTICS_KEY => 1.0,
                    '_dd.agent_psr' => 1.0,
                    '_sampling_priority_v1' => 1.0,
                ])
        ]);
    }

    public function testCommitOk()
    {
        $query = "INSERT INTO tests (id, name) VALUES (1000, 'Sam')";
        $traces = $this->isolateTracer(function () use ($query) {
            $conn = $this->createConnection();
            sqlsrv_begin_transaction($conn);
            sqlsrv_query($conn, $query);
            sqlsrv_commit($conn);
            sqlsrv_close($conn);
        });

        $this->assertOneRowInDatabase('tests', ['id' => 1000, 'name' => 'Sam']);
        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('sqlsrv_connect'),
            SpanAssertion::build('sqlsrv_query', 'sqlsrv', 'sql', $query)
                ->withExactTags(self::baseTags($query))
                ->withExactMetrics([
                    Tag::DB_ROW_COUNT => 1.0,
                    Tag::ANALYTICS_KEY => 1.0,
                    '_dd.agent_psr' => 1.0,
                    '_sampling_priority_v1' => 1.0,
                ]),
            SpanAssertion::build('sqlsrv_commit', 'sqlsrv', 'sql', 'sqlsrv_commit')
                ->withExactTags(self::baseTags())
        ]);
    }

    public function testPrepareOk()
    {
        $query = "SELECT * FROM tests WHERE id = ?";
        $traces = $this->isolateTracer(function () use ($query) {
            $conn = $this->createConnection();
            $stmt = sqlsrv_prepare($conn, $query, [1], ['Scrollable' => 'buffered']);
            sqlsrv_execute($stmt);
            sqlsrv_close($conn);
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('sqlsrv_connect'),
            SpanAssertion::build('sqlsrv_prepare', 'sqlsrv', 'sql', $query)
                ->withExactTags(self::baseTags($query)),
            SpanAssertion::build('sqlsrv_execute', 'sqlsrv', 'sql', $query)
                ->withExactTags(self::baseTags($query))
                ->withExactMetrics([
                    Tag::DB_ROW_COUNT => 1.0,
                    Tag::ANALYTICS_KEY => 1.0,
                    '_dd.agent_psr' => 1.0,
                    '_sampling_priority_v1' => 1.0,
                ])
        ]);
    }

    public function testPrepareOkPeerServiceEnabled()
    {
        $this->putEnvAndReloadConfig(['DD_TRACE_PEER_SERVICE_DEFAULTS_ENABLED=true']);

        $query = "SELECT * FROM tests WHERE id = ?";
        $traces = $this->isolateTracer(function () use ($query) {
            $conn = $this->createConnection();
            $stmt = sqlsrv_prepare($conn, $query, [1], ['Scrollable' => 'buffered']);
            sqlsrv_execute($stmt);
            sqlsrv_close($conn);
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('sqlsrv_connect'),
            SpanAssertion::build('sqlsrv_prepare', 'sqlsrv', 'sql', $query)
                ->withExactTags(self::baseTags($query)),
            SpanAssertion::build('sqlsrv_execute', 'sqlsrv', 'sql', $query)
                ->withExactTags(self::baseTags($query, true))
                ->withExactMetrics([
                    Tag::DB_ROW_COUNT => 1.0,
                    Tag::ANALYTICS_KEY => 1.0,
                    '_dd.agent_psr' => 1.0,
                    '_sampling_priority_v1' => 1.0,
                ])
        ]);
    }

    public function testPrepareError()
    {
        $query = "SELECT * FROM non_existing_table WHERE id = ?";
        $traces = $this->isolateTracer(function () use ($query) {
            $conn = $this->createConnection();
            $stmt = sqlsrv_prepare($conn, $query, [1]);
            sqlsrv_execute($stmt);
            sqlsrv_close($conn);
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('sqlsrv_connect'),
            SpanAssertion::exists('sqlsrv_prepare'),
            SpanAssertion::build('sqlsrv_execute', 'sqlsrv', 'sql', $query)
                ->setError(
                    'SQLSRV error',
                    self::getArchitecture() === 'x86_64' ? SQLSRVTest::ERROR_QUERY_17 : SQLSRVTest::ERROR_QUERY_18
                )->withExactTags(self::baseTags($query))
                ->withExactMetrics([
                    Tag::ANALYTICS_KEY => 1.0,
                    '_dd.agent_psr' => 1.0,
                    '_sampling_priority_v1' => 1.0,
                ])
        ]);
    }

    public function testPrepareErrorPeerServiceEnabled()
    {
        $this->putEnvAndReloadConfig(['DD_TRACE_PEER_SERVICE_DEFAULTS_ENABLED=true']);

        $query = "SELECT * FROM non_existing_table WHERE id = ?";
        $traces = $this->isolateTracer(function () use ($query) {
            $conn = $this->createConnection();
            $stmt = sqlsrv_prepare($conn, $query, [1]);
            sqlsrv_execute($stmt);
            sqlsrv_close($conn);
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('sqlsrv_connect'),
            SpanAssertion::exists('sqlsrv_prepare'),
            SpanAssertion::build('sqlsrv_execute', 'sqlsrv', 'sql', $query)
                ->setError(
                    'SQLSRV error',
                    self::getArchitecture() === 'x86_64' ? SQLSRVTest::ERROR_QUERY_17 : SQLSRVTest::ERROR_QUERY_18
                )->withExactTags(self::baseTags($query, true))
                ->withExactMetrics([
                    Tag::ANALYTICS_KEY => 1.0,
                    '_dd.agent_psr' => 1.0,
                    '_sampling_priority_v1' => 1.0,
                ])
        ]);
    }

    public function testExecError()
    {
        $query = "SELECT * FROM non_existing_table";
        $traces = $this->isolateTracer(function () use ($query) {
            $conn = $this->createConnection();
            sqlsrv_begin_transaction($conn);
            $stmt = sqlsrv_prepare($conn, $query);
            sqlsrv_execute($stmt);
            sqlsrv_commit($conn);
            sqlsrv_close($conn);
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('sqlsrv_connect'),
            SpanAssertion::exists('sqlsrv_prepare'),
            SpanAssertion::build('sqlsrv_execute', 'sqlsrv', 'sql', $query)
                ->setError(
                    'SQLSRV error',
                    self::getArchitecture() === 'x86_64' ? SQLSRVTest::ERROR_QUERY_17 : SQLSRVTest::ERROR_QUERY_18
                )->withExactTags(self::baseTags($query)),
            SpanAssertion::exists('sqlsrv_commit')
        ]);
    }

    public function testLimitedTracerConnectQuery()
    {
        $traces = $this->isolateLimitedTracer(function () {
            $conn = $this->createConnection();
            $query = 'SELECT * FROM tests WHERE id=1';
            sqlsrv_query($conn, $query);
            sqlsrv_close($conn);
        });

        $this->assertEmpty($traces);
    }

    public function testLimitedtracerCommit()
    {
        $traces = $this->isolateLimitedTracer(function () {
            $conn = $this->createConnection();
            $query = "INSERT INTO tests (id, name) VALUES (100, 'From Test')";
            sqlsrv_begin_transaction($conn);
            sqlsrv_query($conn, $query);
            sqlsrv_commit($conn);
            sqlsrv_close($conn);
        });

        $this->assertOneRowInDatabase('tests', ['id' => 100, 'name' => 'From Test']);
        $this->assertEmpty($traces);
    }

    public function testLimitedConnectPrepareStatement()
    {
        $traces = $this->isolateLimitedTracer(function () {
            $conn = $this->createConnection();
            $query = "INSERT INTO tests (id, name) VALUES (?, ?)";
            $stmt = sqlsrv_prepare($conn, $query, [100, 'From Test']);
            sqlsrv_execute($stmt);
            sqlsrv_close($conn);
        });

        $this->assertOneRowInDatabase('tests', ['id' => 100, 'name' => 'From Test']);
        $this->assertEmpty($traces);
    }

    public function testConnectPrepareStatement()
    {
        $query = "INSERT INTO tests (id, name) VALUES (?, ?)";
        $traces = $this->isolateTracer(function () use ($query) {
            $conn = $this->createConnection();
            $stmt = sqlsrv_prepare($conn, $query, [100, 'From Test']);
            sqlsrv_execute($stmt);
            sqlsrv_close($conn);
        });

        $this->assertOneRowInDatabase('tests', ['id' => 100, 'name' => 'From Test']);
        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('sqlsrv_connect'),
            SpanAssertion::build('sqlsrv_prepare', 'sqlsrv', 'sql', $query)
                ->withExactTags(self::baseTags($query)),
            SpanAssertion::build('sqlsrv_execute', 'sqlsrv', 'sql', $query)
                ->withExactTags(self::baseTags($query))
                ->withExactMetrics([
                    Tag::DB_ROW_COUNT => 1.0,
                    Tag::ANALYTICS_KEY => 1.0,
                    '_dd.agent_psr' => 1.0,
                    '_sampling_priority_v1' => 1.0,
                ])
        ], true, false);
    }

    public function testNoFakeServices()
    {
        $this->putEnvAndReloadConfig([
            'DD_SERVICE=configured_service',
            'DD_TRACE_REMOVE_INTEGRATION_SERVICE_NAMES_ENABLED=true',
        ]);

        $query = 'SELECT * FROM tests WHERE id=1';
        $traces = $this->isolateTracer(function () use ($query) {
            $conn = $this->createConnection();
            sqlsrv_query($conn, $query, [], ['Scrollable' => 'static']);
            sqlsrv_close($conn);
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('sqlsrv_connect'),
            SpanAssertion::build('sqlsrv_query', 'configured_service', 'sql', $query)
                ->withExactTags(self::baseTags($query))
                ->withExactMetrics([
                    Tag::DB_ROW_COUNT => 1.0,
                    Tag::ANALYTICS_KEY => 1.0,
                    '_dd.agent_psr' => 1.0,
                    '_sampling_priority_v1' => 1.0,
                ])
        ]);
    }

    private function createConnection()
    {
        $conn = sqlsrv_connect(
            self::$host . ', ' . self::$port,
            [
                'PWD' => self::$password,
                'Database' => self::$db,
                'UID' => self::$user,
                'TrustServerCertificate' => true
            ]
        );

        return $conn;
    }

    private function queryDatabaseAllAssociative($table, $wheres)
    {
        $conditions = [];
        foreach ($wheres as $key => $value) {
            $conditions[] = "$key = '$value'";
        }
        $inlineWhere = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
        $conn = $this->createConnection();
        $sql = "SELECT * FROM $table $inlineWhere";
        $stmt = sqlsrv_query($conn, $sql);
        $results = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $results[] = $row;
        }
        sqlsrv_close($conn);
        return $results;
    }

    private function assertOneRowInDatabase($table, $wheres)
    {
        $results = $this->queryDatabaseAllAssociative($table, $wheres);
        $this->assertCount(1, $results);
    }

    private static function baseTags($query = null, $expectPeerService = false)
    {
        $tags = [
            Tag::SPAN_KIND => 'client',
            Tag::COMPONENT => SQLSRVIntegration::NAME,
            Tag::DB_SYSTEM => SQLSRVIntegration::SYSTEM,
            Tag::DB_INSTANCE => self::$db,
            Tag::DB_USER => self::$user,
            Tag::TARGET_HOST => self::$host,
            Tag::TARGET_PORT => self::$port,
        ] + ($query ? [Tag::DB_STMT => $query] : []);

        if ($expectPeerService) {
            $tags['peer.service'] = 'master';
            $tags['_dd.peer.service.source'] = 'db.instance';
        }

        return $tags;
    }

    private function clearDatabase()
    {
        $conn = $this->createConnection();
        $sql = 'DROP TABLE IF EXISTS tests';
        sqlsrv_query($conn, $sql);
        sqlsrv_commit($conn);
        sqlsrv_close($conn);
    }

    private function setUpDatabase()
    {
        $conn = $this->createConnection();
        $sql1 = 'CREATE TABLE tests (id INT PRIMARY KEY, name VARCHAR(100))';
        sqlsrv_query($conn, $sql1);
        $sql2 = "INSERT INTO tests (id, name) VALUES (1, 'Tom')";
        sqlsrv_query($conn, $sql2);
        sqlsrv_commit($conn);
        sqlsrv_close($conn);
    }
}
