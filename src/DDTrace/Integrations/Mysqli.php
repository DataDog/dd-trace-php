<?php

namespace DDTrace\Integrations;

use DDTrace\Span;
use DDTrace\Tags;
use DDTrace\Types;
use DDTrace\Util\ObjectKVStore;
use OpenTracing\GlobalTracer;

class Mysqli
{
    public static function load()
    {
        if (!extension_loaded('ddtrace')) {
            trigger_error('ddtrace extension required to load mysqli integrations.', E_USER_WARNING);
            return;
        }
        if (!extension_loaded('mysqli')) {
            trigger_error('mysqli is not loaded and cannot be instrumented', E_USER_WARNING);
            return;
        }

        // mysqli mysqli_connect ([ string $host = ini_get("mysqli.default_host")
        //      [, string $username = ini_get("mysqli.default_user")
        //      [, string $passwd = ini_get("mysqli.default_pw")
        //      [, string $dbname = ""
        //      [, int $port = ini_get("mysqli.default_port")
        //      [, string $socket = ini_get("mysqli.default_socket") ]]]]]] )
        dd_trace('mysqli_connect', function () {
            $args = func_get_args();
            $scope = Mysqli::initScope('mysqli_connect', 'mysqli_connect');
            $span = $scope->getSpan();

            try {
                // Depending on configuration, connections errors can both cause an exception and return false
                $result = mysqli_connect(...$args);
                if ($result === false) {
                    $span->setError(new \Exception(mysqli_connect_error(), mysqli_connect_errno()));
                } else {
                    Mysqli::setConnectionInfo($span, $result);
                }
                $scope->close();
            } catch (\Exception $ex) {
                $span->setError($ex);
                $scope->close();
                throw $ex;
            }


            return $result;
        });

        // public mysqli mysqli::__construct ([ string $host = ini_get("mysqli.default_host")
        //      [, string $username = ini_get("mysqli.default_user")
        //      [, string $passwd = ini_get("mysqli.default_pw")
        //      [, string $dbname = ""
        //      [, int $port = ini_get("mysqli.default_port")
        //      [, string $socket = ini_get("mysqli.default_socket") ]]]]]] )
        $mysqli_constructor = PHP_MAJOR_VERSION > 5 ? '__construct' : 'mysqli';
        dd_trace('mysqli', $mysqli_constructor, function () use ($mysqli_constructor) {
            $args = func_get_args();
            $scope = Mysqli::initScope('mysqli.__construct', 'mysqli.__construct');
            /** @var \DDTrace\Span $span */
            $span = $scope->getSpan();

            try {
                $this->$mysqli_constructor(...$args);
                //Mysqli::storeConnectionParams($this, $args);
                if (mysqli_connect_errno()) {
                    $span->setError(new \Exception(mysqli_connect_error(), mysqli_connect_errno()));
                } else {
                    Mysqli::setConnectionInfo($span, $this);
                }
                return $this;
            } catch (\Exception $e) {
                $span->setError($e);
                throw $e;
            } finally {
                $scope->close();
            }
        });

        // mixed mysqli_query ( mysqli $link , string $query [, int $resultmode = MYSQLI_STORE_RESULT ] )
        dd_trace('mysqli_query', function () {
            $args = func_get_args();
            $mysqli = $args[0];
            $query = $args[1];

            $scope = Mysqli::initScope('mysqli_query', $query);
            /** @var \DDTrace\Span $span */
            $span = $scope->getSpan();
            Mysqli::setConnectionInfo($span, $mysqli);
            Mysqli::storeQuery($mysqli, $query);

            $result = mysqli_query(...$args);
            Mysqli::storeQuery($result, $query);
            ObjectKVStore::put($result, 'host_info', Mysqli::extractHostInfo($mysqli));

            $scope->close();

            return $result;
        });

        // mysqli_stmt mysqli_prepare ( mysqli $link , string $query )
        dd_trace('mysqli_prepare', function ($mysqli, $query) {
            $scope = Mysqli::initScope('mysqli_prepare', $query);
            /** @var \DDTrace\Span $span */
            $span = $scope->getSpan();
            Mysqli::setConnectionInfo($span, $mysqli);

            $statement = mysqli_prepare($mysqli, $query);
            Mysqli::storeQuery($statement, $query);
            $host_info = Mysqli::extractHostInfo($mysqli);
            ObjectKVStore::put($statement, 'host_info', $host_info);

            $scope->close();

            return $statement;
        });

        // bool mysqli_commit ( mysqli $link [, int $flags [, string $name ]] )
        dd_trace('mysqli_commit', function () {
            $args = func_get_args();
            $mysqli = $args[0];
            $resource = Mysqli::retrieveQuery($mysqli, 'mysqli_commit');
            $scope = Mysqli::initScope('mysqli_commit', $resource);
            /** @var \DDTrace\Span $span */
            $span = $scope->getSpan();
            Mysqli::setConnectionInfo($span, $mysqli);

            if (isset($args[2])) {
                $span->setTag('db.transaction_name', $args[2]);
            }

            $result = mysqli_commit(...$args);

            $scope->close();

            return $result;
        });

        // bool mysqli_stmt_execute ( mysqli_stmt $stmt )
        dd_trace('mysqli_stmt_execute', function ($stmt) {
            $resource = Mysqli::retrieveQuery($stmt, 'mysqli_stmt_execute');
            $scope = Mysqli::initScope('mysqli_stmt_execute', $resource);

            $result = mysqli_stmt_execute($stmt);

            $scope->close();

            return $result;
        });

        // bool mysqli_stmt_get_result ( mysqli_stmt $stmt )
        dd_trace('mysqli_stmt_get_result', function ($stmt) {
            $resource = Mysqli::retrieveQuery($stmt, 'mysqli_stmt_get_result');
            $result = mysqli_stmt_get_result($stmt);

            Mysqli::storeQuery($result, $resource);
            ObjectKVStore::propagate($stmt, $result, 'host_info');

            return $result;
        });

        // mixed mysqli::query ( string $query [, int $resultmode = MYSQLI_STORE_RESULT ] )
        dd_trace('mysqli', 'query', function () {
            $args = func_get_args();
            $query = $args[0];
            $scope = Mysqli::initScope('mysqli.query', $query);
            /** @var \DDTrace\Span $span */
            $span = $scope->getSpan();
            Mysqli::setConnectionInfo($span, $this);
            Mysqli::storeQuery($this, $query);

            try {
                $result = $this->query(...$args);
                $host_info = Mysqli::extractHostInfo($this);
                ObjectKVStore::put($result, 'host_info', $host_info);
                ObjectKVStore::put($result, 'query', $query);
                return $result;
            } catch (\Exception $e) {
                $span->setError($e);
                throw $e;
            } finally {
                $scope->close();
            }
        });

        // mysqli_stmt mysqli::prepare ( string $query )
        dd_trace('mysqli', 'prepare', function ($query) {
            $scope = Mysqli::initScope('mysqli.prepare', $query);
            /** @var \DDTrace\Span $span */
            $span = $scope->getSpan();
            Mysqli::setConnectionInfo($span, $this);

            try {
                $statement = $this->prepare($query);
                $host_info = Mysqli::extractHostInfo($this);
                ObjectKVStore::put($statement, 'host_info', $host_info);
                Mysqli::storeQuery($statement, $query);
                return $statement;
            } catch (\Exception $e) {
                $span->setError($e);
                throw $e;
            } finally {
                $scope->close();
            }
        });

        // bool mysqli::commit ([ int $flags [, string $name ]] )
        dd_trace('mysqli', 'commit', function () {
            $args = func_get_args();
            $resource = Mysqli::retrieveQuery($this, 'mysqli.commit');
            $scope = Mysqli::initScope('mysqli.commit', $resource);
            /** @var \DDTrace\Span $span */
            $span = $scope->getSpan();
            Mysqli::setConnectionInfo($span, $this);

            if (isset($args[1])) {
                $span->setTag('db.transaction_name', $args[1]);
            }

            try {
                return $this->commit(...$args);
            } catch (\Exception $e) {
                $span->setError($e);
                throw $e;
            } finally {
                $scope->close();
            }
        });

        // bool mysqli_stmt::execute ( void )
        dd_trace('mysqli_stmt', 'execute', function () {
            $resource = Mysqli::retrieveQuery($this, 'mysqli_stmt.execute');
            $scope = Mysqli::initScope('mysqli_stmt.execute', $resource);
            /** @var \DDTrace\Span $span */
            $span = $scope->getSpan();

            try {
                return $this->execute();
            } catch (\Exception $e) {
                $span->setError($e);
                throw $e;
            } finally {
                $scope->close();
            }
        });

        // bool mysqli_stmt::execute ( void )
        dd_trace('mysqli_stmt', 'get_result', function () {
            $resource = Mysqli::retrieveQuery($this, 'mysqli_stmt.get_result');
            $scope = Mysqli::initScope('mysqli_stmt.get_result', $resource);
            /** @var \DDTrace\Span $span */
            $span = $scope->getSpan();

            try {
                $result = $this->get_result();
                ObjectKVStore::propagate($this, $result, 'host_info');
                ObjectKVStore::put($result, 'query', $resource);
                return $result;
            } catch (\Exception $e) {
                $span->setError($e);
                throw $e;
            } finally {
                $scope->close();
            }
        });

        // Procedural fetch methods
        Mysqli::traceProceduralFetchMethod('mysqli_fetch_all');
        Mysqli::traceProceduralFetchMethod('mysqli_fetch_array');
        Mysqli::traceProceduralFetchMethod('mysqli_fetch_assoc');
        Mysqli::traceProceduralFetchMethod('mysqli_fetch_field_direct');
        Mysqli::traceProceduralFetchMethod('mysqli_fetch_field');
        Mysqli::traceProceduralFetchMethod('mysqli_fetch_fields');
        Mysqli::traceProceduralFetchMethod('mysqli_fetch_object');
        Mysqli::traceProceduralFetchMethod('mysqli_fetch_row');

        // Constructor fetch methods
        Mysqli::traceConstructorFetchMethod('fetch_all');
        Mysqli::traceConstructorFetchMethod('fetch_array');
        Mysqli::traceConstructorFetchMethod('fetch_assoc');
        Mysqli::traceConstructorFetchMethod('fetch_field_direct');
        Mysqli::traceConstructorFetchMethod('fetch_field');
        Mysqli::traceConstructorFetchMethod('fetch_fields');
        Mysqli::traceConstructorFetchMethod('fetch_object');
        Mysqli::traceConstructorFetchMethod('fetch_row');
    }

    /**
     * Trace a generic fetch method in a constructor instance approach.
     *
     * @param string $methodName
     */
    private static function traceConstructorFetchMethod($methodName)
    {
        dd_trace('mysqli_result', $methodName, function() use ($methodName) {
            $operationName = 'mysqli_result' . '.' . $methodName;
            $args = func_get_args();
            $resource = Mysqli::retrieveQuery($this, $operationName);
            $scope = Mysqli::initScope($operationName, $resource);
            /** @var \DDTrace\Span $span */
            $span = $scope->getSpan();
            $host_info = ObjectKVStore::get($this, 'host_info', []);
            foreach ($host_info as $key => $value) {
                $span->setTag($key, $value);
            }

            try {
                return $this->$methodName(...$args);
            } catch (\Exception $e) {
                $span->setError($e);
                throw $e;
            } finally {
                $scope->close();
            }
        });
    }

    /**
     * Trace a generic fetch method in a procedural instance approach.
     *
     * @param string $methodName
     */
    private static function traceProceduralFetchMethod($methodName)
    {
        dd_trace($methodName, function() use ($methodName) {
            $args = func_get_args();
            $mysql_result = $args[0];
            $resource = Mysqli::retrieveQuery($mysql_result, $methodName);
            $scope = Mysqli::initScope($methodName, $resource);
            /** @var \DDTrace\Span $span */
            $span = $scope->getSpan();
            $host_info = ObjectKVStore::get($mysql_result, 'host_info', []);
            foreach ($host_info as $key => $value) {
                $span->setTag($key, $value);
            }

            try {
                return $methodName(...$args);
            } catch (\Exception $e) {
                $span->setError($e);
                throw $e;
            } finally {
                $scope->close();
            }
        });
    }

    /**
     * Given a mysqli instance, it extract an array containing host info.
     *
     * @param $mysqli
     * @return array
     */
    public static function extractHostInfo($mysqli)
    {
        $host_info = $mysqli->host_info;
        $parts = explode(':', substr($host_info, 0, strpos($host_info, ' ')));
        $host = $parts[0];
        $port = isset($parts[1]) ? $parts[1] : '3306';
        return [
            'db.type' => 'mysql',
            'out.host' => $host,
            'out.port' => $port,
        ];
    }

    /**
     * Initialize a scope setting basic tags to identify the mysqli service.
     *
     * @param string $operationName
     * @param string $resource
     * @return \OpenTracing\Scope
     */
    public static function initScope($operationName, $resource)
    {
        $scope = GlobalTracer::get()->startActiveSpan($operationName);
        /** @var \DDTrace\Span $span */
        $span = $scope->getSpan();
        $span->setTag(Tags\SPAN_TYPE, Types\SQL);
        $span->setTag(Tags\SERVICE_NAME, 'mysqli');
        $span->setResource($resource);
        return $scope;
    }

    /**
     * Set connection info into an existing span.
     *
     * @param Span $span
     * @param $mysqli
     */
    public static function setConnectionInfo($span, $mysqli)
    {
        $hostInfo = self::extractHostInfo($mysqli);
        foreach ($hostInfo as $tagName => $value){
            $span->setTag($tagName, $value);
        }
    }

    /**
     * Store a query into a mysqli or statement instance.
     *
     * @param mixed $instance
     * @param string $query
     */
    public static function storeQuery($instance, $query)
    {
        ObjectKVStore::put($instance, 'query', $query);
    }

    /**
     * Retrieves a query from a mysqli or statement instance.
     *
     * @param mixed $instance
     * @param string $fallbackValue
     * @return string|null
     */
    public static function retrieveQuery($instance, $fallbackValue)
    {
        return ObjectKVStore::get($instance, 'query', $fallbackValue);
    }
}
