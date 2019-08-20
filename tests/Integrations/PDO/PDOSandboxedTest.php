<?php

namespace DDTrace\Tests\Integrations\PDO;

use DDTrace\Integrations\IntegrationsLoader;
use DDTrace\Tests\Common\IntegrationTestCase;
use DDTrace\Tests\Common\SpanAssertion;

final class PDOSandboxedTest extends IntegrationTestCase
{
    const MYSQL_DATABASE = 'test';
    const MYSQL_USER = 'test';
    const MYSQL_PASSWORD = 'test';
    const MYSQL_HOST = 'mysql_integration';

    public static function setUpBeforeClass()
    {
        parent::setUpBeforeClass();
        putenv('DD_TRACE_SANDBOX_ENABLED=true');
        IntegrationsLoader::reload();
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

    public function testPDOContructOk()
    {
        $traces = $this->isolateTracer(function () {
                $this->pdoInstance();
        });
        $this->assertSpans($traces, [
            SpanAssertion::build('PDO.__construct', 'PDO', 'db', 'PDO.__construct')
                // TODO Add this back when binding $this
                //->withExactTags($this->baseTags()),
                ->withExactTags([]),
        ]);
    }

    // Add this back after exception PR gets merged
    /*
    public function testPDOContructError()
    {
        $traces = $this->isolateTracer(function () {
            try {
                new \PDO($this->mysqlDns(), 'wrong_user', 'wrong_password');
            } catch (\PDOException $ex) {
            }
        });
        $this->assertSpans($traces, [
            SpanAssertion::build('PDO.__construct', 'PDO', 'sql', 'PDO.__construct')
                ->setError('PDOException', 'Sql error: SQLSTATE[HY000] [1045]'),
        ]);
    }
    */

    public function testPDOExecOk()
    {
        $query = "INSERT INTO tests (id, name) VALUES (100, 'Sam')";
        $traces = $this->isolateTracer(function () use ($query) {
            $pdo = $this->pdoInstance();
            $pdo->beginTransaction();
            $pdo->exec($query);
            $pdo->commit();
            $pdo = null;
        });
        $this->assertSpans($traces, [
            SpanAssertion::exists('PDO.__construct'),
            SpanAssertion::build('PDO.exec', 'PDO', 'sql', $query)
                ->setTraceAnalyticsCandidate()
                ->withExactTags(array_merge($this->baseTags(), [
                    'db.rowcount' => '1',
                ])),
            SpanAssertion::exists('PDO.commit'),
        ]);
    }

    public function testPDOExecError()
    {
        $query = "WRONG QUERY)";
        $traces = $this->isolateTracer(function () use ($query) {
            try {
                $pdo = $this->pdoInstance();
                $pdo->beginTransaction();
                $pdo->exec($query);
                $pdo->commit();
                $pdo = null;
            } catch (\PDOException $ex) {
            }
        });
        $this->assertSpans($traces, [
            SpanAssertion::exists('PDO.__construct'),
            SpanAssertion::build('PDO.exec', 'PDO', 'sql', $query)
                ->setTraceAnalyticsCandidate()
                ->setError('PDO error', 'SQL error: 42000. Driver error: 1064')
                ->withExactTags(array_merge($this->baseTags(), [
                    'db.rowcount' => '',
                ])),
            SpanAssertion::exists('PDO.commit'),
        ]);
    }

    public function testPDOExecException()
    {
        $query = "WRONG QUERY)";
        $traces = $this->isolateTracer(function () use ($query) {
            try {
                $pdo = $this->pdoInstance();
                $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                $pdo->beginTransaction();
                $pdo->exec($query);
                $pdo->commit();
                $pdo = null;
                $this->fail('Should throw and exception');
            } catch (\PDOException $ex) {
            }
        });
        $this->assertSpans($traces, [
            SpanAssertion::exists('PDO.__construct'),
            SpanAssertion::build('PDO.exec', 'PDO', 'sql', $query)
                ->setTraceAnalyticsCandidate()
                ->setError('PDOException', 'Sql error')
                ->withExactTags($this->baseTags()),
        ]);
    }

    public function testPDOQuery()
    {
        $query = "SELECT * FROM tests WHERE id=1";
        $traces = $this->isolateTracer(function () use ($query) {
            $pdo = $this->pdoInstance();
            $pdo->query($query);
            $pdo = null;
        });
        $this->assertSpans($traces, [
            SpanAssertion::exists('PDO.__construct'),
            SpanAssertion::build('PDO.query', 'PDO', 'sql', $query)
                ->setTraceAnalyticsCandidate()
                ->withExactTags(array_merge($this->baseTags(), [
                    'db.rowcount' => '1',
                ])),
        ]);
    }

    public function testPDOQueryError()
    {
        $query = "WRONG QUERY";
        $traces = $this->isolateTracer(function () use ($query) {
            try {
                $pdo = $this->pdoInstance();
                $pdo->query($query);
                $pdo = null;
            } catch (\PDOException $ex) {
            }
        });
        $this->assertSpans($traces, [
            SpanAssertion::exists('PDO.__construct'),
            SpanAssertion::build('PDO.query', 'PDO', 'sql', $query)
                ->setTraceAnalyticsCandidate()
                ->setError('PDO error', 'SQL error: 42000. Driver error: 1064')
                ->withExactTags(array_merge($this->baseTags(), [
                    'db.rowcount' => '',
                ])),
        ]);
    }

    public function testPDOQueryException()
    {
        $query = "WRONG QUERY";
        $traces = $this->isolateTracer(function () use ($query) {
            try {
                $pdo = $this->pdoInstance();
                $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                $pdo->query($query);
                $pdo = null;
            } catch (\PDOException $ex) {
            }
        });
        $this->assertSpans($traces, [
            SpanAssertion::exists('PDO.__construct'),
            SpanAssertion::build('PDO.query', 'PDO', 'sql', $query)
                ->setTraceAnalyticsCandidate()
                ->setError('PDOException', 'Sql error')
                ->withExactTags($this->baseTags()),
        ]);
    }

    public function testPDOCommit()
    {
        $query = "INSERT INTO tests (id, name) VALUES (100, 'Sam')";
        $traces = $this->isolateTracer(function () use ($query) {
            $pdo = $this->pdoInstance();
            $pdo->beginTransaction();
            $pdo->exec($query);
            $pdo->commit();
            $pdo = null;
        });
        $this->assertSpans($traces, [
            SpanAssertion::exists('PDO.__construct'),
            SpanAssertion::exists('PDO.exec'),
            SpanAssertion::build('PDO.commit', 'PDO', 'sql', 'PDO.commit')
                ->withExactTags(array_merge($this->baseTags(), [])),
        ]);
    }

    public function testPDOStatementOk()
    {
        $query = "SELECT * FROM tests WHERE id = ?";
        $traces = $this->isolateTracer(function () use ($query) {
            $pdo = $this->pdoInstance();
            $stmt = $pdo->prepare($query);
            $stmt->execute([1]);
            $results = $stmt->fetchAll();
            $this->assertEquals('Tom', $results[0]['name']);
            $stmt->closeCursor();
            $stmt = null;
            $pdo = null;
        });
        $this->assertSpans($traces, [
            SpanAssertion::exists('PDO.__construct'),
            SpanAssertion::build(
                'PDO.prepare',
                'PDO',
                'sql',
                "SELECT * FROM tests WHERE id = ?"
            )->withExactTags(array_merge($this->baseTags(), [])),
            SpanAssertion::build(
                'PDOStatement.execute',
                'PDO',
                'sql',
                "SELECT * FROM tests WHERE id = ?"
            )
                ->setTraceAnalyticsCandidate()
                ->withExactTags(array_merge($this->baseTags(), [
                'db.rowcount' => 1,
                ])),
        ]);
    }

    public function testPDOStatementError()
    {
        $query = "WRONG QUERY";
        $traces = $this->isolateTracer(function () use ($query) {
            try {
                $pdo = $this->pdoInstance();
                $stmt = $pdo->prepare($query);
                $stmt->execute([1]);
                $stmt->fetchAll();
                $stmt->closeCursor();
                $stmt = null;
                $pdo = null;
            } catch (\PDOException $ex) {
            }
        });
        $this->assertSpans($traces, [
            SpanAssertion::exists('PDO.__construct'),
            SpanAssertion::build('PDO.prepare', 'PDO', 'sql', "WRONG QUERY")
                ->withExactTags(array_merge($this->baseTags(), [])),
            SpanAssertion::build('PDOStatement.execute', 'PDO', 'sql', "WRONG QUERY")
                ->setTraceAnalyticsCandidate()
                ->setError('PDOStatement error', 'SQL error: 42000. Driver error: 1064')
                    ->withExactTags(array_merge($this->baseTags(), [
                        'db.rowcount' => 0,
                    ])),
        ]);
    }

    public function testPDOStatementException()
    {
        $query = "WRONG QUERY";
        $traces = $this->isolateTracer(function () use ($query) {
            try {
                $pdo = $this->pdoInstance();
                $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                $stmt = $pdo->prepare($query);
                $stmt->execute([1]);
                $stmt->fetchAll();
                $stmt->closeCursor();
                $stmt = null;
                $pdo = null;
            } catch (\PDOException $ex) {
            }
        });
        $this->assertSpans($traces, [
            SpanAssertion::exists('PDO.__construct'),
            SpanAssertion::build('PDO.prepare', 'PDO', 'sql', "WRONG QUERY")
                ->withExactTags(array_merge($this->baseTags(), [])),
            SpanAssertion::build('PDOStatement.execute', 'PDO', 'sql', "WRONG QUERY")
                ->setTraceAnalyticsCandidate()
                ->setError('PDOException', 'Sql error')
                ->withExactTags($this->baseTags()),
        ]);
    }

    public function testLimitedTracerPDO()
    {
        $query = "SELECT * FROM tests WHERE id = ?";
        $traces = $this->isolateLimitedTracer(function () use ($query) {
            $pdo = $this->pdoInstance();
            $stmt = $pdo->prepare($query);
            $stmt->execute([1]);
            $results = $stmt->fetchAll();
            $this->assertEquals('Tom', $results[0]['name']);
            $stmt->closeCursor();
            $stmt = null;
            $pdo = null;
        });

        $this->assertEmpty($traces);
    }

    private function pdoInstance()
    {
        return new \PDO($this->mysqlDns(), self::MYSQL_USER, self::MYSQL_PASSWORD);
    }

    private function setUpDatabase()
    {
        $this->isolateTracer(function () {
            $pdo = $this->pdoInstance();
            $pdo->beginTransaction();
            $pdo->exec("
                CREATE TABLE tests (
                    id integer not null primary key,
                    name varchar(100)
                )
            ");
            $pdo->exec("INSERT INTO tests (id, name) VALUES (1, 'Tom')");
            $pdo->commit();
            $dbh = null;
        });
    }

    private function clearDatabase()
    {
        $this->isolateTracer(function () {
            $pdo = $this->pdoInstance();
            $pdo->beginTransaction();
            $pdo->exec("DROP TABLE tests");
            $pdo->commit();
            $dbh = null;
        });
    }

    public function mysqlDns()
    {
        return "mysql:host=" . self::MYSQL_HOST . ";dbname=" . self::MYSQL_DATABASE;
    }

    private function baseTags()
    {
        return [
            'db.engine' => 'mysql',
            'out.host' => self::MYSQL_HOST,
            'db.name' => self::MYSQL_DATABASE,
            'db.user' => self::MYSQL_USER,
        ];
    }
}
