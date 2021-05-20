<?php

namespace DDTrace\Integrations\Mysqli;

use DDTrace\Integrations\Integration;
use DDTrace\SpanData;
use DDTrace\Type;
use DDTrace\Util\ObjectKVStore;

class MysqliIntegration extends Integration
{
    const NAME = 'mysqli';

    // https://www.php.net/manual/en/mysqli.construct.php
    const DEFAULT_MYSQLI_HOST = 'localhost';

    /**
     * @return string The integration name.
     */
    public function getName()
    {
        return self::NAME;
    }

    /**
     * Load the integration
     *
     * @return int
     */
    public function init()
    {
        if (!extension_loaded('mysqli')) {
            return Integration::NOT_AVAILABLE;
        }

        $integration = $this;

        \DDTrace\trace_function('mysqli_connect', function (SpanData $span, $args, $result) use ($integration) {
            list($host) = $args;
            $integration->setDefaultAttributes($span, 'mysqli_connect', 'mysqli_connect');
            $integration->mergeMeta($span, MysqliCommon::parseHostInfo($host ?: self::DEFAULT_MYSQLI_HOST));

            if ($result === false) {
                $integration->trackPotentialError($span);
            }
        });

        $mysqli_constructor = PHP_MAJOR_VERSION > 5 ? '__construct' : 'mysqli';
        \DDTrace\trace_method(
            'mysqli',
            $mysqli_constructor,
            function (SpanData $span) use ($integration) {
                $integration->setDefaultAttributes($span, 'mysqli.__construct', 'mysqli.__construct');
                $integration->trackPotentialError($span);

                try {
                    // Host can either be provided as constructor arg or after
                    // through ->real_connect(...). In this latter case an error
                    // `Property access is not allowed yet` would be thrown when
                    // accessing host info.
                    $integration->setConnectionInfo($span, $this);
                } catch (\Exception $ex) {
                }
            }
        );

        \DDTrace\trace_function('mysqli_real_connect', function (SpanData $span, $args) use ($integration) {
            $integration->setDefaultAttributes($span, 'mysqli_real_connect', 'mysqli_real_connect');
            $integration->trackPotentialError($span);

            if (count($args) > 0) {
                $integration->setConnectionInfo($span, $args[0]);
            }
        });

        \DDTrace\trace_method('mysqli', 'real_connect', function (SpanData $span) use ($integration) {
            $integration->setDefaultAttributes($span, 'mysqli.real_connect', 'mysqli.real_connect');
            $integration->trackPotentialError($span);
            $integration->setConnectionInfo($span, $this);
        });


        \DDTrace\trace_function('mysqli_query', function (SpanData $span, $args, $result) use ($integration) {
            list($mysqli, $query) = $args;
            $integration->setDefaultAttributes($span, 'mysqli_query', $query);
            $integration->addTraceAnalyticsIfEnabled($span);
            $integration->setConnectionInfo($span, $mysqli);

            MysqliCommon::storeQuery($mysqli, $query);
            MysqliCommon::storeQuery($result, $query);
            ObjectKVStore::put($result, 'host_info', MysqliCommon::extractHostInfo($mysqli));
        });

        \DDTrace\trace_function('mysqli_prepare', function (SpanData $span, $args, $retval) use ($integration) {
            list($mysqli, $query) = $args;
            $integration->setDefaultAttributes($span, 'mysqli_prepare', $query);
            $integration->setConnectionInfo($span, $mysqli);

            $host_info = MysqliCommon::extractHostInfo($mysqli);
            MysqliCommon::storeQuery($retval, $query);
            ObjectKVStore::put($retval, 'host_info', $host_info);
        });

        \DDTrace\trace_function('mysqli_commit', function (SpanData $span, $args) use ($integration) {
            list($mysqli) = $args;
            $resource = MysqliCommon::retrieveQuery($mysqli, 'mysqli_commit');
            $integration->setDefaultAttributes($span, 'mysqli_commit', $resource);
            $integration->setConnectionInfo($span, $mysqli);

            if (isset($args[2])) {
                $span->meta['db.transaction_name'] = $args[2];
            }
        });

        \DDTrace\trace_function('mysqli_stmt_execute', function (SpanData $span, $args) use ($integration) {
            list($statement) = $args;
            $resource = MysqliCommon::retrieveQuery($statement, 'mysqli_stmt_execute');
            $integration->setDefaultAttributes($span, 'mysqli_stmt_execute', $resource);
        });

        \DDTrace\trace_function('mysqli_stmt_get_result', function (SpanData $span, $args, $result) {
            list($statement) = $args;
            $resource = MysqliCommon::retrieveQuery($statement, 'mysqli_stmt_get_result');
            MysqliCommon::storeQuery($result, $resource);
            ObjectKVStore::propagate($statement, $result, 'host_info');

            return false;
        });

        \DDTrace\trace_method('mysqli', 'query', function (SpanData $span, $args, $result) use ($integration) {
            list($query) = $args;
            $integration->setDefaultAttributes($span, 'mysqli.query', $query);
            $integration->addTraceAnalyticsIfEnabled($span);
            $integration->setConnectionInfo($span, $this);
            MysqliCommon::storeQuery($this, $query);
            ObjectKVStore::put($result, 'query', $query);
            $host_info = MysqliCommon::extractHostInfo($this);
            ObjectKVStore::put($result, 'host_info', $host_info);
            ObjectKVStore::put($result, 'query', $query);
        });

        \DDTrace\trace_method('mysqli', 'prepare', function (SpanData $span, $args, $retval) use ($integration) {
            list($query) = $args;
            $integration->setDefaultAttributes($span, 'mysqli.prepare', $query);
            $integration->setConnectionInfo($span, $this);
            $host_info = MysqliCommon::extractHostInfo($this);
            ObjectKVStore::put($retval, 'host_info', $host_info);
            MysqliCommon::storeQuery($retval, $query);
        });

        \DDTrace\trace_method('mysqli', 'commit', function (SpanData $span, $args) use ($integration) {
            $resource = MysqliCommon::retrieveQuery($this, 'mysqli.commit');
            $integration->setDefaultAttributes($span, 'mysqli.commit', $resource);
            $integration->setConnectionInfo($span, $this);

            if (isset($args[1])) {
                $span->meta['db.transaction_name'] = $args[1];
            }
        });

        \DDTrace\trace_method('mysqli_stmt', 'execute', function (SpanData $span) use ($integration) {
            $resource = MysqliCommon::retrieveQuery($this, 'mysqli_stmt.execute');
            $integration->setDefaultAttributes($span, 'mysqli_stmt.execute', $resource);
            $integration->addTraceAnalyticsIfEnabled($span);
        });

        \DDTrace\trace_method('mysqli_stmt', 'get_result', function (SpanData $span, $a, $result) use ($integration) {
            $resource = MysqliCommon::retrieveQuery($this, 'mysqli_stmt.get_result');
            $integration->setDefaultAttributes($span, 'mysqli_stmt.get_result', $resource);
            $integration->setConnectionInfo($span, $this);

            ObjectKVStore::propagate($this, $result, 'host_info');
            ObjectKVStore::put($result, 'query', $resource);
        });

        return Integration::LOADED;
    }

    /**
     * Initialize a span with the common attributes.
     *
     * @param SpanData $span
     * @param string $name
     * @param string $resource
     */
    public function setDefaultAttributes(SpanData $span, $name, $resource)
    {
        $span->name = $name;
        $span->resource = $resource;
        $span->type = Type::SQL;
        $span->service = 'mysqli';
    }

    /**
     * Set connection info into an existing span.
     *
     * @param SpanData $span
     * @param $mysqli
     */
    public function setConnectionInfo(SpanData $span, $mysqli)
    {
        $hostInfo = MysqliCommon::extractHostInfo($mysqli);
        foreach ($hostInfo as $tagName => $value) {
            $span->meta[$tagName] = $value;
        }
    }

    /**
     * Extracts and sets the proper error info on the span if one is detected.
     *
     * @param SpanData $span
     */
    public function trackPotentialError(SpanData $span)
    {
        $errorCode = mysqli_connect_errno();
        if ($errorCode > 0) {
            $this->setError($span, new \Exception(mysqli_connect_error()));
        }
    }
}
