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

        // mixed mysqli_query ( mysqli $link , string $query [, int $resultmode = MYSQLI_STORE_RESULT ] )
        dd_trace('mysqli_query', function (...$args) {
            $scope = GlobalTracer::get()->startActiveSpan('mysqli_query');
            $span = $scope->getSpan();
            $span->setTag('type', Types\SQL);
            $span->setResource($args[1]);

            $result = mysqli_query(...$args);

            $scope->close();

            return $result;
        });

        // mysqli_stmt mysqli_prepare ( mysqli $link , string $query )
        dd_trace('mysqli_prepare', function ($mysqli, $query) {
            $scope = GlobalTracer::get()->startActiveSpan('mysqli_prepare');
            $span = $scope->getSpan();
            $span->setTag('type', Types\SQL);
            $span->setResource($query);

            $result = mysqli_prepare($mysqli, $query);

            $scope->close();

            return $result;
        });

        // bool mysqli_commit ( mysqli $link [, int $flags [, string $name ]] )
        dd_trace('mysqli_commit', function (...$args) {
            $scope = GlobalTracer::get()->startActiveSpan('mysqli_commit');
            $span = $scope->getSpan();
            $span->setTag('type', Types\SQL);
            if (isset($args[2])) {
                $span->setTag('db.transaction_name', $args[2]);
            }

            $result = mysqli_commit(...$args);

            $scope->close();

            return $result;
        });

        // mysqli mysqli_connect ([ string $host = ini_get("mysqli.default_host")
        //      [, string $username = ini_get("mysqli.default_user")
        //      [, string $passwd = ini_get("mysqli.default_pw")
        //      [, string $dbname = ""
        //      [, int $port = ini_get("mysqli.default_port")
        //      [, string $socket = ini_get("mysqli.default_socket") ]]]]]] )
        dd_trace('mysqli_connect', function (...$args) {
            $scope = GlobalTracer::get()->startActiveSpan('mysqli_connect');
            $span = $scope->getSpan();
            $span->setTag('type', Types\SQL);
            //TODO set db connection info

            $result = mysqli_connect(...$args);

            $scope->close();

            return $result;
        });

        // bool mysqli_stmt_execute ( mysqli_stmt $stmt )
        dd_trace('mysqli_stmt_execute', function (...$args) {
            $scope = GlobalTracer::get()->startActiveSpan('mysqli_stmt_execute');
            $span = $scope->getSpan();
            $span->setTag('type', Types\SQL);
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
            $span->setTag('type', Types\SQL);
            $span->setResource($args[0]);

            $result = $this->query(...$args);

            $scope->close();

            return $result;
        });

        // mysqli_stmt mysqli::prepare ( string $query )
        dd_trace('mysqli', 'prepare', function ($query) {
            $scope = GlobalTracer::get()->startActiveSpan('mysqli.prepare');
            $span = $scope->getSpan();
            $span->setTag('type', Types\SQL);
            $span->setResource($query);

            $result = $this->prepare($query);

            $scope->close();

            return $result;
        });

        // bool mysqli::commit ([ int $flags [, string $name ]] )
        dd_trace('mysqli', 'commit', function (...$args) {
            $scope = GlobalTracer::get()->startActiveSpan('mysqli.commit');
            $span = $scope->getSpan();
            $span->setTag('type', Types\SQL);
            if (isset($args[1])) {
                $span->setTag('db.transaction_name', $args[1]);
            }

            $result = $this->commit(...$args);

            $scope->close();

            return $result;
        });

        // bool mysqli_stmt::execute ( void )
        dd_trace('mysqli_stmt', 'execute', function () {
            $scope = GlobalTracer::get()->startActiveSpan('mysqli_stmt.execute');
            $span = $scope->getSpan();
            $span->setTag('type', Types\SQL);
            //TODO set db connection info
            //TODO how to track query

            $result = $this->execute();

            $scope->close();

            return $result;
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
