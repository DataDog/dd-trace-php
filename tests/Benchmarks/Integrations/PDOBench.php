<?php

declare(strict_types=1);

namespace Benchmarks\Integrations;

use DDTrace\Tests\Common\Utils;

/**
 * @BeforeClassMethods({"setUp"})
 * @AfterClassMethods({"tearDown"})
 */
class PDOBench
{
    const MYSQL_DATABASE = 'test';
    const MYSQL_USER = 'test';
    const MYSQL_PASSWORD = 'test';
    const MYSQL_HOST = 'mysql-integration';

    public $pdo;

    /**
     * @BeforeMethods({"disablePDOIntegration"})
     * @Revs(100)
     * @Iterations(15)
     * @OutputTimeUnit("microseconds")
     * @RetryThreshold(10.0)
     * @Warmup(1)
     */
    public function benchPDOBaseline()
    {
        $this->PDOScenario();
    }

    /**
     * @BeforeMethods({"enablePDOIntegration"})
     * @Revs(100)
     * @Iterations(15)
     * @OutputTimeUnit("microseconds")
     * @RetryThreshold(10.0)
     * @Warmup(1)
     */
    public function benchPDOOverhead()
    {
        $this->PDOScenario();
    }

    /**
     * @BeforeMethods({"enablePDOIntegrationWithDBM"})
     * @Revs(100)
     * @Iterations(15)
     * @OutputTimeUnit("microseconds")
     * @RetryThreshold(10.0)
     * @Warmup(1)
     */
    public function benchPDOOverheadWithDBM()
    {
        $this->PDOScenario();
    }

    public static function setUp()
    {
        self::setUpDatabase();
    }

    public static function tearDown()
    {
        self::clearDatabase();
    }

    public function PDOScenario()
    {
        $this->pdo->beginTransaction();
        $stmt = $this->pdo->prepare("SELECT * FROM tests WHERE id = ?");
        $stmt->execute([1]);
        $this->pdo->commit();
    }

    public function disablePDOIntegration()
    {
        \dd_trace_serialize_closed_spans();
        Utils::putEnvAndReloadConfig([
            'DD_TRACE_ENABLED=0'
        ]);
        $this->pdo = $this->pdoInstance();
    }

    public function enablePDOIntegration()
    {
        \dd_trace_serialize_closed_spans();
        Utils::putEnvAndReloadConfig([
            'DD_TRACE_ENABLED=1'
        ]);
        $this->pdo = $this->pdoInstance();
    }

    public function enablePDOIntegrationWithDBM()
    {
        \dd_trace_serialize_closed_spans();
        Utils::putEnvAndReloadConfig([
            'DD_TRACE_ENABLED=1',
            'DD_DBM_PROPAGATION_MODE=full'
        ]);
        $this->pdo = $this->pdoInstance();
    }


    private static function mysqlDns(): string
    {
        return "mysql:host=" . self::MYSQL_HOST . ";dbname=" . self::MYSQL_DATABASE;
    }

    private static function pdoInstance($opts = null): \PDO
    {
        // The default error mode is PDO::ERRMODE_SILENT on PHP < 8
        if (!isset($opts[\PDO::ATTR_ERRMODE])) {
            $opts[\PDO::ATTR_ERRMODE] = \PDO::ERRMODE_SILENT;
        }
        return new \PDO(self::mysqlDns(), self::MYSQL_USER, self::MYSQL_PASSWORD, $opts);
    }

    private static function setUpDatabase()
    {
        $pdo = self::pdoInstance();
        $pdo->beginTransaction();
        $pdo->exec("
                CREATE TABLE tests (
                    id integer not null primary key AUTO_INCREMENT,
                    name varchar(100)
                )
            ");
        if (PHP_VERSION_ID >= 80000 && !$pdo->inTransaction()) {
            // CREATE TABLE causes an implicit commit on PHP 8
            // @see https://dev.mysql.com/doc/refman/8.0/en/implicit-commit.html
            $pdo->beginTransaction();
        }
        $pdo->exec("INSERT INTO tests (id, name) VALUES (1, 'Tom')");

        $pdo->commit();
        $pdo = null;
    }

    private static function clearDatabase()
    {
        $pdo = self::pdoInstance();
        $pdo->beginTransaction();
        $pdo->exec("DROP TABLE tests");
        if (PHP_VERSION_ID < 80000) {
            // DROP TABLE causes an implicit commit on PHP 8
            // @see https://dev.mysql.com/doc/refman/8.0/en/implicit-commit.html
            $pdo->commit();
        }
        $pdo = null;
    }
}
