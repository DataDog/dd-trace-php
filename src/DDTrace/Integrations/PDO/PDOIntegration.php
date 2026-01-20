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

    const DB_DRIVER_TO_SYSTEM = [
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
            \DDTrace\trace_method('PDO', 'connect', static function (SpanData $span, array $args, $pdo) {
                Integration::handleOrphan($span);
                $span->name = $span->resource = 'PDO.connect';
                $connectionMetadata = self::extractConnectionMetadata($args);
                ObjectKVStore::put($pdo, self::CONNECTION_TAGS_KEY, $connectionMetadata);
                self::setCommonSpanInfo($connectionMetadata, $span);
            });
        }

        // public int PDO::exec(string $query)
        \DDTrace\install_hook('PDO::exec', static function (HookData $hook) {
            list($query) = $hook->args;

            $span = $hook->span();
            Integration::handleOrphan($span);
            $span->name = 'PDO.exec';
            $span->resource = Integration::toString($query);
            $span->peerServiceSources = DatabaseIntegrationHelper::PEER_SERVICE_SOURCES;
            $instance = $hook->instance;
            PDOIntegration::setCommonSpanInfo($instance, $span);
            PDOIntegration::addTraceAnalyticsIfEnabled($span);

            PDOIntegration::injectDBIntegration($instance, $hook);
            PDOIntegration::handleRasp($instance, $span);
        }, static function (HookData $hook) {
            $span = $hook->span();
            if (is_numeric($hook->returned)) {
                $span->metrics[Tag::DB_ROW_COUNT] = $hook->returned;
            }
            PDOIntegration::detectError($hook->instance, $span);
        });

        // public PDOStatement PDO::query(string $query)
        // public PDOStatement PDO::query(string $query, int PDO::FETCH_COLUMN, int $colno)
        // public PDOStatement PDO::query(string $query, int PDO::FETCH_CLASS, string $classname, array $ctorargs)
        // public PDOStatement PDO::query(string $query, int PDO::FETCH_INFO, object $object)
        // public int PDO::exec(string $query)
        \DDTrace\install_hook('PDO::query', static function (HookData $hook) {
            list($query) = $hook->args;

            $span = $hook->span();
            $span->name = 'PDO.query';
            $span->resource = Integration::toString($query);
            $span->peerServiceSources = DatabaseIntegrationHelper::PEER_SERVICE_SOURCES;
            $instance = $hook->instance;
            PDOIntegration::setCommonSpanInfo($instance, $span);
            PDOIntegration::addTraceAnalyticsIfEnabled($span);

            PDOIntegration::injectDBIntegration($instance, $hook);
            PDOIntegration::handleRasp($instance, $span);
        }, static function (HookData $hook) {
            $span = $hook->span();
            $instance = $hook->instance;
            if ($hook->returned instanceof \PDOStatement) {
                $span->metrics[Tag::DB_ROW_COUNT] = $hook->returned->rowCount();
                ObjectKVStore::propagate($instance, $hook->returned, PDOIntegration::CONNECTION_TAGS_KEY);
            }
            PDOIntegration::detectError($instance, $span);
        });

        // public PDOStatement PDO::prepare ( string $statement [, array $driver_options = array() ] )
        \DDTrace\install_hook('PDO::prepare', static function (HookData $hook) {
            list($query) = $hook->args;
            $hook->data = $query;

            $span = $hook->span();
            Integration::handleOrphan($span);
            $span->name = 'PDO.prepare';
            $span->resource = Integration::toString($query);
            $instance = $hook->instance;
            PDOIntegration::setCommonSpanInfo($instance, $span);

            PDOIntegration::injectDBIntegration($instance, $hook, true);
            PDOIntegration::handleRasp($instance, $span);
        }, static function (HookData $hook) {
            $pdo = $hook->returned;
            ObjectKVStore::propagate($hook->instance, $pdo, PDOIntegration::CONNECTION_TAGS_KEY);
            if ($pdo instanceof \PDOStatement) {
                \dd_trace_internal_fn("force_overwrite_property", $pdo, "queryString", $hook->data); // Restore the query string minus the DBM injected stuff
            }
        });

        // public bool PDO::commit ( void )
        \DDTrace\install_hook('PDO::commit', static function (HookData $hook) {
            $span = $hook->span();
            Integration::handleOrphan($span);
            $span->name = $span->resource = 'PDO.commit';
            PDOIntegration::setCommonSpanInfo($hook->instance, $span);
        });

        // public bool PDOStatement::execute ([ array $input_parameters ] )
        \DDTrace\install_hook(
            'PDOStatement::execute',
            static function (HookData $hook) {
                $hook->span();
            },
            static function (HookData $hook) {
                $span = $hook->span();
                $instance = $hook->instance;
                Integration::handleOrphan($span);
                $span->name = 'PDOStatement.execute';
                Integration::handleInternalSpanServiceName($span, PDOIntegration::NAME);
                $span->type = Type::SQL;
                $span->resource = $instance->queryString;
                $span->peerServiceSources = DatabaseIntegrationHelper::PEER_SERVICE_SOURCES;
                if ($hook->returned === true) {
                    try {
                        $span->metrics[Tag::DB_ROW_COUNT] = $instance->rowCount();
                    } catch (\Exception $e) {
                        // Ignore exception thrown by rowCount() method.
                        // Drupal PDOStatement::rowCount() method throws an exception if the query is not a SELECT.
                    }
                }
                PDOIntegration::setCommonSpanInfo($instance, $span);
                PDOIntegration::addTraceAnalyticsIfEnabled($span);
                PDOIntegration::detectError($instance, $span);
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

    const DSN_REGEX = <<<'REGEX'
(\A
    (?<engine>[^:]++):
    (?:
        (?:
             (?:server|unix_socket|host(?:name)?)=(?<host>(?:[^;]*+(?:;;)?)++)
            |port=(?<port>(?&host))
            |charset=(?<charset>(?&host))
            |(?:database|dbname)=(?<db>(?&host))
            |driver=(?<driver>(?&host))
            |(?&host) # host can actually be empty, supporting repeated or trailing semicolons
        )
        (?:;|\Z)
    )++
)xi
REGEX;

    private static function parseDsn($dsn)
    {
        if (\preg_match(self::DSN_REGEX, $dsn, $m)) {
            $engine = $m['engine']; // If uri: is used we'll also land here, but it's deprecated and we don't support it
            $db = $m['db'] ?? "";
            $charset = $m['charset'] ?? "";
            $host = $m['host'] ?? "";
            $port = $m['port'] ?? "";
            $driver = $m['driver'] ?? "";

            $dbSystem = self::DB_DRIVER_TO_SYSTEM[$engine] ?? 'other_sql';
            $tags = ['db.engine' => $engine];
            $tags[Tag::DB_SYSTEM] = $dbSystem;
            $tags[Tag::DB_TYPE] = $dbSystem;  // db.type is DD equivalent to db.system in OpenTelemetry, used for SQL spans obfuscation

            if ($db !== "") {
                $tags[Tag::DB_NAME] = $db;
            }
            if ($charset !== "") {
                $tags[Tag::DB_CHARSET] = $charset;
            }
            if ($host !== "") {
                $tags[Tag::TARGET_HOST] = $host;
            }
            if ($port !== "") {
                $tags[Tag::TARGET_PORT] = $port;
            }
            if ($driver !== "") {
                $tags[Tag::DB_SYSTEM] = strtolower($driver);
            }
        } elseif ($iniDsn = ini_get("pdo.dsn.$dsn")) {
            $tags = self::parseDsn($iniDsn);
        } else {
            // If we cannot find the ini
            $tags = [
                Tag::DB_SYSTEM => 'other_sql',
                Tag::DB_TYPE => 'other_sql',
            ];
        }
        return $tags;
    }

    public static function injectDBIntegration($pdo, $hook, $forcedMode = null)
    {
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driver === "odbc") {
            $cached_driver = ObjectKVStore::get($pdo, self::CONNECTION_TAGS_KEY, []);
            // This particular driver is not supported for DBM
            if (isset($cached_driver[Tag::DB_SYSTEM]) && $cached_driver[Tag::DB_SYSTEM] === "ingres") {
                return;
            }
        }
        DatabaseIntegrationHelper::injectDatabaseIntegrationData($hook, $driver, 0, $forcedMode);
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
            $storedConnectionInfo = ObjectKVStore::get($source, self::CONNECTION_TAGS_KEY, []);
        }
        if (!\is_array($storedConnectionInfo)) {
            $storedConnectionInfo = [];
        }

        $span->type = Type::SQL;
        $span->meta[Tag::SPAN_KIND] = 'client';
        $span->meta[Tag::COMPONENT] = self::NAME;
        if (\dd_trace_env_config("DD_TRACE_DB_CLIENT_SPLIT_BY_INSTANCE") &&
                isset($storedConnectionInfo[Tag::TARGET_HOST])
        ) {
            Integration::handleInternalSpanServiceName($span, self::NAME, true);
            $span->service = $span->service
                . '-' . \DDTrace\Util\Normalizer::normalizeHostUdsAsService($storedConnectionInfo[Tag::TARGET_HOST]);
        } else {
            Integration::handleInternalSpanServiceName($span, self::NAME);
        }

        foreach ($storedConnectionInfo as $tag => $value) {
            $span->meta[$tag] = $value;
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

        $storedConnectionInfo = ObjectKVStore::get($source, self::CONNECTION_TAGS_KEY, []);
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
