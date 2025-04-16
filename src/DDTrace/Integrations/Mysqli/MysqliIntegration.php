<?php

namespace DDTrace\Integrations\Mysqli;

use DDTrace\HookData;
use DDTrace\Integrations\DatabaseIntegrationHelper;
use DDTrace\Integrations\Integration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\Util\ObjectKVStore;
use function DDTrace\install_hook;

class MysqliIntegration extends Integration
{
    const NAME = 'mysqli';
    const SYSTEM = 'mysql';

    // https://www.php.net/manual/en/mysqli.construct.php
    const DEFAULT_MYSQLI_HOST = 'localhost';

    const KEY_DATABASE_NAME = 'database_name';
    const KEY_MYSQLI_INSTANCE = 'mysqli_instance';

    /**
     * Load the integration
     *
     * @return int
     */
    public function init(): int
    {
        if (!extension_loaded('mysqli')) {
            return Integration::NOT_AVAILABLE;
        }

        $integration = $this;

        \DDTrace\trace_function('mysqli_connect', function (SpanData $span, $args, $result) use ($integration) {
            list($host) = $args;
            $dbName = empty($args[3]) ? null : $args[3];
            if ($dbName) {
                // In case of a procedural connect, we set the database name here so it works also in case of connection
                // error ($misqli == false). However, we also add it to the returned instance (in case of success) to
                // propagate it to the queries.
                $span->meta[Tag::DB_NAME] = $args[3];
            }
            $integration->setDefaultAttributes($span, 'mysqli_connect', 'mysqli_connect');
            $span->meta += MysqliCommon::parseHostInfo($host ?: self::DEFAULT_MYSQLI_HOST);

            if ($result === false) {
                $integration->trackPotentialError($span);
            } else {
                ObjectKVStore::put($result, MysqliIntegration::KEY_DATABASE_NAME, $dbName);
            }
        });

        \DDTrace\trace_method(
            'mysqli',
            '__construct',
            function (SpanData $span, $args) use ($integration) {
                $dbName = empty($args[3]) ? null : $args[3];
                if ($dbName) {
                    ObjectKVStore::put($this, MysqliIntegration::KEY_DATABASE_NAME, $dbName);
                }
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
            list($mysqli) = $args;
            $host = empty($args[1]) ? null : $args[0];
            $dbName = empty($args[4]) ? null : $args[4];
            if ($dbName) {
                ObjectKVStore::put($mysqli, MysqliIntegration::KEY_DATABASE_NAME, $dbName);
            }
            $integration->setDefaultAttributes($span, 'mysqli_real_connect', 'mysqli_real_connect');
            if ($host) {
                $span->meta += MysqliCommon::parseHostInfo($host ?: self::DEFAULT_MYSQLI_HOST);
            }
            $integration->trackPotentialError($span);

            if (count($args) > 0) {
                $integration->setConnectionInfo($span, $args[0]);
            }
        });

        \DDTrace\trace_method('mysqli', 'real_connect', function (SpanData $span, $args) use ($integration) {
            $dbName = empty($args[3]) ? null : $args[3];
            if ($dbName) {
                ObjectKVStore::put($this, MysqliIntegration::KEY_DATABASE_NAME, $dbName);
            }
            $integration->setDefaultAttributes($span, 'mysqli.real_connect', 'mysqli.real_connect');
            $integration->trackPotentialError($span);
            $integration->setConnectionInfo($span, $this);
        });

        \DDTrace\install_hook('mysqli_query', function (HookData $hook) use ($integration) {
            list($mysqli, $query) = $hook->args;

            $span = $hook->span();
            $span->peerServiceSources = DatabaseIntegrationHelper::PEER_SERVICE_SOURCES;
            $integration->setDefaultAttributes($span, 'mysqli_query', $query);
            $integration->addTraceAnalyticsIfEnabled($span);
            $integration->setConnectionInfo($span, $mysqli);

            DatabaseIntegrationHelper::injectDatabaseIntegrationData($hook, 'mysql', 1);
            $integration->handleRasp($span);
        }, function (HookData $hook) use ($integration) {
            list($mysqli, $query) = $hook->args;
            $span = $hook->span();
            $integration->setConnectionInfo($span, $mysqli);

            MysqliCommon::storeQuery($mysqli, $query);
            MysqliCommon::storeQuery($hook->returned, $query);
            ObjectKVStore::put($hook->returned, 'host_info', MysqliCommon::extractHostInfo($mysqli));

            if (is_object($hook->returned) && property_exists($hook->returned, 'num_rows')) {
                $span->metrics[Tag::DB_ROW_COUNT] = $hook->returned->num_rows;
            }
        });

        \DDTrace\install_hook('mysqli_real_query', function (HookData $hook) use ($integration) {
            list($mysqli, $query) = $hook->args;

            $span = $hook->span();
            $span->peerServiceSources = DatabaseIntegrationHelper::PEER_SERVICE_SOURCES;
            $integration->setDefaultAttributes($span, 'mysqli_real_query', $query);
            $integration->addTraceAnalyticsIfEnabled($span);
            $integration->setConnectionInfo($span, $mysqli);

            DatabaseIntegrationHelper::injectDatabaseIntegrationData($hook, 'mysql', 1);
            $integration->handleRasp($span);
        }, function (HookData $hook) use ($integration) {
            list($mysqli, $query) = $hook->args;
            $span = $hook->span();
            $integration->setConnectionInfo($span, $mysqli);

            MysqliCommon::storeQuery($mysqli, $query);
        });

        \DDTrace\install_hook('mysqli_prepare', function (HookData $hook) use ($integration) {
            list(, $query) = $hook->args;

            $span = $hook->span();
            $integration->setDefaultAttributes($span, 'mysqli_prepare', $query);

            DatabaseIntegrationHelper::injectDatabaseIntegrationData($hook, 'mysql', 1);
            $integration->handleRasp($span);
        }, function (HookData $hook) use ($integration) {
            list($mysqli, $query) = $hook->args;
            $span = $hook->span();
            $integration->setConnectionInfo($span, $mysqli);

            $host_info = MysqliCommon::extractHostInfo($mysqli);
            MysqliCommon::storeQuery($hook->returned, $query);
            ObjectKVStore::put($hook->returned, 'host_info', $host_info);
            ObjectKVStore::put($hook->returned, MysqliIntegration::KEY_MYSQLI_INSTANCE, $mysqli);

            if (is_object($hook->returned) && property_exists($hook->returned, 'num_rows')) {
                $span->metrics[Tag::DB_ROW_COUNT] = $hook->returned->num_rows;
            }
        });

        \DDTrace\install_hook('mysqli::query', function (HookData $hook) use ($integration) {
            list($query) = $hook->args;

            $span = $hook->span();
            $span->peerServiceSources = DatabaseIntegrationHelper::PEER_SERVICE_SOURCES;
            $integration->setDefaultAttributes($span, 'mysqli.query', $query);
            $integration->addTraceAnalyticsIfEnabled($span);
            $integration->setConnectionInfo($span, $this);

            DatabaseIntegrationHelper::injectDatabaseIntegrationData($hook, 'mysql');
            $integration->handleRasp($span);
        }, function (HookData $hook) use ($integration) {
            list($query) = $hook->args;
            $span = $hook->span();
            $integration->setConnectionInfo($span, $this);

            MysqliCommon::storeQuery($this, $query);
            MysqliCommon::storeQuery($hook->returned, $query);
            ObjectKVStore::put($hook->returned, 'host_info', MysqliCommon::extractHostInfo($this));
            ObjectKVStore::put($hook->returned, 'query', $query);

            if (is_object($hook->returned) && property_exists($hook->returned, 'num_rows')) {
                $span->metrics[Tag::DB_ROW_COUNT] = $hook->returned->num_rows;
            }
        });

        \DDTrace\install_hook('mysqli::real_query', function (HookData $hook) use ($integration) {
            list($query) = $hook->args;

            $span = $hook->span();
            $span->peerServiceSources = DatabaseIntegrationHelper::PEER_SERVICE_SOURCES;
            $integration->setDefaultAttributes($span, 'mysqli.real_query', $query);
            $integration->addTraceAnalyticsIfEnabled($span);
            $integration->setConnectionInfo($span, $this);

            DatabaseIntegrationHelper::injectDatabaseIntegrationData($hook, 'mysql');
            $integration->handleRasp($span);
        }, function (HookData $hook) use ($integration) {
            list($query) = $hook->args;
            $span = $hook->span();
            $integration->setConnectionInfo($span, $this);

            MysqliCommon::storeQuery($this, $query);
        });

        \DDTrace\install_hook('mysqli::prepare', function (HookData $hook) use ($integration) {
            list($query) = $hook->args;

            $span = $hook->span();
            $integration->setDefaultAttributes($span, 'mysqli.prepare', $query);

            DatabaseIntegrationHelper::injectDatabaseIntegrationData($hook, 'mysql');
            $integration->handleRasp($span);
        }, function (HookData $hook) use ($integration) {
            list($query) = $hook->args;
            $span = $hook->span();
            $integration->setConnectionInfo($span, $this);

            $host_info = MysqliCommon::extractHostInfo($this);
            MysqliCommon::storeQuery($hook->returned, $query);
            ObjectKVStore::put($hook->returned, 'host_info', $host_info);
            ObjectKVStore::put($hook->returned, MysqliIntegration::KEY_MYSQLI_INSTANCE, $this);

            if (is_object($hook->returned) && property_exists($hook->returned, 'num_rows')) {
                $span->metrics[Tag::DB_ROW_COUNT] = $hook->returned->num_rows;
            }
        });

        \DDTrace\install_hook('mysqli_select_db', function (HookData $hook) {
            list($mysqli, $dbName) = $hook->args;
            ObjectKVStore::put($mysqli, MysqliIntegration::KEY_DATABASE_NAME, $dbName);
        });

        \DDTrace\install_hook('mysqli::select_db', function (HookData $hook) {
            list($dbName) = $hook->args;
            ObjectKVStore::put($this, MysqliIntegration::KEY_DATABASE_NAME, $dbName);
        });

        if (PHP_VERSION_ID >= 80200) {
            \DDTrace\install_hook('mysqli_execute_query', function (HookData $hook) use ($integration) {
                list(, $query) = $hook->args;

                $span = $hook->span();
                $span->peerServiceSources = DatabaseIntegrationHelper::PEER_SERVICE_SOURCES;
                $integration->setDefaultAttributes($span, 'mysqli_execute_query', $query);
                $integration->addTraceAnalyticsIfEnabled($span);

                DatabaseIntegrationHelper::injectDatabaseIntegrationData($hook, 'mysql', 1);
                $integration->handleRasp($span);
            }, function (HookData $hook) use ($integration) {
                list($mysqli, $query) = $hook->args;
                $span = $hook->span();
                $integration->setConnectionInfo($span, $mysqli);

                MysqliCommon::storeQuery($mysqli, $query);
                MysqliCommon::storeQuery($hook->returned, $query);
                ObjectKVStore::put($hook->returned, 'host_info', MysqliCommon::extractHostInfo($mysqli));
                ObjectKVStore::put($hook->returned, 'query', $query);

                if (is_object($hook->returned) && property_exists($hook->returned, 'num_rows')) {
                    $span->metrics[Tag::DB_ROW_COUNT] = $hook->returned->num_rows;
                }
            });

            \DDTrace\install_hook('mysqli::execute_query', function (HookData $hook) use ($integration) {
                list($query) = $hook->args;

                $span = $hook->span();
                $span->peerServiceSources = DatabaseIntegrationHelper::PEER_SERVICE_SOURCES;
                $integration->setDefaultAttributes($span, 'mysqli.execute_query', $query);
                $integration->addTraceAnalyticsIfEnabled($span);

                DatabaseIntegrationHelper::injectDatabaseIntegrationData($hook, 'mysql');
                $integration->handleRasp($span);
            }, function (HookData $hook) use ($integration) {
                list($query) = $hook->args;
                $span = $hook->span();
                $integration->setConnectionInfo($span, $this);

                MysqliCommon::storeQuery($this, $query);
                MysqliCommon::storeQuery($hook->returned, $query);
                ObjectKVStore::put($hook->returned, 'host_info', MysqliCommon::extractHostInfo($this));
                ObjectKVStore::put($hook->returned, 'query', $query);

                if (is_object($hook->returned) && property_exists($hook->returned, 'num_rows')) {
                    $span->metrics[Tag::DB_ROW_COUNT] = $hook->returned->num_rows;
                }
            });
        }
        \DDTrace\install_hook(
            'mysqli_multi_query',
            function (HookData $hook) use ($integration) {
                list(, $query) = $hook->args;
                $integration->handleRasp($query);
            }
        );
        \DDTrace\install_hook(
            'mysqli::multi_query',
            function (HookData $hook) use ($integration) {
                list($query) = $hook->args;
                $integration->handleRasp($query);
            }
        );

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
            $integration->setConnectionInfo(
                $span,
                ObjectKVStore::get($statement, MysqliIntegration::KEY_MYSQLI_INSTANCE)
            );
            $span->peerServiceSources = DatabaseIntegrationHelper::PEER_SERVICE_SOURCES;
        });

        \DDTrace\trace_function('mysqli_stmt_get_result', function (SpanData $span, $args, $result) {
            list($statement) = $args;
            $resource = MysqliCommon::retrieveQuery($statement, 'mysqli_stmt_get_result');
            MysqliCommon::storeQuery($result, $resource);
            ObjectKVStore::propagate($statement, $result, 'host_info');

            return false;
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
            $integration->setConnectionInfo($span, ObjectKVStore::get($this, MysqliIntegration::KEY_MYSQLI_INSTANCE));
            $span->peerServiceSources = DatabaseIntegrationHelper::PEER_SERVICE_SOURCES;
        });

        \DDTrace\trace_method('mysqli_stmt', 'get_result', function (SpanData $span, $a, $result) use ($integration) {
            $resource = MysqliCommon::retrieveQuery($this, 'mysqli_stmt.get_result');
            $integration->setDefaultAttributes($span, 'mysqli_stmt.get_result', $resource, $result);
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
     * @param $result
     */
    public function setDefaultAttributes(SpanData $span, $name, $resource, $result = null)
    {
        $span->name = $name;
        $span->resource = $resource;
        $span->type = Type::SQL;
        Integration::handleInternalSpanServiceName($span, MysqliIntegration::NAME);
        $span->meta[Tag::SPAN_KIND] = 'client';
        $span->meta[Tag::COMPONENT] = MysqliIntegration::NAME;
        $span->meta[Tag::DB_SYSTEM] = MysqliIntegration::SYSTEM;
        if (is_object($result) && property_exists($result, 'num_rows')) {
            $span->metrics[Tag::DB_ROW_COUNT] = $result->num_rows;
        }
    }

    /**
     * Set connection info into an existing span.
     *
     * @param SpanData $span
     * @param $mysqli
     */
    public function setConnectionInfo(SpanData $span, $mysqli)
    {
        if (empty($mysqli)) {
            return;
        }

        $hostInfo = MysqliCommon::extractHostInfo($mysqli);
        foreach ($hostInfo as $tagName => $value) {
            $span->meta[$tagName] = $value;
        }
        if (\dd_trace_env_config("DD_TRACE_DB_CLIENT_SPLIT_BY_INSTANCE")) {
            if (isset($hostInfo[Tag::TARGET_HOST])) {
                $span->service .=
                    '-' . \DDTrace\Util\Normalizer::normalizeHostUdsAsService($hostInfo[Tag::TARGET_HOST]);
            }
        }
        $dbName = ObjectKVStore::get($mysqli, MysqliIntegration::KEY_DATABASE_NAME);
        if ($dbName) {
            $span->meta[Tag::DB_NAME] = $dbName;
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
            $span->meta[Tag::ERROR_MSG] = mysqli_connect_error();
            $span->meta[Tag::ERROR_TYPE] = 'mysqli error';
            $span->meta[Tag::ERROR_STACK] = \DDTrace\get_sanitized_exception_trace(new \Exception, 2);
        }
    }

    /**
     * Handle RASP for SQLi detection.
     * @param SpanData|string $span
     */
    public function handleRasp($span)
    {
        static $raspEnabled = null;
        if ($raspEnabled === null) {
            $raspEnabled = \dd_trace_env_config("DD_APPSEC_RASP_ENABLED") &&
                function_exists('datadog\appsec\push_addresses');
        }

        if (!$raspEnabled) {
            return;
        }

        $addresses = array(
            'server.db.statement' => \is_string($span) ? $span : $span->resource,
            'server.db.system' => 'mysql',
        );
        \datadog\appsec\push_addresses($addresses, "sqli");
    }
}
