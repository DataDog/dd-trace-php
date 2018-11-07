<?php

namespace DDTrace\Integrations;

use DDTrace\Tags;
use DDTrace\Types;
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
        dd_trace('mysqli_connect', function (...$args) {
            $scope = GlobalTracer::get()->startActiveSpan('mysqli_connect');
            $span = $scope->getSpan();
            $span->setTag(Tags\SPAN_TYPE, Types\SQL);
            $span->setTag(Tags\SERVICE_NAME, 'mysqli');
            //TODO set db connection info

            try {
                // Depending on configuration, connections errors can both cause an exception and return false
                $result = mysqli_connect(...$args);
                if ($result === false) {
                    $span->setError(new \Exception(mysqli_connect_error(), mysqli_connect_errno()));
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
        dd_trace('mysqli', $mysqli_constructor, function (...$args) use ($mysqli_constructor) {
            $scope = GlobalTracer::get()->startActiveSpan('mysqli.__construct');
            $span = $scope->getSpan();
            $span->setTag(Tags\SPAN_TYPE, Types\SQL);
            $span->setTag(Tags\SERVICE_NAME, 'mysqli');
            $span->setResource('mysqli.__construct');
            //TODO set db connection info

            try {
                $this->$mysqli_constructor(...$args);
                //Mysqli::storeConnectionParams($this, $args);
                if (mysqli_connect_errno()) {
                    $span->setError(new \Exception(mysqli_connect_error(), mysqli_connect_errno()));
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
        dd_trace('mysqli_query', function (...$args) {
            $scope = GlobalTracer::get()->startActiveSpan('mysqli_query');
            $span = $scope->getSpan();
            $span->setTag(Tags\SPAN_TYPE, Types\SQL);
            $span->setTag(Tags\SERVICE_NAME, 'mysqli');
            $span->setResource($args[1]);

            $result = mysqli_query(...$args);

            $scope->close();

            return $result;
        });

        // mysqli_stmt mysqli_prepare ( mysqli $link , string $query )
        dd_trace('mysqli_prepare', function ($mysqli, $query) {
            $scope = GlobalTracer::get()->startActiveSpan('mysqli_prepare');
            $span = $scope->getSpan();
            $span->setTag(Tags\SPAN_TYPE, Types\SQL);
            $span->setTag(Tags\SERVICE_NAME, 'mysqli');
            $span->setResource($query);

            $result = mysqli_prepare($mysqli, $query);

            $scope->close();

            return $result;
        });

        // bool mysqli_commit ( mysqli $link [, int $flags [, string $name ]] )
        dd_trace('mysqli_commit', function (...$args) {
            $scope = GlobalTracer::get()->startActiveSpan('mysqli_commit');
            $span = $scope->getSpan();
            $span->setTag(Tags\SPAN_TYPE, Types\SQL);
            $span->setTag(Tags\SERVICE_NAME, 'mysqli');
            if (isset($args[2])) {
                $span->setTag('db.transaction_name', $args[2]);
            }

            $result = mysqli_commit(...$args);

            $scope->close();

            return $result;
        });

        // bool mysqli_stmt_execute ( mysqli_stmt $stmt )
        dd_trace('mysqli_stmt_execute', function (...$args) {
            $scope = GlobalTracer::get()->startActiveSpan('mysqli_stmt_execute');
            $span = $scope->getSpan();
            $span->setTag(Tags\SPAN_TYPE, Types\SQL);
            $span->setTag(Tags\SERVICE_NAME, 'mysqli');
            //TODO set db connection info
            //TODO how to track query

            $result = mysqli_stmt_execute(...$args);

            $scope->close();

            return $result;
        });

        // mixed mysqli::query ( string $query [, int $resultmode = MYSQLI_STORE_RESULT ] )
        dd_trace('mysqli', 'query', function (...$args) {
            $scope = GlobalTracer::get()->startActiveSpan('mysqli.query');
            $span = $scope->getSpan();
            $span->setTag(Tags\SPAN_TYPE, Types\SQL);
            $span->setTag(Tags\SERVICE_NAME, 'mysqli');
            $span->setResource($args[0]);

            try {
                return $this->query(...$args);
            } catch (\Exception $e) {
                $span->setError($e);
                throw $e;
            } finally {
                $scope->close();
            }
        });

        // mysqli_stmt mysqli::prepare ( string $query )
        dd_trace('mysqli', 'prepare', function ($query) {
            $scope = GlobalTracer::get()->startActiveSpan('mysqli.prepare');
            $span = $scope->getSpan();
            $span->setTag(Tags\SPAN_TYPE, Types\SQL);
            $span->setTag(Tags\SERVICE_NAME, 'mysqli');
            $span->setResource($query);

            try {
                return $this->prepare($query);
            } catch (\Exception $e) {
                $span->setError($e);
                throw $e;
            } finally {
                $scope->close();
            }
        });

        // bool mysqli::commit ([ int $flags [, string $name ]] )
        dd_trace('mysqli', 'commit', function (...$args) {
            $scope = GlobalTracer::get()->startActiveSpan('mysqli.commit');
            $span = $scope->getSpan();
            $span->setTag(Tags\SPAN_TYPE, Types\SQL);
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
            $scope = GlobalTracer::get()->startActiveSpan('mysqli_stmt.execute');
            $span = $scope->getSpan();
            $span->setTag(Tags\SPAN_TYPE, Types\SQL);
            $span->setTag(Tags\SERVICE_NAME, 'mysqli');
            //TODO set db connection info
            //TODO how to track query

            try {
                return $this->execute();
            } catch (\Exception $e) {
                $span->setError($e);
                throw $e;
            } finally {
                $scope->close();
            }
        });
    }

    public static function extractHostInfo($mysqli)
    {
        $host_info = $mysqli->host_info;
        $parts = explode(':', substr($host_info, 0, strpos($host_info, ' ')));
        $host = $parts[0];
        $port = isset($parts[1]) ? $parts[1] : null;
        return [
            'db.type' => 'mysql',
            'out.host' => $host,
            'out.port' => $port,
        ];
    }
}
