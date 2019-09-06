<?php

namespace DDTrace\Tests\Integrations\PDO;

use DDTrace\Integrations\IntegrationsLoader;
use DDTrace\Tests\Common\IntegrationTestCase;

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
        IntegrationTestCase::setUpBeforeClass();
        putenv('DD_TRACE_SANDBOX_ENABLED=true');
        putenv('DD_PDO_ANALYTICS_ENABLED=true');
        IntegrationsLoader::reload();
    }

    public static function tearDownAfterClass()
    {
        parent::tearDownAfterClass();
        putenv('DD_PDO_ANALYTICS_ENABLED');
        putenv('DD_TRACE_SANDBOX_ENABLED');
    }

    protected function setUp()
    {
        if (PHP_VERSION_ID < 50600) {
            $this->markTestSkipped('Sandbox API not available on < PHP 5.6');
            return;
        }
        parent::setUp();
    }

    // Remove this fake test after limited tracer added to sandbox API
    public function testLimitedTracerPDO()
    {
        $this->assertTrue(true);
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
