<?php

namespace DDTrace\Tests\Integrations\PDO;

use DDTrace\Tests\Common\SpanAssertion;

final class PDOSandboxedTest extends PDOTest
{
    const IS_SANDBOX = true;

    // phpcs:disable
    const ERROR_CONSTRUCT = 'SQLSTATE[HY000] [1045] Access denied for user \'wrong_user\'@\'%s\' (using password: YES)';
    const ERROR_EXEC = 'SQLSTATE[42000]: Syntax error or access violation: 1064 You have an error in your SQL syntax; check the manual that corresponds to your MySQL server version for the right syntax to use near \'WRONG QUERY)\' at line 1';
    const ERROR_QUERY = 'SQLSTATE[42000]: Syntax error or access violation: 1064 You have an error in your SQL syntax; check the manual that corresponds to your MySQL server version for the right syntax to use near \'WRONG QUERY\' at line 1';
    const ERROR_STATEMENT = 'SQLSTATE[42000]: Syntax error or access violation: 1064 You have an error in your SQL syntax; check the manual that corresponds to your MySQL server version for the right syntax to use near \'WRONG QUERY\' at line 1';
    // phpcs:enable

    public static function setUpBeforeClass()
    {
        putenv('DD_PDO_ANALYTICS_ENABLED=true');
        parent::setUpBeforeClass();
    }

    public static function tearDownAfterClass()
    {
        parent::tearDownAfterClass();
        putenv('DD_PDO_ANALYTICS_ENABLED');
    }

    protected function setUp()
    {
        if (PHP_VERSION_ID < 50600) {
            $this->markTestSkipped('Sandbox API not available on < PHP 5.6');
            return;
        }
        parent::setUp();
    }

    public function testCustomPDOPrepareWithStringableStatement()
    {
        $query = "SELECT * FROM tests WHERE id = ?";
        $traces = $this->isolateTracer(function () use ($query) {
            $pdo = new CustomPDO($this->mysqlDns(), self::MYSQL_USER, self::MYSQL_PASSWORD);
            $stmt = $pdo->prepare(new CustomPDOStatement($query));
            $stmt->execute([1]);
            $results = $stmt->fetchAll();
            $this->assertEquals('Tom', $results[0]['name']);
            $stmt->closeCursor();
        });
        $this->assertSpans($traces, [
            SpanAssertion::exists('PDO.__construct'),
            SpanAssertion::build(
                'PDO.prepare',
                'PDO',
                'sql',
                $query
            )->withExactTags($this->baseTags()),
            SpanAssertion::build(
                'PDOStatement.execute',
                'PDO',
                'sql',
                $query
            )
                ->setTraceAnalyticsCandidate()
                ->withExactTags(array_merge($this->baseTags(), [
                    'db.rowcount' => 1,
                ])),
        ]);
    }

    public function testBrokenPDOPrepareWithNonStringableStatement()
    {
        if (PHP_VERSION_ID < 70200) {
            $this->markTestSkipped('Test relies on spl_object_id() which was added in PHP 7.2');
            return;
        }
        $query = "SELECT * FROM tests WHERE id = ?";
        $objId = 0;
        $traces = $this->isolateTracer(function () use ($query, &$objId) {
            $pdo = new CustomPDO($this->mysqlDns(), self::MYSQL_USER, self::MYSQL_PASSWORD);
            $brokenStatement = new BrokenPDOStatement($query);
            $objId = spl_object_id($brokenStatement);
            $stmt = $pdo->prepare($brokenStatement);
            $stmt->execute([1]);
            $results = $stmt->fetchAll();
            $this->assertEquals('Tom', $results[0]['name']);
            $stmt->closeCursor();
        });
        $this->assertSpans($traces, [
            SpanAssertion::exists('PDO.__construct'),
            SpanAssertion::build(
                'PDO.prepare',
                'PDO',
                'sql',
                'object(DDTrace\Tests\Integrations\PDO\BrokenPDOStatement)#' . $objId
            )->withExactTags(SpanAssertion::NOT_TESTED),
            SpanAssertion::build(
                'PDOStatement.execute',
                'PDO',
                'sql',
                $query
            )
                ->setTraceAnalyticsCandidate()
                ->withExactTags(SpanAssertion::NOT_TESTED),
        ]);
    }

    // @see https://github.com/DataDog/dd-trace-php/issues/510
    public function testPDOStatementsAreReleased()
    {
        $query = "SELECT * FROM tests WHERE id = ?";
        $pdo = new \PDO(
            $this->mysqlDns(),
            self::MYSQL_USER,
            self::MYSQL_PASSWORD,
            [\PDO::ATTR_EMULATE_PREPARES => false]
        );
        $stmt = $pdo->prepare($query);
        $stmt->execute([1]);
        unset($stmt);

        $pdo->prepare($query)->execute([2]);

        $closedCount = $pdo->query("SHOW SESSION STATUS LIKE 'Com_stmt_close'")->fetchColumn(1);

        $this->assertEquals(2, $closedCount);
    }
}
