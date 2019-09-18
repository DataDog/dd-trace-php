<?php

namespace DDTrace\Tests\Integrations\PDO;

use DDTrace\Tests\Common\IntegrationTestCase;
use DDTrace\Tests\Common\SpanAssertion;

class PDOTest extends IntegrationTestCase
{
    const IS_SANDBOX = false;

    const MYSQL_DATABASE = 'test';
    const MYSQL_USER = 'test';
    const MYSQL_PASSWORD = 'test';
    const MYSQL_HOST = 'mysql_integration';

    // phpcs:disable
    const ERROR_CONSTRUCT = 'Sql error: SQLSTATE[HY000] [1045]';
    const ERROR_EXEC = 'Sql error';
    const ERROR_QUERY = 'Sql error';
    const ERROR_STATEMENT = 'Sql error';
    // phpcs:enable

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

    public function testPDOConstructOk()
    {
        $traces = $this->isolateTracer(function () {
                $this->pdoInstance();
        });
        $this->assertSpans($traces, [
            SpanAssertion::build('PDO.__construct', 'PDO', 'sql', 'PDO.__construct')
                ->withExactTags($this->baseTags()),
        ], static::IS_SANDBOX);
    }

    public function testPDOConstructError()
    {
        $traces = $this->isolateTracer(function () {
            try {
                new \PDO($this->mysqlDns(), 'wrong_user', 'wrong_password');
            } catch (\PDOException $ex) {
            }
        });
        $this->assertSpans($traces, [
            SpanAssertion::build('PDO.__construct', 'PDO', 'sql', 'PDO.__construct')
                ->withExactTags(array_merge($this->baseTags(), [
                    'db.user' => 'wrong_user',
                ]))
                ->setError('PDOException', static::ERROR_CONSTRUCT, true),
        ]);
    }

    public function testPDOExecOk()
    {
        $query = "INSERT INTO tests (id, name) VALUES (1000, 'Sam')";
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
                ->withExactTags($this->baseTags()),
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
                ->setError('PDOException', static::ERROR_EXEC, true)
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
        ], static::IS_SANDBOX);
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
                ->withExactTags($this->baseTags()),
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
                ->setError('PDOException', static::ERROR_QUERY, true)
                ->withExactTags($this->baseTags()),
        ]);
    }

    public function testPDOCommit()
    {
        $query = "INSERT INTO tests (id, name) VALUES (1000, 'Sam')";
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
                ->withExactTags($this->baseTags()),
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
            )->withExactTags($this->baseTags()),
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

    public function testPDOStatementIsCorrectlyClosedOnUnset()
    {
        $query = "SELECT * FROM tests WHERE id > ?";
        $pdo = $this->ensureActiveQueriesErrorCanHappen();
        $this->isolateTracer(function () use ($query, $pdo) {
            $stmt = $pdo->prepare($query);
            $stmt->execute([10]);
            $stmt->fetch();
            unset($stmt);

            $stmt2 = $pdo->prepare($query);
            $stmt2->execute([10]);
            $stmt2->fetch();
        });
        $this->addToAssertionCount(1);
    }

    public function testPDOStatementCausesActiveQueriesError()
    {
        $query = "SELECT * FROM tests WHERE id > ?";
        $pdo = $this->ensureActiveQueriesErrorCanHappen();
        try {
            $this->isolateTracer(function () use ($query, $pdo) {
                $stmt = $pdo->prepare($query);
                $stmt->execute([10]);
                $stmt->fetch();

                $stmt2 = $pdo->prepare($query);
                $stmt2->execute([10]);
                $stmt2->fetch();
            });

            $this->fail("Expected exception PDOException not thrown");
        } catch (\PDOException $ex) {
            $this->addToAssertionCount(1);
        }
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
                ->withExactTags($this->baseTags()),
            SpanAssertion::build('PDOStatement.execute', 'PDO', 'sql', "WRONG QUERY")
                ->settraceanalyticscandidate()
                ->seterror('PDOStatement error', 'SQL error: 42000. Driver error: 1064')
                    ->withExactTags($this->baseTags()),
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
                ->withExactTags($this->baseTags()),
            SpanAssertion::build('PDOStatement.execute', 'PDO', 'sql', "WRONG QUERY")
                ->setTraceAnalyticsCandidate()
                ->setError('PDOException', static::ERROR_STATEMENT, true)
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

    private function pdoInstance($opts = null)
    {
        $instance =  new \PDO($this->mysqlDns(), self::MYSQL_USER, self::MYSQL_PASSWORD, $opts);

        return $instance;
    }

    private function ensureActiveQueriesErrorCanHappen()
    {
        $opts = array(
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false
        );

        $pdo = $this->pdoInstance($opts);

        $this->isolateTracer(function () use ($pdo) {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO tests (name) VALUES (?)");

            for ($i = 0; $i < 1000; $i++) {
                $stmt->execute(['Jerry']);
            }
            $pdo->commit();
        });
        return $pdo;
    }

    private function setUpDatabase()
    {
        $this->isolateTracer(function () {
            $pdo = $this->pdoInstance();
            $pdo->beginTransaction();
            $pdo->exec("
                CREATE TABLE tests (
                    id integer not null primary key AUTO_INCREMENT,
                    name varchar(100)
                )
            ");
            $pdo->exec("INSERT INTO tests (id, name) VALUES (1, 'Tom')");

            $pdo->commit();
            $pdo = null;
        });
    }

    private function clearDatabase()
    {
        $this->isolateTracer(function () {
            $pdo = $this->pdoInstance();
            $pdo->beginTransaction();
            $pdo->exec("DROP TABLE tests");
            $pdo->commit();
            $pdo = null;
        });
    }

    public function mysqlDns()
    {
        return "mysql:host=" . self::MYSQL_HOST . ";dbname=" . self::MYSQL_DATABASE;
    }

    protected function baseTags()
    {
        return [
            'db.engine' => 'mysql',
            'out.host' => self::MYSQL_HOST,
            'db.name' => self::MYSQL_DATABASE,
            'db.user' => self::MYSQL_USER,
        ];
    }
}
