<?php

namespace DDTrace\Integrations\PDO;

use DDTrace\Integrations\Integration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;

class PDOSandboxedIntegration extends Integration
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
     * @var self
     */
    private static $instance;

    /**
     * @return self
     */
    public static function getInstance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * @return string The integration name.
     */
    public function getName()
    {
        return self::NAME;
    }

    /**
     * Static method to add instrumentation to PDO requests
     */
    public static function load()
    {
        if (!extension_loaded('PDO')) {
            // PDO is provided through an extension and not through a class loader.
            return Integration::NOT_AVAILABLE;
        }

        // public PDO::__construct ( string $dsn [, string $username [, string $passwd [, array $options ]]] )
        dd_trace_method('PDO', '__construct', function (SpanData $span, array $args) {
            $span->name = $span->resource = 'PDO.__construct';
            $span->service = 'PDO';
            $span->type = Type::SQL;
            $span->meta = PDOSandboxedIntegration::storeConnectionParams($this, $args);
        });

        // public int PDO::exec(string $query)
        dd_trace_method('PDO', 'exec', function (SpanData $span, array $args, $retval) {
            $span->name = 'PDO.exec';
            $span->resource = $args[0];
            $span->service = 'PDO';
            $span->type = Type::SQL;
            $span->meta = [
                'db.rowcount' => (string) $retval,
            ];
            PDOSandboxedIntegration::setConnectionTags($this, $span);
            PDOSandboxedIntegration::getInstance()->addTraceAnalyticsIfEnabled($span);
        });

        // public PDOStatement PDO::query(string $query)
        // public PDOStatement PDO::query(string $query, int PDO::FETCH_COLUMN, int $colno)
        // public PDOStatement PDO::query(string $query, int PDO::FETCH_CLASS, string $classname, array $ctorargs)
        // public PDOStatement PDO::query(string $query, int PDO::FETCH_INFO, object $object)
        // public int PDO::exec(string $query)
        dd_trace_method('PDO', 'query', function (SpanData $span, array $args, $retval) {
            $span->name = 'PDO.query';
            $span->resource = $args[0];
            $span->service = 'PDO';
            $span->type = Type::SQL;
            if ($retval instanceof \PDOStatement) {
                $span->meta = [
                    'db.rowcount' => (string) $retval->rowCount(),
                ];
                PDOSandboxedIntegration::storeStatementFromConnection($this, $retval);
            }
            PDOSandboxedIntegration::setConnectionTags($this, $span);
            PDOSandboxedIntegration::getInstance()->addTraceAnalyticsIfEnabled($span);
        });

        // public bool PDO::commit ( void )
        dd_trace_method('PDO', 'commit', function (SpanData $span) {
            $span->name = $span->resource = 'PDO.commit';
            $span->service = 'PDO';
            $span->type = Type::SQL;
            PDOSandboxedIntegration::setConnectionTags($this, $span);
        });

        // public PDOStatement PDO::prepare ( string $statement [, array $driver_options = array() ] )
        dd_trace_method('PDO', 'prepare', function (SpanData $span, array $args, $retval) {
            $span->name = 'PDO.prepare';
            $span->resource = $args[0];
            $span->service = 'PDO';
            $span->type = Type::SQL;
            PDOSandboxedIntegration::setConnectionTags($this, $span);
            PDOSandboxedIntegration::storeStatementFromConnection($this, $retval);
        });

        // public bool PDOStatement::execute ([ array $input_parameters ] )
        dd_trace_method('PDOStatement', 'execute', function (SpanData $span) {
            $span->name = 'PDOStatement.execute';
            $span->resource = $this->queryString;
            $span->service = 'PDO';
            $span->type = Type::SQL;
            $span->meta = [
                'db.rowcount' => (string) $this->rowCount(),
            ];
            PDOSandboxedIntegration::setStatementTags($this, $span);
            PDOSandboxedIntegration::getInstance()->addTraceAnalyticsIfEnabled($span);
        });

        return Integration::LOADED;
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
            $tags['db.user'] = $constructorArgs[1];
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
            $span->meta[$tag] = $value;
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
            $span->meta[$tag] = $value;
        }
    }
}
