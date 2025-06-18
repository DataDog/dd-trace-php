<?php

declare(strict_types=1);

namespace Benchmarks\Integrations;

use DDTrace\Tests\Common\Utils;

/**
 * @BeforeClassMethods({"setUpDatabase"})
 * @AfterClassMethods({"tearDownDatabase"})
 */
class PDOBench
{
    const MYSQL_DATABASE = 'test';
    const MYSQL_USER = 'test';
    const MYSQL_PASSWORD = 'test';
    const MYSQL_HOST = 'mysql-integration';

    public static $sharedPdo;
    public static $sharedStmt;

    /**
     * @BeforeMethods({"initSharedPdoAndStmt","disablePDOIntegration"})
     * @Revs(1000)
     * @Iterations(20)
     * @OutputTimeUnit("microseconds")
     * @RetryThreshold(5.0)
     * @Warmup(2)
     */
    public function benchPDOBaseline()
    {
        $this->PDOScenario();
    }

    /**
     * @BeforeMethods({"initSharedPdoAndStmt", "enablePDOIntegration"})
     * @Revs(1000)
     * @Iterations(20)
     * @OutputTimeUnit("microseconds")
     * @RetryThreshold(5.0)
     * @Warmup(2)
     */
    public function benchPDOOverhead()
    {
        $this->PDOScenario();
    }

    /**
     * @BeforeMethods({"initSharedPdoAndStmt", "enablePDOIntegrationWithDBM"})
     * @Revs(1000)
     * @Iterations(20)
     * @OutputTimeUnit("microseconds")
     * @RetryThreshold(5.0)
     * @Warmup(2)
     */
    public function benchPDOOverheadWithDBM()
    {
        $this->PDOScenario();
    }

    public static function setUpDatabase()
    {
        try {
            $pdo = self::pdoInstance();
            // Drop table if it exists (cleanup from previous failed runs)
            $pdo->exec("DROP TABLE IF EXISTS tests");
            
            // Create table
            $pdo->exec("
                CREATE TABLE tests (
                    id integer not null primary key AUTO_INCREMENT,
                    name varchar(100)
                ) ENGINE=MEMORY
            ");
            
            // Insert test data
            $pdo->exec("INSERT INTO tests (id, name) VALUES (1, 'Tom')");
        } catch (\Exception $e) {
            throw new \RuntimeException("Database setup failed: " . $e->getMessage(), 0, $e);
        }
    }

    public static function tearDownDatabase()
    {
        try {
            $pdo = self::pdoInstance();
            $pdo->exec("DROP TABLE IF EXISTS tests");
        } catch (\Exception $e) {
            // Ignore cleanup errors
        }
    }

    public function initSharedPdoAndStmt()
    {
        if (!self::$sharedPdo) {
            self::$sharedPdo = self::pdoInstance();
            if (!self::$sharedPdo) {
                throw new \RuntimeException("Failed to create PDO instance");
            }
        }
        
        if (!self::$sharedStmt) {
            self::$sharedStmt = self::$sharedPdo->prepare("SELECT * FROM tests WHERE id = ?");
            if (!self::$sharedStmt) {
                throw new \RuntimeException("Failed to prepare statement");
            }
            
            // Warm up the statement with a few executions
            for ($i = 0; $i < 10; $i++) {
                self::$sharedStmt->execute([1]);
                self::$sharedStmt->fetch();
            }
        }
    }

    public function PDOScenario()
    {
        self::$sharedStmt->execute([1]);
        self::$sharedStmt->fetch();
    }

    public function disablePDOIntegration()
    {
        \dd_trace_serialize_closed_spans();
        Utils::putEnvAndReloadConfig([
            'DD_TRACE_ENABLED=0'
        ]);
    }

    public function enablePDOIntegration()
    {
        \dd_trace_serialize_closed_spans();
        Utils::putEnvAndReloadConfig([
            'DD_TRACE_ENABLED=1'
        ]);
    }

    public function enablePDOIntegrationWithDBM()
    {
        \dd_trace_serialize_closed_spans();
        Utils::putEnvAndReloadConfig([
            'DD_TRACE_ENABLED=1',
            'DD_DBM_PROPAGATION_MODE=full'
        ]);
    }

    private static function mysqlDns(): string
    {
        return "mysql:host=" . self::MYSQL_HOST . ";dbname=" . self::MYSQL_DATABASE;
    }

    private static function pdoInstance($opts = null): \PDO
    {
        $defaultOpts = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, // Use exceptions for better error messages
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false, // Use real prepared statements
            \PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true, // Consistent buffering
        ];
        
        if ($opts) {
            $opts = array_merge($defaultOpts, $opts);
        } else {
            $opts = $defaultOpts;
        }
        
        return new \PDO(self::mysqlDns(), self::MYSQL_USER, self::MYSQL_PASSWORD, $opts);
    }
}
