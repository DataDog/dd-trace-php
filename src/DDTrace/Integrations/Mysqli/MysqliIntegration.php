<?php

namespace DDTrace\Integrations\Mysqli;

use DDTrace\Integrations\Integration;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\Util\Environment;
use DDTrace\Util\ObjectKVStore;
use DDTrace\Util\TryCatchFinally;
use DDTrace\GlobalTracer;

class MysqliIntegration
{
    const NAME = 'mysqli';

    public static function load()
    {
        if (!extension_loaded('mysqli') || Environment::matchesPhpVersion('5.4')) {
            // Memcached is provided through an extension and not through a class loader.
            return Integration::NOT_AVAILABLE;
        }

        // mysqli mysqli_connect ([ string $host = ini_get("mysqli.default_host")
        //      [, string $username = ini_get("mysqli.default_user")
        //      [, string $passwd = ini_get("mysqli.default_pw")
        //      [, string $dbname = ""
        //      [, int $port = ini_get("mysqli.default_port")
        //      [, string $socket = ini_get("mysqli.default_socket") ]]]]]] )
        dd_trace('mysqli_connect', function () {
            $args = func_get_args();
            $scope = MysqliIntegration::initScope('mysqli_connect', 'mysqli_connect');
            $span = $scope->getSpan();

            $thrown = null;
            $result = null;
            try {
                // Depending on configuration, connections errors can both cause an exception and return false
                $result = call_user_func_array('mysqli_connect', $args);
                if ($result === false) {
                    $span->setError(new \Exception(mysqli_connect_error(), mysqli_connect_errno()));
                } else {
                    MysqliIntegration::setConnectionInfo($span, $result);
                }
                $scope->close();
            } catch (\Exception $ex) {
                $span->setError($ex);
                $thrown = $ex;
            }

            $scope->close();
            if ($thrown) {
                throw $thrown;
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
            $scope = MysqliIntegration::initScope('mysqli.__construct', 'mysqli.__construct');
            /** @var \DDTrace\Span $span */
            $span = $scope->getSpan();

            // PHP 5.4 compatible try-catch-finally
            $thrown = null;
            try {
                call_user_func_array([$this, $mysqli_constructor], $args);
                //Mysqli::storeConnectionParams($this, $args);
                if (mysqli_connect_errno()) {
                    $span->setError(new \Exception(mysqli_connect_error(), mysqli_connect_errno()));
                } else {
                    MysqliIntegration::setConnectionInfo($span, $this);
                }
            } catch (\Exception $ex) {
                $thrown = $ex;
                $span->setError($ex);
            }

            $scope->close();
            if ($thrown) {
                throw $thrown;
            }

            return $this;
        });

        // mixed mysqli_query ( mysqli $link , string $query [, int $resultmode = MYSQLI_STORE_RESULT ] )
        dd_trace('mysqli_query', function () {
            $args = func_get_args();
            list($mysqli, $query) = $args;

            $scope = MysqliIntegration::initScope('mysqli_query', $query);
            /** @var \DDTrace\Span $span */
            $span = $scope->getSpan();
            MysqliIntegration::setConnectionInfo($span, $mysqli);
            MysqliIntegration::storeQuery($mysqli, $query);

            $result = call_user_func_array('mysqli_query', $args);
            MysqliIntegration::storeQuery($result, $query);
            ObjectKVStore::put($result, 'host_info', MysqliIntegration::extractHostInfo($mysqli));

            $scope->close();

            return $result;
        });

        // mysqli_stmt mysqli_prepare ( mysqli $link , string $query )
        dd_trace('mysqli_prepare', function ($mysqli, $query) {
            $scope = MysqliIntegration::initScope('mysqli_prepare', $query);
            /** @var \DDTrace\Span $span */
            $span = $scope->getSpan();
            MysqliIntegration::setConnectionInfo($span, $mysqli);

            $statement = mysqli_prepare($mysqli, $query);
            MysqliIntegration::storeQuery($statement, $query);
            $host_info = MysqliIntegration::extractHostInfo($mysqli);
            ObjectKVStore::put($statement, 'host_info', $host_info);

            $scope->close();

            return $statement;
        });

        // bool mysqli_commit ( mysqli $link [, int $flags [, string $name ]] )
        dd_trace('mysqli_commit', function () {
            $args = func_get_args();
            list($mysqli) = $args;
            $resource = MysqliIntegration::retrieveQuery($mysqli, 'mysqli_commit');
            $scope = MysqliIntegration::initScope('mysqli_commit', $resource);
            /** @var \DDTrace\Span $span */
            $span = $scope->getSpan();
            MysqliIntegration::setConnectionInfo($span, $mysqli);

            if (isset($args[2])) {
                $span->setTag('db.transaction_name', $args[2]);
            }

            $result = call_user_func_array('mysqli_commit', $args);

            $scope->close();

            return $result;
        });

        // bool mysqli_stmt_execute ( mysqli_stmt $stmt )
        dd_trace('mysqli_stmt_execute', function ($stmt) {
            $resource = MysqliIntegration::retrieveQuery($stmt, 'mysqli_stmt_execute');
            $scope = MysqliIntegration::initScope('mysqli_stmt_execute', $resource);

            $result = mysqli_stmt_execute($stmt);

            $scope->close();

            return $result;
        });

        // bool mysqli_stmt_get_result ( mysqli_stmt $stmt )
        dd_trace('mysqli_stmt_get_result', function ($stmt) {
            $resource = MysqliIntegration::retrieveQuery($stmt, 'mysqli_stmt_get_result');
            $result = mysqli_stmt_get_result($stmt);

            MysqliIntegration::storeQuery($result, $resource);
            ObjectKVStore::propagate($stmt, $result, 'host_info');

            return $result;
        });

        // mixed mysqli::query ( string $query [, int $resultmode = MYSQLI_STORE_RESULT ] )
        dd_trace('mysqli', 'query', function () {
            $args = func_get_args();
            list($query) = $args;
            $scope = MysqliIntegration::initScope('mysqli.query', $query);
            /** @var \DDTrace\Span $span */
            $span = $scope->getSpan();
            MysqliIntegration::setConnectionInfo($span, $this);
            MysqliIntegration::storeQuery($this, $query);

            return TryCatchFinally::executePublicMethod(
                $scope,
                $this,
                'query',
                $args,
                function ($result) use ($query) {
                    $host_info = MysqliIntegration::extractHostInfo($this);
                    ObjectKVStore::put($result, 'host_info', $host_info);
                    ObjectKVStore::put($result, 'query', $query);
                }
            );
        });

        // mysqli_stmt mysqli::prepare ( string $query )
        dd_trace('mysqli', 'prepare', function ($query) {
            $scope = MysqliIntegration::initScope('mysqli.prepare', $query);
            /** @var \DDTrace\Span $span */
            $span = $scope->getSpan();
            MysqliIntegration::setConnectionInfo($span, $this);

            return TryCatchFinally::executePublicMethod(
                $scope,
                $this,
                'prepare',
                [$query],
                function ($statement) use ($query) {
                    $host_info = MysqliIntegration::extractHostInfo($this);
                    ObjectKVStore::put($statement, 'host_info', $host_info);
                    MysqliIntegration::storeQuery($statement, $query);
                }
            );
        });

        // bool mysqli::commit ([ int $flags [, string $name ]] )
        dd_trace('mysqli', 'commit', function () {
            $args = func_get_args();
            $resource = MysqliIntegration::retrieveQuery($this, 'mysqli.commit');
            $scope = MysqliIntegration::initScope('mysqli.commit', $resource);
            /** @var \DDTrace\Span $span */
            $span = $scope->getSpan();
            MysqliIntegration::setConnectionInfo($span, $this);

            if (isset($args[1])) {
                $span->setTag('db.transaction_name', $args[1]);
            }

            return TryCatchFinally::executePublicMethod($scope, $this, 'commit', $args);
        });

        // bool mysqli_stmt::execute ( void )
        dd_trace('mysqli_stmt', 'execute', function () {
            $resource = MysqliIntegration::retrieveQuery($this, 'mysqli_stmt.execute');
            $scope = MysqliIntegration::initScope('mysqli_stmt.execute', $resource);
            return TryCatchFinally::executePublicMethod($scope, $this, 'execute', []);
        });

        // bool mysqli_stmt::execute ( void )
        dd_trace('mysqli_stmt', 'get_result', function () {
            $resource = MysqliIntegration::retrieveQuery($this, 'mysqli_stmt.get_result');
            $scope = MysqliIntegration::initScope('mysqli_stmt.get_result', $resource);
            return TryCatchFinally::executePublicMethod(
                $scope,
                $this,
                'get_result',
                [],
                function ($result) use ($resource) {
                    ObjectKVStore::propagate($this, $result, 'host_info');
                    ObjectKVStore::put($result, 'query', $resource);
                }
            );
        });

        // Procedural fetch methods
        self::traceProceduralFetchMethod('mysqli_fetch_all');
        self::traceProceduralFetchMethod('mysqli_fetch_array');
        self::traceProceduralFetchMethod('mysqli_fetch_assoc');
        self::traceProceduralFetchMethod('mysqli_fetch_field_direct');
        self::traceProceduralFetchMethod('mysqli_fetch_field');
        self::traceProceduralFetchMethod('mysqli_fetch_fields');
        self::traceProceduralFetchMethod('mysqli_fetch_object');
        self::traceProceduralFetchMethod('mysqli_fetch_row');

        // Constructor fetch methods
        self::traceConstructorFetchMethod('fetch_all');
        self::traceConstructorFetchMethod('fetch_array');
        self::traceConstructorFetchMethod('fetch_assoc');
        self::traceConstructorFetchMethod('fetch_field_direct');
        self::traceConstructorFetchMethod('fetch_field');
        self::traceConstructorFetchMethod('fetch_fields');
        self::traceConstructorFetchMethod('fetch_object');
        self::traceConstructorFetchMethod('fetch_row');

        return Integration::LOADED;
    }

    /**
     * Trace a generic fetch method in a constructor instance approach.
     *
     * @param string $methodName
     */
    private static function traceConstructorFetchMethod($methodName)
    {
        dd_trace('mysqli_result', $methodName, function () use ($methodName) {
            $operationName = 'mysqli_result.' . $methodName;
            $args = func_get_args();
            $resource = MysqliIntegration::retrieveQuery($this, $operationName);
            $scope = MysqliIntegration::initScope($operationName, $resource);
            /** @var \DDTrace\Span $span */
            $span = $scope->getSpan();
            $host_info = ObjectKVStore::get($this, 'host_info', []);
            foreach ($host_info as $key => $value) {
                $span->setTag($key, $value);
            }

            return TryCatchFinally::executePublicMethod($scope, $this, $methodName, $args);
        });
    }

    /**
     * Trace a generic fetch method in a procedural instance approach.
     *
     * @param string $methodName
     */
    private static function traceProceduralFetchMethod($methodName)
    {
        dd_trace($methodName, function () use ($methodName) {
            $args = func_get_args();
            list($mysql_result) = $args;
            $resource = MysqliIntegration::retrieveQuery($mysql_result, $methodName);
            $scope = MysqliIntegration::initScope($methodName, $resource);
            /** @var \DDTrace\Span $span */
            $span = $scope->getSpan();
            $host_info = ObjectKVStore::get($mysql_result, 'host_info', []);
            foreach ($host_info as $key => $value) {
                $span->setTag($key, $value);
            }

            return TryCatchFinally::executeFunction($scope, $methodName, $args);
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
     * @return \DDTrace\Contracts\Scope
     */
    public static function initScope($operationName, $resource)
    {
        $scope = GlobalTracer::get()->startActiveSpan($operationName);
        /** @var \DDTrace\Span $span */
        $span = $scope->getSpan();
        $span->setTag(Tag::SPAN_TYPE, Type::SQL);
        $span->setTag(Tag::SERVICE_NAME, 'mysqli');
        $span->setTag(Tag::RESOURCE_NAME, $resource);
        return $scope;
    }

    /**
     * Set connection info into an existing span.
     *
     * @param \DDTrace\Contracts\Span $span
     * @param $mysqli
     */
    public static function setConnectionInfo($span, $mysqli)
    {
        $hostInfo = self::extractHostInfo($mysqli);
        foreach ($hostInfo as $tagName => $value) {
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
