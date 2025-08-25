<?php

namespace DDTrace\Integrations\SQLSRV;

use DDTrace\HookData;
use DDTrace\Integrations\DatabaseIntegrationHelper;
use DDTrace\Integrations\Integration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;

use function DDTrace\resource_weak_get;
use function DDTrace\resource_weak_store;

class SQLSRVIntegration extends Integration
{
    const NAME = 'sqlsrv';
    const SYSTEM = 'mssql';
    const CONNECTION_TAGS_KEY = 'sqlsrv_connection_tags';
    const QUERY_TAGS_KEY = 'sqlsrv_query_tags';

    /**
     * Load the integration
     */
    public static function init(): int
    {
        if (!extension_loaded('sqlsrv')) {
            return Integration::NOT_AVAILABLE;
        }

        // sqlsrv_connect ( string $serverName [, array $connectionInfo] ) : resource
        \DDTrace\trace_function('sqlsrv_connect', static function (SpanData $span, $args, $retval) {
            $connectionMetadata = self::extractConnectionMetadata($args);
            if ($retval) {
                resource_weak_store($retval, self::CONNECTION_TAGS_KEY, $connectionMetadata);
            }
            self::setDefaultAttributes($connectionMetadata, $span, 'sqlsrv_connect');

            self::detectError($retval, $span);
        });

        // sqlsrv_query ( resource $conn , string $query [, array $params [, array $options ]] ) : resource
        \DDTrace\install_hook('sqlsrv_query', static function (HookData $hook) {
            list($conn, $query) = $hook->args;

            $span = $hook->span();
            self::setDefaultAttributes($conn, $span, 'sqlsrv_query', $query);
            self::addTraceAnalyticsIfEnabled($span);
            $span->peerServiceSources = DatabaseIntegrationHelper::PEER_SERVICE_SOURCES;

            DatabaseIntegrationHelper::injectDatabaseIntegrationData($hook, 'sqlsrv', 1);
        }, static function (HookData $hook) {
            list($conn, $query) = $hook->args;
            $span = $hook->span();
            if (\is_resource($hook->returned)) {
                resource_weak_store($hook->returned, self::CONNECTION_TAGS_KEY, resource_weak_get($conn, self::CONNECTION_TAGS_KEY));
                resource_weak_store($conn, self::QUERY_TAGS_KEY, $query);
            }

            $result = $hook->returned;
            self::setMetrics($span, $result);
            self::detectError($result, $span);
        });

        // sqlsrv_prepare ( resource $conn , string $query [, array $params [, array $options ]] ) : resource
        \DDTrace\install_hook('sqlsrv_prepare', static function (HookData $hook) {
            list($conn, $query) = $hook->args;

            $span = $hook->span();
            self::setDefaultAttributes($conn, $span, 'sqlsrv_prepare', $query);

            DatabaseIntegrationHelper::injectDatabaseIntegrationData($hook, 'sqlsrv', 1);
        }, static function (HookData $hook) {
            list($conn, $query) = $hook->args;
            $span = $hook->span();
            if (\is_resource($hook->returned)) {
                resource_weak_store($hook->returned, self::CONNECTION_TAGS_KEY, resource_weak_get($conn, self::CONNECTION_TAGS_KEY));
                resource_weak_store($hook->returned, self::QUERY_TAGS_KEY, $query);
            }

            self::detectError($hook->returned, $span);
        });

        // sqlsrv_commit ( resource $conn ) : bool
        \DDTrace\trace_function('sqlsrv_commit', static function (SpanData $span, $args, $retval) {
            list($conn) = $args;
            self::setDefaultAttributes($conn, $span, 'sqlsrv_commit', null, $retval);

            self::detectError($retval, $span);
        });

        // sqlsrv_execute ( resource $stmt ) : bool
        \DDTrace\trace_function('sqlsrv_execute', static function (SpanData $span, $args, $retval) {
            list($stmt) = $args;
            if (\is_resource($stmt)) {
                $query = resource_weak_get($stmt, self::QUERY_TAGS_KEY);
            }
            self::setDefaultAttributes($stmt, $span, 'sqlsrv_execute', $query ?? "", $retval);
            self::addTraceAnalyticsIfEnabled($span);
            $span->peerServiceSources = DatabaseIntegrationHelper::PEER_SERVICE_SOURCES;
            if ($retval) {
                self::setMetrics($span, $args[0]);
            }

            self::detectError($retval, $span);
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
        } elseif (is_resource($source)) {
            $storedConnectionInfo = resource_weak_get($source, self::CONNECTION_TAGS_KEY) ?? [];
        }

        if (!isset($storedConnectionInfo) || !is_array($storedConnectionInfo)) {
            $storedConnectionInfo = [];
        }

        $span->name = $name;
        $span->resource = $query ?? $name;
        $span->type = Type::SQL;
        Integration::handleInternalSpanServiceName($span, self::NAME);
        $span->meta[Tag::SPAN_KIND] = 'client';
        $span->meta[Tag::COMPONENT] = self::NAME;
        $span->meta[Tag::DB_SYSTEM] = self::SYSTEM;

        foreach ($storedConnectionInfo as $tag => $value) {
            $span->meta[$tag] = $value;
        }

        $targetName = $storedConnectionInfo[Tag::DB_INSTANCE] ?? $storedConnectionInfo[Tag::TARGET_HOST] ?? "<default>";
        if (\dd_trace_env_config("DD_TRACE_DB_CLIENT_SPLIT_BY_INSTANCE")) {
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

        $errors = \sqlsrv_errors();
        if (empty($errors)) {
            return;
        }

        // There could be multiple errors occurring on the same sqlsrv operation
        // If this is the case, we concatenate them using ' | ' as the separator
        // Format: SQL Error: <code>. Driver error: <sqlstate>. Driver-specific error data: <message>
        $errorMessages = implode(' | ', array_map(static function ($error) {
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

    protected static function setMetrics(SpanData $span, $stmt)
    {
        if ($stmt) {
            $numRows = \sqlsrv_num_rows($stmt);
            if ($numRows) {
                // If the default cursor type (SQLSRC_CURSOR_FORWARD) is used, the number of rows
                // in a result set can be retrieved only after all rows in the result set
                // have been read, and we cannot do that without fetching all the rows, which
                // could lead to some non-negligible overhead.
                $span->metrics[Tag::DB_ROW_COUNT] = $numRows;
            } else {
                self::setMetricRowsAffected($span, $stmt);
            }
        }
    }

    protected static function setMetricRowsAffected(SpanData $span, $stmt)
    {
        if ($stmt) {
            $rowsAffected = \sqlsrv_rows_affected($stmt);
            if ($rowsAffected !== false && $rowsAffected !== -1) {
                // If the function returns -1, the number of rows cannot be determined.
                $span->metrics[Tag::DB_ROW_COUNT] = $rowsAffected;
            }
        }
    }
}
