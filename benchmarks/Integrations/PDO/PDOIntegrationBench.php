<?php

use DDTrace\DebugTracer;
use DDTrace\Integrations\PDO\PDOIntegration;

/**
 * @BeforeClassMethods({"initDatabase"})
 * @AfterClassMethods({"dropDatabase"})
 * @BeforeMethods({"initConnection", "initTracer"})
 * @AfterMethods({"flushTracer"})
 */
class PDOIntegrationBench
{
    use DebugTracer;

    const MYSQL_DATABASE = 'test';
    const MYSQL_USER = 'test';
    const MYSQL_PASSWORD = 'test';
    const MYSQL_HOST = 'mysql_integration';

    /** @var \PDO */
    private $pdo;

    public static function initDatabase()
    {
        $pdo = self::newConnection();
        $pdo->beginTransaction();
        $pdo->exec("
                CREATE TABLE tests (
                    id integer not null primary key,
                    name varchar(100)
                )
            ");
        $pdo->exec("INSERT INTO tests (id, name) VALUES (1, 'Pam')");
        $pdo->exec("INSERT INTO tests (id, name) VALUES (2, 'Jim')");
        $pdo->exec("INSERT INTO tests (id, name) VALUES (3, 'Dwight')");
        $pdo->exec("INSERT INTO tests (id, name) VALUES (4, 'Creed')");
        $pdo->commit();
    }

    public static function dropDatabase()
    {
        $pdo = self::newConnection();
        $pdo->exec("DROP TABLE tests");
    }

    public function initConnection()
    {
        $this->pdo = self::newConnection();
    }

    private static function newConnection()
    {
        return new \PDO(
            'mysql:host='.self::MYSQL_HOST.';dbname='.self::MYSQL_DATABASE,
            self::MYSQL_USER,
            self::MYSQL_PASSWORD
        );
    }

    public function benchBaseline()
    {
        $this->doDatabaseStuff();
    }

    public function benchWithTracing()
    {
        PDOIntegration::load();
        $this->doDatabaseStuff();
    }

    private function doDatabaseStuff()
    {
        $this->pdo->exec("INSERT INTO tests (name) VALUES ('Kelly')");
        foreach (range(1, 4) as $id) {
            $statement = $this->pdo->prepare('SELECT * FROM tests WHERE id = ?');
            $statement->execute([$id]);
        }
    }
}
