<?php

namespace DDTrace\Integrations\PDO;

use DDTrace\HookData;
use DDTrace\Integrations\DatabaseIntegrationHelper;
use DDTrace\Integrations\Integration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\Util\ObjectKVStore;

class PDOIntegration extends Integration
{
    const NAME = 'pdo';

    const CONNECTION_TAGS_KEY = 'connection_tags';

    private static $DB_DRIVER_TO_SYSTEM = [
        'cubrid' => 'other_sql',
        'dblib' => 'other_sql',
        // may be mssql or Sybase, not supported anymore so shouldn't be a problem
        'firebird' => 'firebird',
        'ibm' => 'db2',
        'informix' => 'informix',
        'mysql' => 'mysql',
        'sqlsrv' => 'mssql',
        'oci' => 'oracle',
        'odbc' => 'other_sql',
        'pgsql' => 'postgresql',
        'sqlite' => 'sqlite'
    ];

    /**
     * Add instrumentation to PDO requests
     */
    public static function init(): int
    {
        if (!extension_loaded('PDO')) {
            // PDO is provided through an extension and not through a class loader.
            return Integration::NOT_AVAILABLE;
        }

        // public PDO::__construct ( string $dsn [, string $username [, string $passwd [, array $options ]]] )
        \DDTrace\trace_method('PDO', '__construct', function (SpanData $span, array $args) {
            Integration::handleOrphan($span);
            $span->name = $span->resource = 'PDO.__construct';
            $connectionMetadata = PDOIntegration::extractConnectionMetadata($args);
            ObjectKVStore::put($this, PDOIntegration::CONNECTION_TAGS_KEY, $connectionMetadata);
            // We have to use $connectionMetadata as a medium, instead of $this (aka the PDO instance) because in
            // PHP 5.* $this is NULL in this callback when there is a connection error.
            PDOIntegration::setCommonSpanInfo($connectionMetadata, $span);
        });

        if (PHP_VERSION_ID >= 80400) {
            // public PDO::connect ( string $dsn [, string $username [, string $passwd [, array $options ]]] )
            \DDTrace\trace_method('PDO', 'connect', function (SpanData $span, array $args, $pdo) {
                Integration::handleOrphan($span);
                $span->name = $span->resource = 'PDO.connect';
                $connectionMetadata = PDOIntegration::extractConnectionMetadata($args);
                ObjectKVStore::put($pdo, PDOIntegration::CONNECTION_TAGS_KEY, $connectionMetadata);
                PDOIntegration::setCommonSpanInfo($connectionMetadata, $span);
            });
        }

        // public int PDO::exec(string $query)
        \DDTrace\install_hook('PDO::exec', function (HookData $hook) {
            list($query) = $hook->args;

            $span = $hook->span();
            Integration::handleOrphan($span);
            $span->name = 'PDO.exec';
            $span->resource = Integration::toString($query);
            $span->peerServiceSources = DatabaseIntegrationHelper::PEER_SERVICE_SOURCES;
            PDOIntegration::setCommonSpanInfo($this, $span);
            PDOIntegration::addTraceAnalyticsIfEnabled($span);

            PDOIntegration::injectDBIntegration($this, $hook);
            PDOIntegration::handleRasp($this, $span);
        }, function (HookData $hook) {
            $span = $hook->span();
            if (is_numeric($hook->returned)) {
                $span->metrics[Tag::DB_ROW_COUNT] = $hook->returned;
            }
            PDOIntegration::detectError($this, $span);
        });

        // public PDOStatement PDO::query(string $query)
        // public PDOStatement PDO::query(string $query, int PDO::FETCH_COLUMN, int $colno)
        // public PDOStatement PDO::query(string $query, int PDO::FETCH_CLASS, string $classname, array $ctorargs)
        // public PDOStatement PDO::query(string $query, int PDO::FETCH_INFO, object $object)
        // public int PDO::exec(string $query)
        \DDTrace\install_hook('PDO::query', function (HookData $hook) {
            list($query) = $hook->args;

            $span = $hook->span();
            $span->name = 'PDO.query';
            $span->resource = Integration::toString($query);
            $span->peerServiceSources = DatabaseIntegrationHelper::PEER_SERVICE_SOURCES;
            PDOIntegration::setCommonSpanInfo($this, $span);
            PDOIntegration::addTraceAnalyticsIfEnabled($span);

            PDOIntegration::injectDBIntegration($this, $hook);
            PDOIntegration::handleRasp($this, $span);
        }, function (HookData $hook) {
            $span = $hook->span();
            if ($hook->returned instanceof \PDOStatement) {
                $span->metrics[Tag::DB_ROW_COUNT] = $hook->returned->rowCount();
                ObjectKVStore::propagate($this, $hook->returned, PDOIntegration::CONNECTION_TAGS_KEY);
            }
            PDOIntegration::detectError($this, $span);
        });

        // public PDOStatement PDO::prepare ( string $statement [, array $driver_options = array() ] )
        \DDTrace\install_hook('PDO::prepare', function (HookData $hook) {
            list($query) = $hook->args;

            $span = $hook->span();
            Integration::handleOrphan($span);
            $span->name = 'PDO.prepare';
            $span->resource = Integration::toString($query);
            PDOIntegration::setCommonSpanInfo($this, $span);

            PDOIntegration::injectDBIntegration($this, $hook);
            PDOIntegration::handleRasp($this, $span);
        }, function (HookData $hook) {
            ObjectKVStore::propagate($this, $hook->returned, PDOIntegration::CONNECTION_TAGS_KEY);
        });

        // public bool PDO::commit ( void )
        \DDTrace\trace_method('PDO', 'commit', function (SpanData $span) {
            Integration::handleOrphan($span);
            $span->name = $span->resource = 'PDO.commit';
            PDOIntegration::setCommonSpanInfo($this, $span);
        });

        // public bool PDOStatement::execute ([ array $input_parameters ] )
        \DDTrace\trace_method(
            'PDOStatement',
            'execute',
            function (SpanData $span, array $args, $retval) {
                Integration::handleOrphan($span);
                $span->name = 'PDOStatement.execute';
                Integration::handleInternalSpanServiceName($span, PDOIntegration::NAME);
                $span->type = Type::SQL;
                $span->resource = $this->queryString;
                $span->peerServiceSources = DatabaseIntegrationHelper::PEER_SERVICE_SOURCES;
                if ($retval === true) {
                    try {
                        $span->metrics[Tag::DB_ROW_COUNT] = $this->rowCount();
                    } catch (\Exception $e) {
                        // Ignore exception thrown by rowCount() method.
                        // Drupal PDOStatement::rowCount() method throws an exception if the query is not a SELECT.
                        // TODO: Check the instance of '$this' instead of doing this try/catch.
                    }
                }
                PDOIntegration::setCommonSpanInfo($this, $span);
                PDOIntegration::addTraceAnalyticsIfEnabled($span);
                PDOIntegration::detectError($this, $span);
            }
        );

        return Integration::LOADED;
    }

    /**
     * @param \PDO|\PDOStatement $pdoOrStatement
     * @param SpanData $span
     */
    public static function detectError($pdoOrStatement, SpanData $span)
    {
        $errorCode = $pdoOrStatement->errorCode();
        // Error codes follows the ANSI SQL-92 convention of 5 total chars:
        //   - 2 chars for class value
        //   - 3 chars for subclass value
        // Non error class values are: '00', '01', 'IM'
        // @see: http://php.net/manual/en/pdo.errorcode.php
        if (strlen($errorCode) !== 5) {
            return;
        }

        $class = strtoupper(substr($errorCode, 0, 2));
        if (in_array($class, ['00', '01', 'IM'], true)) {
            // Not an error
            return;
        }
        $errorInfo = $pdoOrStatement->errorInfo();
        $span->meta[Tag::ERROR_MSG] = 'SQL error: ' . $errorCode . '. Driver error: ' . $errorInfo[1];

        // Driver-specific error message will be in the rest of the array
        // The spec suggests the error message to be at pos 2 only,
        // ODBC: the SQLSTATE in [3] (but e.g. SQLServer doc states that the array can contain monre than 3 elements)
        // DBLIB: [3] an OSerror (int), [4] a severity and optionally a string representation of the OSerror in [5]
        if (count($errorInfo) > 2) {
            $span->meta[Tag::ERROR_MSG] .= '. Driver-specific error data: ' . implode('. ', array_slice($errorInfo, 2));
        }

        $span->meta[Tag::ERROR_TYPE] = get_class($pdoOrStatement) . ' error';
    }

    private static function parseDsn($dsn)
    {
        $engine = substr($dsn, 0, strpos($dsn, ':'));
        $tags = ['db.engine' => $engine];
        $dbSystem = isset(self::$DB_DRIVER_TO_SYSTEM[$engine])
          ? self::$DB_DRIVER_TO_SYSTEM[$engine]
          : 'other_sql';
        $tags[Tag::DB_SYSTEM] = $dbSystem;
        $tags[Tag::DB_TYPE] = $dbSystem;  // db.type is DD equivalent to db.system in OpenTelemetry, used for SQL spans obfuscation
        $valStrings = explode(';', substr($dsn, strlen($engine) + 1));
        foreach ($valStrings as $valString) {
            if (!strpos($valString, '=')) {
                continue;
            }
            list($key, $value) = explode('=', $valString);
            switch (strtolower($key)) {
                case 'charset':
                    $tags[Tag::DB_CHARSET] = $value;
                    break;
                case 'database':
                case 'dbname':
                    $tags[Tag::DB_NAME] = $value;
                    break;
                case 'server':
                case 'unix_socket':
                case 'hostname':
                case 'host':
                    $tags[Tag::TARGET_HOST] = $value;
                    break;
                case 'port':
                    $tags[Tag::TARGET_PORT] = $value;
                    break;
                case 'driver':
                    // This is more specific than just "odbc"
                    $tags[Tag::DB_SYSTEM] = strtolower($value);
                    break;
            }
        }

        return $tags;
    }

    public static function injectDBIntegration($pdo, $hook)
    {
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driver === "odbc") {
            $cached_driver = ObjectKVStore::get($pdo, PDOIntegration::CONNECTION_TAGS_KEY, []);
            // This particular driver is not supported for DBM
            if (isset($cached_driver[Tag::DB_SYSTEM]) && $cached_driver[Tag::DB_SYSTEM] === "ingres") {
                return;
            }
        }
        DatabaseIntegrationHelper::injectDatabaseIntegrationData($hook, $driver);
    }

    public static function extractConnectionMetadata(array $constructorArgs)
    {
        $tags = self::parseDsn($constructorArgs[0]);
        if (isset($constructorArgs[1])) {
            $tags['db.user'] = $constructorArgs[1];
        }
        return $tags;
    }

    /**
     * @param PDO|PDOStatement|array $source
     * @param DDTrace\SpanData $span
     */
    public static function setCommonSpanInfo($source, SpanData $span)
    {
        if (\is_array($source)) {
            $storedConnectionInfo = $source;
        } else {
            $storedConnectionInfo = ObjectKVStore::get($source, PDOIntegration::CONNECTION_TAGS_KEY, []);
        }
        if (!\is_array($storedConnectionInfo)) {
            $storedConnectionInfo = [];
        }

        $span->type = Type::SQL;
        $span->meta[Tag::SPAN_KIND] = 'client';
        $span->meta[Tag::COMPONENT] = PDOIntegration::NAME;
        if (\dd_trace_env_config("DD_TRACE_DB_CLIENT_SPLIT_BY_INSTANCE") &&
                isset($storedConnectionInfo[Tag::TARGET_HOST])
        ) {
            Integration::handleInternalSpanServiceName($span, PDOIntegration::NAME, true);
            $span->service = $span->service
                . '-' . \DDTrace\Util\Normalizer::normalizeHostUdsAsService($storedConnectionInfo[Tag::TARGET_HOST]);
        } else {
            Integration::handleInternalSpanServiceName($span, PDOIntegration::NAME);
        }

        foreach ($storedConnectionInfo as $tag => $value) {
            $span->meta[$tag] = $value;
        }

        if (\dd_trace_env_config("DD_APPSEC_RASP_ENABLED") && function_exists('datadog\appsec\push_addresses')
            && !empty($span->resource) && !empty($storedConnectionInfo[Tag::DB_SYSTEM])) {
        }
    }

    /**
     * @param PDO $source
     * @param DDTrace\SpanData $span
     */
    public static function handleRasp(\PDO $source, SpanData $span)
    {
        static $raspEnabled = null;
        if ($raspEnabled === null) {
            $raspEnabled = \dd_trace_env_config("DD_APPSEC_RASP_ENABLED") &&
                function_exists('datadog\appsec\push_addresses');
        }

        if (!$raspEnabled) {
            return;
        }

        $storedConnectionInfo = ObjectKVStore::get($source, PDOIntegration::CONNECTION_TAGS_KEY, []);
        if (!\is_array($storedConnectionInfo) || empty($storedConnectionInfo[Tag::DB_SYSTEM])) {
            return;
        }

        $addresses = array(
            'server.db.statement' => $span->resource,
            'server.db.system' => $storedConnectionInfo[Tag::DB_SYSTEM],
        );
        \datadog\appsec\push_addresses($addresses, "sqli");
    }
}
