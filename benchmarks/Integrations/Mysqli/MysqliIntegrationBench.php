<?php

use DDTrace\DebugTracer;
use DDTrace\Integrations\Mysqli\MysqliIntegration;

/**
 * @BeforeClassMethods({"initDatabase"})
 * @AfterClassMethods({"dropDatabase"})
 * @BeforeMethods({"initConnection", "initTracer"})
 * @AfterMethods({"flushTracer"})
 */
class MysqliIntegrationBench
{
    use DebugTracer;

    const MYSQL_DATABASE = 'test';
    const MYSQL_USER = 'test';
    const MYSQL_PASSWORD = 'test';
    const MYSQL_HOST = 'mysql_integration';

    /** @var \mysqli */
    private $mysqli;

    public static function initDatabase()
    {
        $mysqli = self::newConnection();
        $mysqli->begin_transaction();
        $mysqli->query("
                CREATE TABLE tests (
                    id integer not null primary key,
                    name varchar(100)
                )
            ");
        $mysqli->query("INSERT INTO tests (id, name) VALUES (1, 'Pam')");
        $mysqli->query("INSERT INTO tests (id, name) VALUES (2, 'Jim')");
        $mysqli->query("INSERT INTO tests (id, name) VALUES (3, 'Dwight')");
        $mysqli->query("INSERT INTO tests (id, name) VALUES (4, 'Creed')");
        $mysqli->commit();
    }

    public static function dropDatabase()
    {
        self::newConnection()->query("DROP TABLE tests");
    }

    public function initConnection()
    {
        $this->mysqli = self::newConnection();
    }

    private static function newConnection()
    {
        return new \mysqli(
            self::MYSQL_HOST,
            self::MYSQL_USER,
            self::MYSQL_PASSWORD,
            self::MYSQL_DATABASE
        );
    }

    public function benchBaseline()
    {
        $this->doDatabaseStuff();
    }

    public function benchWithTracing()
    {
        MysqliIntegration::load();
        $this->doDatabaseStuff();
    }

    private function doDatabaseStuff()
    {
        $this->mysqli->query("INSERT INTO tests (name) VALUES ('Kelly')");
        foreach (range(1, 4) as $id) {
            $statement = $this->mysqli->prepare('SELECT * FROM tests WHERE id = ?');
            $statement->bind_param('i', $id);
            $statement->execute();
            $statement->store_result();
        }
    }
}
