<?php

namespace DDTrace\Integrations\PDO;

use DDTrace\Integrations\Integration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\Util\ObjectKVStore;

class PDOIntegration extends Integration
{
    const NAME = 'pdo';

    const CONNECTION_TAGS_KEY = 'connection_tags';

    /**
     * @return string The integration name.
     */
    public function getName()
    {
        return self::NAME;
    }

    /**
     * Add instrumentation to PDO requests
     */
    public function init()
    {
        if (!extension_loaded('PDO')) {
            // PDO is provided through an extension and not through a class loader.
            return Integration::NOT_AVAILABLE;
        }

        $integration = $this;

        // public PDO::__construct ( string $dsn [, string $username [, string $passwd [, array $options ]]] )
        \DDTrace\trace_method('PDO', '__construct', function (SpanData $span, array $args) {
            $span->name = $span->resource = 'PDO.__construct';
            $connectionMetadata = PDOIntegration::extractConnectionMetadata($args);
            ObjectKVStore::put($this, PDOIntegration::CONNECTION_TAGS_KEY, $connectionMetadata);
            // We have to use $connectionMetadata as a medium, instead of $this (aka the PDO instance) because in
            // PHP 5.* $this is NULL in this callback when there is a connection error.
            PDOIntegration::setCommonSpanInfo($connectionMetadata, $span);
        });

        // public int PDO::exec(string $query)
        \DDTrace\trace_method('PDO', 'exec', function (SpanData $span, array $args, $retval) use ($integration) {
            $span->name = 'PDO.exec';
            $span->resource = Integration::toString($args[0]);
            if (is_numeric($retval)) {
                $span->meta['db.rowcount'] = $retval;
            }
            PDOIntegration::setCommonSpanInfo($this, $span);
            $integration->addTraceAnalyticsIfEnabled($span);
            PDOIntegration::detectError($this, $span);
        });

        // public PDOStatement PDO::query(string $query)
        // public PDOStatement PDO::query(string $query, int PDO::FETCH_COLUMN, int $colno)
        // public PDOStatement PDO::query(string $query, int PDO::FETCH_CLASS, string $classname, array $ctorargs)
        // public PDOStatement PDO::query(string $query, int PDO::FETCH_INFO, object $object)
        // public int PDO::exec(string $query)
        \DDTrace\trace_method('PDO', 'query', function (SpanData $span, array $args, $retval) use ($integration) {
            $span->name = 'PDO.query';
            $span->service = 'pdo';
            $span->type = Type::SQL;
            $span->resource = Integration::toString($args[0]);
            if ($retval instanceof \PDOStatement) {
                $span->meta['db.rowcount'] = $retval->rowCount();
                ObjectKVStore::propagate($this, $retval, PDOIntegration::CONNECTION_TAGS_KEY);
            }
            PDOIntegration::setCommonSpanInfo($this, $span);
            $integration->addTraceAnalyticsIfEnabled($span);
            PDOIntegration::detectError($this, $span);
        });

        // public bool PDO::commit ( void )
        \DDTrace\trace_method('PDO', 'commit', function (SpanData $span) {
            $span->name = $span->resource = 'PDO.commit';
            PDOIntegration::setCommonSpanInfo($this, $span);
        });

        // public PDOStatement PDO::prepare ( string $statement [, array $driver_options = array() ] )
        \DDTrace\trace_method('PDO', 'prepare', function (SpanData $span, array $args, $retval) {
            $span->name = 'PDO.prepare';
            $span->resource = Integration::toString($args[0]);
            ObjectKVStore::propagate($this, $retval, PDOIntegration::CONNECTION_TAGS_KEY);
            PDOIntegration::setCommonSpanInfo($this, $span);
        });

        // public bool PDOStatement::execute ([ array $input_parameters ] )
        \DDTrace\trace_method(
            'PDOStatement',
            'execute',
            function (SpanData $span, array $args, $retval) use ($integration) {
                $span->name = 'PDOStatement.execute';
                $span->service = 'pdo';
                $span->type = Type::SQL;
                $span->resource = $this->queryString;
                if ($retval === true) {
                    $span->meta['db.rowcount'] = $this->rowCount();
                }
                PDOIntegration::setCommonSpanInfo($this, $span);
                $integration->addTraceAnalyticsIfEnabled($span);
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
        $valStrings = explode(';', substr($dsn, strlen($engine) + 1));
        foreach ($valStrings as $valString) {
            if (!strpos($valString, '=')) {
                continue;
            }
            list($key, $value) = explode('=', $valString);
            switch (strtolower($key)) {
                case 'charset':
                    $tags['db.charset'] = $value;
                    break;
                case 'database':
                case 'dbname':
                    $tags['db.name'] = $value;
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
            }
        }

        return $tags;
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
        $span->service = 'pdo';
        $span->meta[Tag::SPAN_KIND] = 'client';
        $span->meta[Tag::COMPONENT] = PDOIntegration::NAME;
        if (\DDTrace\Util\Runtime::getBoolIni("datadog.trace.db_client_split_by_instance")) {
            if (isset($storedConnectionInfo[Tag::TARGET_HOST])) {
                $span->service .=
                    '-' . \DDTrace\Util\Normalizer::normalizeHostUdsAsService($storedConnectionInfo[Tag::TARGET_HOST]);
            }
        }

        foreach ($storedConnectionInfo as $tag => $value) {
            $span->meta[$tag] = $value;
        }
    }
}
