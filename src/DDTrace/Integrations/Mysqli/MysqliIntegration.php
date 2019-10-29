<?php

namespace DDTrace\Integrations\Mysqli;

use DDTrace\Integrations\Integration;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\Util\ObjectKVStore;
use DDTrace\GlobalTracer;

class MysqliIntegration extends Integration
{
    const NAME = 'mysqli';

    /**
     * @var self
     */
    private static $instance;

    /**
     * @return self
     */
    public static function getInstance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * @return string The integration name.
     */
    public function getName()
    {
        return self::NAME;
    }

    public static function load()
    {
        if (!extension_loaded('mysqli')) {
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
            $tracer = GlobalTracer::get();
            if ($tracer->limited()) {
                return dd_trace_forward_call();
            }

            $scope = MysqliIntegration::initScope('mysqli_connect', 'mysqli_connect');
            $span = $scope->getSpan();

            $thrown = null;
            $result = null;
            try {
                // Depending on configuration, connections errors can both cause an exception and return false
                $result = dd_trace_forward_call();
                if ($result === false) {
                    $span->setError(new \Exception(mysqli_connect_error(), mysqli_connect_errno()));
                } else {
                    MysqliIntegration::setConnectionInfo($span, $result);
                }
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

        dd_trace('mysqli_real_connect', function () {
            $args = func_get_args();
            $tracer = GlobalTracer::get();
            if ($tracer->limited()) {
                return dd_trace_forward_call();
            }

            $scope = MysqliIntegration::initScope('mysqli_real_connect', 'mysqli_real_connect');
            $span = $scope->getSpan();

            $thrown = null;
            $result = null;
            try {
                // Depending on configuration, connections errors can both cause an exception and return false
                $result = dd_trace_forward_call();
                if ($result === false) {
                    $span->setError(new \Exception(mysqli_connect_error(), mysqli_connect_errno()));
                } elseif (count($args) > 0) {
                    MysqliIntegration::setConnectionInfo($span, $args[0]);
                }
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
            $tracer = GlobalTracer::get();
            if ($tracer->limited()) {
                return dd_trace_forward_call();
            }

            $scope = MysqliIntegration::initScope('mysqli.__construct', 'mysqli.__construct');
            /** @var \DDTrace\Span $span */
            $span = $scope->getSpan();

            // PHP 5.4 compatible try-catch-finally
            $thrown = null;
            try {
                dd_trace_forward_call();
                if (mysqli_connect_errno()) {
                    $span->setError(new \Exception(mysqli_connect_error(), mysqli_connect_errno()));
                } elseif (count($args)) {
                    // Host can either be provided as constructor arg or after
                    // through ->real_connect(...). In this latter case an error
                    // `Property access is not allowed yet` would be thrown when
                    // accessing host info.
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

        // bool mysqli_stmt_get_result ( mysqli_stmt $stmt )
        dd_trace('mysqli', 'real_connect', function ($stmt) {
            $args = func_get_args();
            $tracer = GlobalTracer::get();
            if ($tracer->limited()) {
                return dd_trace_forward_call();
            }

            $scope = MysqliIntegration::initScope('mysqli.real_connect', 'mysqli.real_connect');
            /** @var \DDTrace\Span $span */
            $span = $scope->getSpan();

            // PHP 5.4 compatible try-catch-finally
            $thrown = null;
            $result = null;
            try {
                $result = dd_trace_forward_call();
                if (mysqli_connect_errno()) {
                    $span->setError(new \Exception(mysqli_connect_error(), mysqli_connect_errno()));
                } elseif (count($args)) {
                    // Host can either be provided as constructor arg or after
                    // through ->real_connect(...). In this latter case an error
                    // `Property access is not allowed yet` would be thrown when
                    // accessing host info.
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

            return $result;
        });


        // mixed mysqli_query ( mysqli $link , string $query [, int $resultmode = MYSQLI_STORE_RESULT ] )
        dd_trace('mysqli_query', function () {
            $tracer = GlobalTracer::get();
            if ($tracer->limited()) {
                return dd_trace_forward_call();
            }

            list($mysqli, $query) = func_get_args();

            $scope = MysqliIntegration::initScope('mysqli_query', $query);
            /** @var \DDTrace\Span $span */
            $span = $scope->getSpan();
            $span->setTraceAnalyticsCandidate();
            MysqliIntegration::setConnectionInfo($span, $mysqli);
            MysqliCommon::storeQuery($mysqli, $query);

            $result = dd_trace_forward_call();
            MysqliCommon::storeQuery($result, $query);
            ObjectKVStore::put($result, 'host_info', MysqliCommon::extractHostInfo($mysqli));

            $scope->close();

            return $result;
        });

        // mysqli_stmt mysqli_prepare ( mysqli $link , string $query )
        dd_trace('mysqli_prepare', function ($mysqli, $query) {
            $tracer = GlobalTracer::get();
            if ($tracer->limited()) {
                return dd_trace_forward_call();
            }

            $scope = MysqliIntegration::initScope('mysqli_prepare', $query);
            /** @var \DDTrace\Span $span */
            $span = $scope->getSpan();
            MysqliIntegration::setConnectionInfo($span, $mysqli);

            $statement = dd_trace_forward_call();
            MysqliCommon::storeQuery($statement, $query);
            $host_info = MysqliCommon::extractHostInfo($mysqli);
            ObjectKVStore::put($statement, 'host_info', $host_info);

            $scope->close();

            return $statement;
        });

        // bool mysqli_commit ( mysqli $link [, int $flags [, string $name ]] )
        dd_trace('mysqli_commit', function () {
            $tracer = GlobalTracer::get();
            if ($tracer->limited()) {
                return dd_trace_forward_call();
            }

            $args = func_get_args();
            list($mysqli) = $args;
            $resource = MysqliCommon::retrieveQuery($mysqli, 'mysqli_commit');
            $scope = MysqliIntegration::initScope('mysqli_commit', $resource);
            /** @var \DDTrace\Span $span */
            $span = $scope->getSpan();
            MysqliIntegration::setConnectionInfo($span, $mysqli);

            if (isset($args[2])) {
                $span->setTag('db.transaction_name', $args[2]);
            }

            $result = dd_trace_forward_call();

            $scope->close();

            return $result;
        });

        // bool mysqli_stmt_execute ( mysqli_stmt $stmt )
        dd_trace('mysqli_stmt_execute', function ($stmt) {
            $tracer = GlobalTracer::get();
            if ($tracer->limited()) {
                return dd_trace_forward_call();
            }

            $resource = MysqliCommon::retrieveQuery($stmt, 'mysqli_stmt_execute');
            $scope = MysqliIntegration::initScope('mysqli_stmt_execute', $resource);

            $result = dd_trace_forward_call();

            $scope->close();

            return $result;
        });

        // bool mysqli_stmt_get_result ( mysqli_stmt $stmt )
        dd_trace('mysqli_stmt_get_result', function ($stmt) {
            $tracer = GlobalTracer::get();
            if ($tracer->limited()) {
                return dd_trace_forward_call();
            }

            $resource = MysqliCommon::retrieveQuery($stmt, 'mysqli_stmt_get_result');
            $result = dd_trace_forward_call();

            MysqliCommon::storeQuery($result, $resource);
            ObjectKVStore::propagate($stmt, $result, 'host_info');

            return $result;
        });

        // mixed mysqli::query ( string $query [, int $resultmode = MYSQLI_STORE_RESULT ] )
        dd_trace('mysqli', 'query', function () {
            $tracer = GlobalTracer::get();
            if ($tracer->limited()) {
                return dd_trace_forward_call();
            }

            list($query) = func_get_args();
            $scope = MysqliIntegration::initScope('mysqli.query', $query);
            /** @var \DDTrace\Span $span */
            $span = $scope->getSpan();
            $span->setTraceAnalyticsCandidate();
            MysqliIntegration::setConnectionInfo($span, $this);
            MysqliCommon::storeQuery($this, $query);

            $afterResult = function ($result) use ($query) {
                $host_info = MysqliCommon::extractHostInfo($this);
                ObjectKVStore::put($result, 'host_info', $host_info);
                ObjectKVStore::put($result, 'query', $query);
            };
            return include __DIR__ . '/../../try_catch_finally.php';
        });

        // mysqli_stmt mysqli::prepare ( string $query )
        dd_trace('mysqli', 'prepare', function ($query) {
            $tracer = GlobalTracer::get();
            if ($tracer->limited()) {
                return dd_trace_forward_call();
            }

            $scope = MysqliIntegration::initScope('mysqli.prepare', $query);
            /** @var \DDTrace\Span $span */
            $span = $scope->getSpan();
            MysqliIntegration::setConnectionInfo($span, $this);
            $afterResult = function ($statement) use ($query) {
                $host_info = MysqliCommon::extractHostInfo($this);
                ObjectKVStore::put($statement, 'host_info', $host_info);
                MysqliCommon::storeQuery($statement, $query);
            };
            return include __DIR__ . '/../../try_catch_finally.php';
        });

        // bool mysqli::commit ([ int $flags [, string $name ]] )
        dd_trace('mysqli', 'commit', function () {
            $tracer = GlobalTracer::get();
            if ($tracer->limited()) {
                return dd_trace_forward_call();
            }

            $args = func_get_args();
            $resource = MysqliCommon::retrieveQuery($this, 'mysqli.commit');
            $scope = MysqliIntegration::initScope('mysqli.commit', $resource);
            /** @var \DDTrace\Span $span */
            $span = $scope->getSpan();
            MysqliIntegration::setConnectionInfo($span, $this);

            if (isset($args[1])) {
                $span->setTag('db.transaction_name', $args[1]);
            }

            return include __DIR__ . '/../../try_catch_finally.php';
        });

        // bool mysqli_stmt::execute ( void )
        dd_trace('mysqli_stmt', 'execute', function () {
            $tracer = GlobalTracer::get();
            if ($tracer->limited()) {
                return dd_trace_forward_call();
            }

            $resource = MysqliCommon::retrieveQuery($this, 'mysqli_stmt.execute');
            $scope = MysqliIntegration::initScope('mysqli_stmt.execute', $resource);
            $scope->getSpan()->setTraceAnalyticsCandidate();
            return include __DIR__ . '/../../try_catch_finally.php';
        });

        // bool mysqli_stmt::execute ( void )
        dd_trace('mysqli_stmt', 'get_result', function () {
            $tracer = GlobalTracer::get();
            if ($tracer->limited()) {
                return dd_trace_forward_call();
            }

            $resource = MysqliCommon::retrieveQuery($this, 'mysqli_stmt.get_result');
            $scope = MysqliIntegration::initScope('mysqli_stmt.get_result', $resource);
            $afterResult = function ($result) use ($resource) {
                ObjectKVStore::propagate($this, $result, 'host_info');
                ObjectKVStore::put($result, 'query', $resource);
            };
            return include __DIR__ . '/../../try_catch_finally.php';
        });
        return Integration::LOADED;
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
        $scope = GlobalTracer::get()->startIntegrationScopeAndSpan(MysqliIntegration::getInstance(), $operationName);
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
        $hostInfo = MysqliCommon::extractHostInfo($mysqli);
        foreach ($hostInfo as $tagName => $value) {
            $span->setTag($tagName, $value);
        }
    }
}
