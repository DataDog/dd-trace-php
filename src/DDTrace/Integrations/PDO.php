<?php

namespace DDTrace\Integrations;

use DDTrace\Tags;
use DDTrace\Types;
use OpenTracing\GlobalTracer;

class PDO
{
    private static $connections = [];
    private static $statements = [];

    /**
     * Static method to add instrumentation to PDO requests
     */
    public static function load()
    {
        if (!extension_loaded('ddtrace')) {
            trigger_error('ddtrace extension required to load PDO integration.', E_USER_WARNING);
            return;
        }

        // public PDO::__construct ( string $dsn [, string $username [, string $passwd [, array $options ]]] )
        dd_trace('PDO', '__construct', function (...$args) {
            $scope = GlobalTracer::get()->startActiveSpan('PDO.__construct');
            $span = $scope->getSpan();
            $span->setTag(Tags\SPAN_TYPE, Types\SQL);
            $span->setTag(Tags\SERVICE_NAME, 'PDO');
            $span->setResource('PDO.__construct');

            try {
                $this->__construct(...$args);
                PDO::storeConnectionParams($this, $args);
                return $this;
            } catch (\Exception $e) {
                $span->setError($e);
                throw $e;
            } finally {
                $scope->close();
            }
        });

        // public int PDO::exec(string $query)
        dd_trace('PDO', 'exec', function ($statement) {
            $scope = GlobalTracer::get()->startActiveSpan('PDO.exec');
            $span = $scope->getSpan();
            $span->setTag(Tags\SPAN_TYPE, Types\SQL);
            $span->setTag(Tags\SERVICE_NAME, 'PDO');
            $span->setResource($statement);
            PDO::setConnectionTags($this, $span);

            try {
                $result = $this->exec($statement);
                $span->setTag('db.rowcount', $result);
                return $result;
            } catch (\Exception $e) {
                $span->setError($e);
                throw $e;
            } finally {
                $scope->close();
            }
        });

        // public PDOStatement PDO::query(string $query)
        // public PDOStatement PDO::query(string $query, int PDO::FETCH_COLUMN, int $colno)
        // public PDOStatement PDO::query(string $query, int PDO::FETCH_CLASS, string $classname, array $ctorargs)
        // public PDOStatement PDO::query(string $query, int PDO::FETCH_INFO, object $object)
        // public int PDO::exec(string $query)
        dd_trace('PDO', 'query', function (...$args) {
            $scope = GlobalTracer::get()->startActiveSpan('PDO.query');
            $span = $scope->getSpan();
            $span->setTag(Tags\SPAN_TYPE, Types\SQL);
            $span->setTag(Tags\SERVICE_NAME, 'PDO');
            $span->setResource($args[0]);
            PDO::setConnectionTags($this, $span);

            try {
                $result = $this->query(...$args[0]);
                PDO::storeStatementFromConnection($this, $result);
                try {
                    $span->setTag('db.rowcount', $result->rowCount());
                } catch (\Exception $e) {
                }
                return $result;
            } catch (\Exception $e) {
                $span->setError($e);
                throw $e;
            } finally {
                $scope->close();
            }
        });

        // public bool PDO::commit ( void )
        dd_trace('PDO', 'commit', function () {
            $scope = GlobalTracer::get()->startActiveSpan('PDO.commit');
            $span = $scope->getSpan();
            $span->setTag(Tags\SPAN_TYPE, Types\SQL);
            $span->setTag(Tags\SERVICE_NAME, 'PDO');
            PDO::setConnectionTags($this, $span);

            try {
                return $this->commit();
            } catch (\Exception $e) {
                $span->setError($e);
                throw $e;
            } finally {
                $scope->close();
            }
        });

        // public PDOStatement PDO::prepare ( string $statement [, array $driver_options = array() ] )
        dd_trace('PDO', 'prepare', function (...$args) {
            $scope = GlobalTracer::get()->startActiveSpan('PDO.prepare');
            $span = $scope->getSpan();
            $span->setTag(Tags\SPAN_TYPE, Types\SQL);
            $span->setTag(Tags\SERVICE_NAME, 'PDO');
            $span->setResource($args[0]);
            PDO::setConnectionTags($this, $span);

            try {
                $result = $this->prepare(...$args);
                PDO::storeStatementFromConnection($this, $result);
                return $result;
            } catch (\Exception $e) {
                $span->setError($e);
                throw $e;
            } finally {
                $scope->close();
            }
        });

        // public bool PDOStatement::execute ([ array $input_parameters ] )
        dd_trace('PDOStatement', 'execute', function ($params) {
            $scope = GlobalTracer::get()->startActiveSpan('PDOStatement.execute');
            $span = $scope->getSpan();
            $span->setTag(Tags\SPAN_TYPE, Types\SQL);
            $span->setTag(Tags\SERVICE_NAME, 'PDO');
            $span->setResource($this->queryString);
            PDO::setStatementTags($this, $span);

            try {
                $result = $this->execute($params);
                try {
                    $span->setTag('db.rowcount', $this->rowCount());
                } catch (\Exception $e) {
                }
                return $result;
            } catch (\Exception $e) {
                $span->setError($e);
                throw $e;
            } finally {
                $scope->close();
            }
        });
    }

    private static function parseDsn($dsn)
    {
        $engine = substr($dsn, 0, strpos($dsn, ':'));
        $tags = ['db.engine' => $engine];
        $valStrings = explode(';', substr($dsn, strlen($engine) + 1));
        foreach ($valStrings as $valString) {
            if (!strpos($valString, '=')) {
                continue;
            }
            list($key, $value) = explode('=', $valString);
            switch ($key) {
                case 'charset':
                    $tags['db.charset'] = $value;
                    break;
                case 'dbname':
                    $tags['db.name'] = $value;
                    break;
                case 'host':
                    $tags[Tags\TARGET_HOST] = $value;
                    break;
                case 'port':
                    $tags[Tags\TARGET_PORT] = $value;
                    break;
            }
        }

        return $tags;
    }

    public static function storeConnectionParams($pdo, array $constructorArgs)
    {
        $tags = self::parseDsn($constructorArgs[0]);
        if (isset($constructorArgs[1])) {
            $tags['db.user'] = $constructorArgs[1];
        }
        self::$connections[spl_object_hash($pdo)] = $tags;
    }

    public static function storeStatementFromConnection($pdo, $stmt)
    {
        $pdoHash = spl_object_hash($pdo);
        if (isset(self::$connections[$pdoHash])) {
            self::$statements[spl_object_hash($stmt)] = $pdoHash;
        }
    }

    public static function setConnectionTags($pdo, $span)
    {
        $hash = spl_object_hash($pdo);
        if (!isset(self::$connections[$hash])) {
            return;
        }

        foreach (self::$connections[$hash] as $tag => $value) {
            $span->setTag($tag, $value);
        }
    }

    public static function setStatementTags($stmt, $span)
    {
        $stmtHash = spl_object_hash($stmt);
        if (!isset(self::$statements[$stmtHash])) {
            return;
        }
        if (!isset(self::$connections[self::$statements[$stmtHash]])) {
            return;
        }

        foreach (self::$connections[self::$statements[$stmtHash]] as $tag => $value) {
            $span->setTag($tag, $value);
        }
    }
}
