<?php

namespace DDTrace\Integrations\Mysqli;

use DDTrace\Integrations\Integration;
use DDTrace\Integrations\SandboxedIntegration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\Util\ObjectKVStore;
use DDTrace\GlobalTracer;

class MysqliSandboxedIntegration extends SandboxedIntegration
{
    const NAME = 'mysqli';

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
            // Memcached is provided through an extension and not through a class loader.
            return Integration::NOT_AVAILABLE;
        }

        $integration = $this;

        dd_trace_function('mysqli_connect', function (SpanData $span, $args, $result) use ($integration) {
            if (dd_trace_tracer_is_limited()) {
                return false;
            }

            $integration->setDefaultAttributes($span, 'mysqli_connect', 'mysqli_connect');

            if ($result !== false) {
                $integration->setConnectionInfo($span, $result);
            } else {
                $integration->trackPotentialError($span);
            }
        });

        $mysqli_constructor = PHP_MAJOR_VERSION > 5 ? '__construct' : 'mysqli';
        dd_trace_method('mysqli', $mysqli_constructor, function (SpanData $span, $args) use ($mysqli_constructor, $integration) {
            if (dd_trace_tracer_is_limited()) {
                return false;
            }

            $integration->setDefaultAttributes($span, 'mysqli.__construct', 'mysqli.__construct');
            $integration->trackPotentialError($span);

            try {
                // Host can either be provided as constructor arg or after
                // through ->real_connect(...). In this latter case an error
                // `Property access is not allowed yet` would be thrown when
                // accessing host info.
                $integration->setConnectionInfo($span, $this);
            } catch (\Exception $ex) {}
        });

        dd_trace_function('mysqli_real_connect', function (SpanData $span, $args) use ($integration) {
            if (dd_trace_tracer_is_limited()) {
                return false;
            }

            $integration->setDefaultAttributes($span, 'mysqli_real_connect', 'mysqli_real_connect');
            $integration->trackPotentialError($span);

            if (count($args) > 0) {
                $integration->setConnectionInfo($span, $args[0]);
            }
        });

        dd_trace_method('mysqli', 'real_connect', function (SpanData $span) use ($integration) {
            if (dd_trace_tracer_is_limited()) {
                return false;
            }

            $integration->setDefaultAttributes($span, 'mysqli.real_connect', 'mysqli.real_connect');
            $integration->trackPotentialError($span);
            $integration->setConnectionInfo($span, $this);
        });


        dd_trace_function('mysqli_query', function (SpanData $span, $args, $result) use ($integration) {
            if (dd_trace_tracer_is_limited()) {
                return false;
            }

            list($mysqli, $query) = $args;
            $integration->setDefaultAttributes($span, 'mysqli_query', $query);
            $integration->addTraceAnalyticsIfEnabled($span);
            $integration->setConnectionInfo($span, $mysqli);

            MysqliCommon::storeQuery($mysqli, $query);
            MysqliCommon::storeQuery($result, $query);
            ObjectKVStore::put($result, 'host_info', MysqliCommon::extractHostInfo($mysqli));
        });

        dd_trace_function('mysqli_prepare', function (SpanData $span, $args, $returnedStatement) use ($integration) {
            if (dd_trace_tracer_is_limited()) {
                return false;
            }

            list($mysqli, $query) = $args;
            $integration->setDefaultAttributes($span, 'mysqli_prepare', $query);
            $integration->setConnectionInfo($span, $mysqli);

            $host_info = MysqliCommon::extractHostInfo($mysqli);
            MysqliCommon::storeQuery($returnedStatement, $query);
            ObjectKVStore::put($returnedStatement, 'host_info', $host_info);
        });

        dd_trace_function('mysqli_commit', function (SpandData $span, $args) use ($integration) {
            if (dd_trace_tracer_is_limited()) {
                return false;
            }

            list($mysqli) = $args;
            $resource = MysqliCommon::retrieveQuery($mysqli, 'mysqli_commit');
            $integration->setDefaultAttributes($span, 'mysqli_commit', $resource);
            $integration->setConnectionInfo($span, $mysqli);

            if (isset($args[2])) {
                $span->meta['db.transaction_name'] =$args[2];
            }
        });

        dd_trace_function('mysqli_stmt_execute', function (SpanData $span, $args, $returnedStatement) use ($integration) {
            if (dd_trace_tracer_is_limited()) {
                return false;
            }

            $resource = MysqliCommon::retrieveQuery($returnedStatement, 'mysqli_stmt_execute');
            $integration->setDefaultAttributes($span, 'mysqli_stmt_execute', $resource);
        });

        dd_trace_function('mysqli_stmt_get_result', function (SpanData $span, $args, $result) use ($integration) {
            if (dd_trace_tracer_is_limited()) {
                return false;
            }

            list($statement) = $args;
            $resource = MysqliCommon::retrieveQuery($statement, 'mysqli_stmt_get_result');
            MysqliIntegration::storeQuery($result, $resource);
            ObjectKVStore::propagate($statement, $result, 'host_info');

            return false;
        });

        dd_trace_method('mysqli', 'query', function (SpanData $span, $args, $result) use ($integration) {
            if (dd_trace_tracer_is_limited()) {
                return false;
            }

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

        dd_trace_method('mysqli', 'prepare', function (SpanData $span, $args, $returnedStatement) use ($integration) {
            if (dd_trace_tracer_is_limited()) {
                return false;
            }

            list($query) = $args;
            $integration->setDefaultAttributes($span, 'mysqli.prepare', $query);
            $integration->setConnectionInfo($span, $this);
            $host_info = MysqliCommon::extractHostInfo($this);
            ObjectKVStore::put($returnedStatement, 'host_info', $host_info);
            MysqliIntegration::storeQuery($returnedStatement, $query);
        });

        dd_trace_method('mysqli', 'commit', function (SpanData $span, $args) use ($integration) {
            if (dd_trace_tracer_is_limited()) {
                return false;
            }

            $resource = MysqliCommon::retrieveQuery($this, 'mysqli.commit');
            $integration->setDefaultAttributes($span, 'mysqli.commit', $resource);
            $integration->setConnectionInfo($span, $this);

            if (isset($args[1])) {
                $span->setTag('db.transaction_name', $args[1]);
            }
        });

        dd_trace_method('mysqli_stmt', 'execute', function (SpanData $span) use ($integration) {
            if (dd_trace_tracer_is_limited()) {
                return false;
            }

            $resource = MysqliCommon::retrieveQuery($this, 'mysqli_stmt.execute');
            $integration->setDefaultAttributes($span, 'mysqli_stmt.execute', $resource);
            $integration->addTraceAnalyticsIfEnabled($span);
        });

        dd_trace_method('mysqli_stmt', 'get_result', function (SpanData $span, $args, $result) use ($integration) {
            if (dd_trace_tracer_is_limited()) {
                return false;
            }

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

    /**
     * Extracts and sets the proper error info on the span if one is detected.
     *
     * @param SpanData $span
     */
    public function trackPotentialError(SpanData $span)
    {
        $errorCode = mysqli_connect_errno();
        if ($errorCode > 0) {
            $message = sprintf("%s [code: %d]", mysqli_connect_error(), $errorCode);
            $this->setError($span, $message);
        }
    }
}
