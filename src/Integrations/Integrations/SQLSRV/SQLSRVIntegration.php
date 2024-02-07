<?php

namespace DDTrace\Integrations\SQLSRV;

use DDTrace\HookData;
use DDTrace\Integrations\DatabaseIntegrationHelper;
use DDTrace\Integrations\Integration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\Util\ObjectKVStore;

use function DDTrace\install_hook;

class SQLSRVIntegration extends Integration
{
    const NAME = 'sqlsrv';
    const SYSTEM = 'mssql';
    const CONNECTION_TAGS_KEY = 'sqlsrv_connection_tags';
    const QUERY_TAGS_KEY = 'sqlsrv_query_tags';

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
        if (!extension_loaded('sqlsrv')) {
            return Integration::NOT_AVAILABLE;
        }

        $integration = $this;

        // sqlsrv_connect ( string $serverName [, array $connectionInfo] ) : resource
        \DDTrace\trace_function('sqlsrv_connect', function (SpanData $span, $args, $retval) use ($integration) {
            $connectionMetadata = $integration->extractConnectionMetadata($args);
            ObjectKVStore::put($this, SQLSRVIntegration::CONNECTION_TAGS_KEY, $connectionMetadata);
            self::setDefaultAttributes($connectionMetadata, $span, 'sqlsrv_connect');

            $integration->detectError($retval, $span);
        });

        if (PHP_MAJOR_VERSION > 5) {
            // sqlsrv_query ( resource $conn , string $query [, array $params [, array $options ]] ) : resource
            \DDTrace\install_hook('sqlsrv_query', function (HookData $hook) use ($integration) {
                list(, $query) = $hook->args;

                $span = $hook->span();
                self::setDefaultAttributes($this, $span, 'sqlsrv_query', $query);
                $integration->addTraceAnalyticsIfEnabled($span);
                if (\PHP_MAJOR_VERSION > 5) {
                    $span->peerServiceSources = DatabaseIntegrationHelper::PEER_SERVICE_SOURCES;
                }

                ObjectKVStore::put($this, SQLSRVIntegration::QUERY_TAGS_KEY, $query);

                DatabaseIntegrationHelper::injectDatabaseIntegrationData($hook, 'sqlsrv', 1);
            }, function (HookData $hook) use ($integration) {
                $span = $hook->span();
                if (is_object($hook->returned)) {
                    ObjectKVStore::propagate($this, $hook->returned, SQLSRVIntegration::CONNECTION_TAGS_KEY);
                }

                $result = $hook->returned;
                $this->setMetrics($span, $result);
                $integration->detectError($result, $span);
            });

            // sqlsrv_prepare ( resource $conn , string $query [, array $params [, array $options ]] ) : resource
            \DDTrace\install_hook('sqlsrv_prepare', function (HookData $hook) use ($integration) {
                list(, $query) = $hook->args;

                ObjectKVStore::put($this, SQLSRVIntegration::QUERY_TAGS_KEY, $query);

                $span = $hook->span();
                self::setDefaultAttributes($this, $span, 'sqlsrv_prepare', $query);

                DatabaseIntegrationHelper::injectDatabaseIntegrationData($hook, 'sqlsrv', 1);
            }, function (HookData $hook) use ($integration) {
                $span = $hook->span();
                if (is_object($hook->returned)) {
                    ObjectKVStore::propagate($this, $hook->returned, SQLSRVIntegration::CONNECTION_TAGS_KEY);
                }

                $integration->detectError($hook->returned, $span);
            });
        } else {
            // sqlsrv_query ( resource $conn , string $query [, array $params [, array $options ]] ) : resource
            \DDTrace\trace_function('sqlsrv_query', function (SpanData $span, $args, $retval) use ($integration) {
                /** @var resource $conn */
                $conn = $args[0];
                /** @var string $query */
                $query = $args[1];
                self::setDefaultAttributes($this, $span, 'sqlsrv_query', $query, $retval);
                $integration->addTraceAnalyticsIfEnabled($span);
                if (\PHP_MAJOR_VERSION > 5) {
                    $span->peerServiceSources = DatabaseIntegrationHelper::PEER_SERVICE_SOURCES;
                }
                ObjectKVStore::put($this, SQLSRVIntegration::QUERY_TAGS_KEY, $query);

                $this->setMetrics($span, $retval);

                $integration->detectError($retval, $span);
            });

            // sqlsrv_prepare ( resource $conn , string $query [, array $params [, array $options ]] ) : resource
            \DDTrace\trace_function('sqlsrv_prepare', function (SpanData $span, $args, $retval) use ($integration) {
                /** @var string $query */
                $query = $args[1];
                self::setDefaultAttributes($this, $span, 'sqlsrv_prepare', $query, $retval);
                ObjectKVStore::put($this, SQLSRVIntegration::QUERY_TAGS_KEY, $query);

                $integration->detectError($retval, $span);
            });
        }

        // sqlsrv_commit ( resource $conn ) : bool
        \DDTrace\trace_function('sqlsrv_commit', function (SpanData $span, $args, $retval) use ($integration) {
            self::setDefaultAttributes($this, $span, 'sqlsrv_commit', null, $retval);

            $integration->detectError($retval, $span);
        });

        // sqlsrv_execute ( resource $stmt ) : bool
        \DDTrace\trace_function('sqlsrv_execute', function (SpanData $span, $args, $retval) use ($integration) {
            $query = ObjectKVStore::get($this, SQLSRVIntegration::QUERY_TAGS_KEY);
            self::setDefaultAttributes($this, $span, 'sqlsrv_execute', $query, $retval);
            $integration->addTraceAnalyticsIfEnabled($span);
            if (\PHP_MAJOR_VERSION > 5) {
                    $span->peerServiceSources = DatabaseIntegrationHelper::PEER_SERVICE_SOURCES;
            }
            if ($retval) {
                $this->setMetrics($span, $args[0]);
            }

            $integration->detectError($retval, $span);
        });

        return Integration::LOADED;
    }

    public static function extractConnectionMetadata($connectionArguments)
    {
        // Retrieve Connection Metadata
        $connectionMetadata = [];
        $serverName = $connectionArguments[0];
        // $serverName's format is [protocol:]server[\instance][,port]
        // Do a regex to extract the host, instance and port
        $host = '';
        if (preg_match('/^(?:[^:]+:)?([^\\\,]+)/', $serverName, $matches)) {
            $host = $matches[1];
        }
        $connectionMetadata[Tag::TARGET_HOST] = empty($host) ? '<default>' : $host;

        $instanceName = '';
        if (preg_match('/\\\([^,]+)/', $serverName, $matches)) {
            $instanceName = $matches[1];
        }
        if (!empty($instanceName)) {
            $connectionMetadata[Tag::DB_INSTANCE] = $instanceName;
        }

        $port = '';
        if (preg_match('/,\s?(\d+)/', $serverName, $matches)) {
            $port = $matches[1];
        }
        $connectionMetadata[Tag::TARGET_PORT] = empty($port) ? '<default>' : $port;

        $connectionInfo = $connectionArguments[1];
        if (is_array($connectionInfo)) {
            if (isset($connectionInfo['UID'])) {
                $connectionMetadata[Tag::DB_USER] = $connectionInfo['UID'];
            }
            if (isset($connectionInfo['Database']) && empty($instanceName)) {
                $connectionMetadata[Tag::DB_INSTANCE] = $connectionInfo['Database'];
            }
            if (isset($connectionInfo['CharacterSet'])) {
                $connectionMetadata['db.charset'] = $connectionInfo['CharacterSet'];
            }
        }

        return $connectionMetadata;
    }

    public static function setDefaultAttributes($source, SpanData $span, $name, $query = null, $result = null)
    {
        if (is_array($source)) {
            $storedConnectionInfo = $source;
        } else {
            $storedConnectionInfo = ObjectKVStore::get($source, SQLSRVIntegration::CONNECTION_TAGS_KEY, []);
        }

        if (!is_array($storedConnectionInfo)) {
            $storedConnectionInfo = [];
        }

        $span->name = $name;
        $span->resource = $query ?? $name;
        $span->type = Type::SQL;
        Integration::handleInternalSpanServiceName($span, SQLSRVIntegration::NAME);
        $span->meta[Tag::SPAN_KIND] = 'client';
        $span->meta[Tag::COMPONENT] = SQLSRVIntegration::NAME;
        $span->meta[Tag::DB_SYSTEM] = SQLSRVIntegration::SYSTEM;

        if ($query) {
            $span->meta[Tag::DB_STMT] = $query;
        }

        foreach ($storedConnectionInfo as $tag => $value) {
            $span->meta[$tag] = $value;
        }

        $targetName = $storedConnectionInfo[Tag::DB_INSTANCE] ?? $storedConnectionInfo[Tag::TARGET_HOST] ?? "<default>";
        if (\DDTrace\Util\Runtime::getBoolIni("datadog.trace.db_client_split_by_instance")) {
            if ($targetName !== "<default>") {
                $span->service .= '-' . \DDTrace\Util\Normalizer::normalizeHostUdsAsService($targetName);
            }
        }
    }

    public static function detectError($SQLSRVRetval, SpanData $span)
    {
        if ($SQLSRVRetval !== false) {
            return;
        }

        $errors = sqlsrv_errors();
        if (empty($errors)) {
            return;
        }

        // There could be multiple errors occurring on the same sqlsrv operation
        // If this is the case, we concatenate them using ' | ' as the separator
        // Format: SQL Error: <code>. Driver error: <sqlstate>. Driver-specific error data: <message>
        $errorMessages = implode(' | ', array_map(function ($error) {
            return sprintf(
                'SQL error: %s. Driver error: %s. Driver-specific error data: %s',
                $error['code'],
                $error['SQLSTATE'],
                $error['message']
            );
        }, $errors));

        $span->meta[Tag::ERROR_MSG] = $errorMessages;
        $span->meta[Tag::ERROR_TYPE] = 'SQLSRV error';
    }

    protected function setMetrics(SpanData $span, $stmt)
    {
        if ($stmt) {
            $numRows = sqlsrv_num_rows($stmt);
            if ($numRows) {
                // If the default cursor type (SQLSRC_CURSOR_FORWARD) is used, the number of rows
                // in a result set can be retrieved only after all rows in the result set
                // have been read, and we cannot do that without fetching all the rows, which
                // could lead to some non-negligible overhead.
                $span->metrics[Tag::DB_ROW_COUNT] = $numRows;
            } else {
                $this->setMetricRowsAffected($span, $stmt);
            }
        }
    }

    protected function setMetricRowsAffected(SpanData $span, $stmt)
    {
        if ($stmt) {
            $rowsAffected = sqlsrv_rows_affected($stmt);
            if ($rowsAffected !== false && $rowsAffected !== -1) {
                // If the function returns -1, the number of rows cannot be determined.
                $span->metrics[Tag::DB_ROW_COUNT] = $rowsAffected;
            }
        }
    }
}
