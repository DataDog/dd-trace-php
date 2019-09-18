<?php

namespace DDTrace\Integrations\PDO;

use DDTrace\Integrations\SandboxedIntegration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;

class PDOSandboxedIntegration extends SandboxedIntegration
{
    const NAME = 'pdo';

    /**
     * @var array
     */
    private static $connections = [];

    /**
     * @var array
     */
    private static $statements = [];

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
            return SandboxedIntegration::NOT_AVAILABLE;
        }

        $integration = $this;

        // public PDO::__construct ( string $dsn [, string $username [, string $passwd [, array $options ]]] )
        dd_trace_method('PDO', '__construct', function (SpanData $span, array $args) {
            if (dd_trace_tracer_is_limited()) {
                return false;
            }
            $span->name = $span->resource = 'PDO.__construct';
            $span->service = 'PDO';
            $span->type = Type::SQL;
            $span->meta = PDOSandboxedIntegration::storeConnectionParams($this, $args);
        });

        // public int PDO::exec(string $query)
        dd_trace_method('PDO', 'exec', function (SpanData $span, array $args, $retval) use ($integration) {
            if (dd_trace_tracer_is_limited()) {
                return false;
            }
            $span->name = 'PDO.exec';
            $span->service = 'PDO';
            $span->type = Type::SQL;
            $span->resource = (string) $args[0];
            if (is_numeric($retval)) {
                $span->meta = [
                    'db.rowcount' => (string) $retval,
                ];
            }
            PDOSandboxedIntegration::setConnectionTags($this, $span);
            $integration->addTraceAnalyticsIfEnabled($span);
            PDOSandboxedIntegration::detectError($this, $span);
        });

        // public PDOStatement PDO::query(string $query)
        // public PDOStatement PDO::query(string $query, int PDO::FETCH_COLUMN, int $colno)
        // public PDOStatement PDO::query(string $query, int PDO::FETCH_CLASS, string $classname, array $ctorargs)
        // public PDOStatement PDO::query(string $query, int PDO::FETCH_INFO, object $object)
        // public int PDO::exec(string $query)
        dd_trace_method('PDO', 'query', function (SpanData $span, array $args, $retval) use ($integration) {
            if (dd_trace_tracer_is_limited()) {
                return false;
            }
            $span->name = 'PDO.query';
            $span->service = 'PDO';
            $span->type = Type::SQL;
            $span->resource = (string) $args[0];
            if ($retval instanceof \PDOStatement) {
                $span->meta = [
                    'db.rowcount' => (string) $retval->rowCount(),
                ];
                PDOSandboxedIntegration::storeStatementFromConnection($this, $retval);
            }
            PDOSandboxedIntegration::setConnectionTags($this, $span);
            $integration->addTraceAnalyticsIfEnabled($span);
            PDOSandboxedIntegration::detectError($this, $span);
        });

        // public bool PDO::commit ( void )
        dd_trace_method('PDO', 'commit', function (SpanData $span) {
            if (dd_trace_tracer_is_limited()) {
                return false;
            }
            $span->name = $span->resource = 'PDO.commit';
            $span->service = 'PDO';
            $span->type = Type::SQL;
            PDOSandboxedIntegration::setConnectionTags($this, $span);
        });

        // public PDOStatement PDO::prepare ( string $statement [, array $driver_options = array() ] )
        dd_trace_method('PDO', 'prepare', function (SpanData $span, array $args, $retval) {
            if (dd_trace_tracer_is_limited()) {
                return false;
            }
            $span->name = 'PDO.prepare';
            $span->service = 'PDO';
            $span->type = Type::SQL;
            $span->resource = (string) $args[0];
            PDOSandboxedIntegration::setConnectionTags($this, $span);
            PDOSandboxedIntegration::storeStatementFromConnection($this, $retval);
        });

        // public bool PDOStatement::execute ([ array $input_parameters ] )
        dd_trace_method('PDOStatement', 'execute', function (SpanData $span, array $args, $retval) use ($integration) {
            if (dd_trace_tracer_is_limited()) {
                return false;
            }
            $span->name = 'PDOStatement.execute';
            $span->service = 'PDO';
            $span->type = Type::SQL;
            $span->resource = (string) $this->queryString;
            if ($retval === true) {
                $span->meta = [
                    'db.rowcount' => (string) $this->rowCount(),
                ];
            }
            PDOSandboxedIntegration::setStatementTags($this, $span);
            $integration->addTraceAnalyticsIfEnabled($span);
            PDOSandboxedIntegration::detectError($this, $span);
        });

        return SandboxedIntegration::LOADED;
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
            switch ($key) {
                case 'charset':
                    $tags['db.charset'] = $value;
                    break;
                case 'dbname':
                    $tags['db.name'] = $value;
                    break;
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

    public static function storeConnectionParams($pdo, array $constructorArgs)
    {
        $tags = self::parseDsn($constructorArgs[0]);
        if (isset($constructorArgs[1])) {
            $tags['db.user'] = (string) $constructorArgs[1];
        }
        self::$connections[spl_object_hash($pdo)] = $tags;
        return $tags;
    }

    public static function storeStatementFromConnection($pdo, $stmt)
    {
        if (!$stmt instanceof \PDOStatement) {
            // When an error occurs 'FALSE' will be returned in place of the statement.
            return;
        }
        $pdoHash = spl_object_hash($pdo);
        if (isset(self::$connections[$pdoHash])) {
            self::$statements[spl_object_hash($stmt)] = $pdoHash;
        }
    }

    public static function setConnectionTags($pdo, SpanData $span)
    {
        $hash = spl_object_hash($pdo);
        if (!isset(self::$connections[$hash])) {
            return;
        }
        foreach (self::$connections[$hash] as $tag => $value) {
            $span->meta[$tag] = (string) $value;
        }
    }

    public static function setStatementTags($stmt, SpanData $span)
    {
        $stmtHash = spl_object_hash($stmt);
        if (!isset(self::$statements[$stmtHash])) {
            return;
        }
        if (!isset(self::$connections[self::$statements[$stmtHash]])) {
            return;
        }
        foreach (self::$connections[self::$statements[$stmtHash]] as $tag => $value) {
            $span->meta[$tag] = (string) $value;
        }
    }
}
