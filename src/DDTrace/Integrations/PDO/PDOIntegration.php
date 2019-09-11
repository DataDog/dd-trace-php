<?php

namespace DDTrace\Integrations\PDO;

use DDTrace\Integrations\Integration;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\GlobalTracer;

class PDOIntegration extends Integration
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

        /**
         * Workaround for the Drupal DBAL bug.
         * Delete this `if` block once the bug is fixed.
         * @see https://github.com/DataDog/dd-trace-php/pull/284
         */
        if (defined('DRUPAL_CORE_COMPATIBILITY')) {
            return Integration::NOT_AVAILABLE;
        }

        // public PDO::__construct ( string $dsn [, string $username [, string $passwd [, array $options ]]] )
        dd_trace('PDO', '__construct', function () {
            $tracer = GlobalTracer::get();
            if ($tracer->limited()) {
                return dd_trace_forward_call();
            }

            $scope = $tracer->startIntegrationScopeAndSpan(
                PDOIntegration::getInstance(),
                'PDO.__construct'
            );
            $span = $scope->getSpan();
            $span->setTag(Tag::SPAN_TYPE, Type::SQL);
            $span->setTag(Tag::SERVICE_NAME, 'PDO');
            $span->setTag(Tag::RESOURCE_NAME, 'PDO.__construct');

            // PHP 5.4 compatible try-catch-finally
            $thrown = null;
            try {
                dd_trace_forward_call();
                PDOIntegration::detectError($span, $this);
            } catch (\Exception $e) {
                PDOIntegration::setErrorOnException($span, $e);
                $thrown = $e;
            }

            if (!$thrown || $thrown instanceof \PDOException) {
                PDOIntegration::storeConnectionParams($this, func_get_args());
                PDOIntegration::setConnectionTags($this, $span);
            }

            $scope->close();
            if ($thrown) {
                throw $thrown;
            }

            return $this;
        });

        // public int PDO::exec(string $query)
        dd_trace('PDO', 'exec', function ($statement) {
            $tracer = GlobalTracer::get();
            if ($tracer->limited()) {
                return dd_trace_forward_call();
            }

            $scope = $tracer->startIntegrationScopeAndSpan(PDOIntegration::getInstance(), 'PDO.exec');
            $span = $scope->getSpan();
            $span->setTag(Tag::SPAN_TYPE, Type::SQL);
            $span->setTag(Tag::SERVICE_NAME, 'PDO');
            $span->setTag(Tag::RESOURCE_NAME, $statement);
            $span->setTraceAnalyticsCandidate();
            PDOIntegration::setConnectionTags($this, $span);

            // PHP 5.4 compatible try-catch-finally
            $thrown = null;
            $result = null;
            try {
                $result = dd_trace_forward_call();
                PDOIntegration::detectError($span, $this);
                if (is_numeric($result)) {
                    $span->setTag('db.rowcount', $result);
                }
            } catch (\Exception $e) {
                PDOIntegration::setErrorOnException($span, $e);
                $thrown = $e;
            }

            $scope->close();
            if ($thrown) {
                throw $thrown;
            }

            return $result;
        });

        // public PDOStatement PDO::query(string $query)
        // public PDOStatement PDO::query(string $query, int PDO::FETCH_COLUMN, int $colno)
        // public PDOStatement PDO::query(string $query, int PDO::FETCH_CLASS, string $classname, array $ctorargs)
        // public PDOStatement PDO::query(string $query, int PDO::FETCH_INFO, object $object)
        // public int PDO::exec(string $query)
        dd_trace('PDO', 'query', function () {
            $tracer = GlobalTracer::get();
            if ($tracer->limited()) {
                return dd_trace_forward_call();
            }

            $scope = $tracer->startIntegrationScopeAndSpan(PDOIntegration::getInstance(), 'PDO.query');
            $args = func_get_args();
            $span = $scope->getSpan();
            $span->setTag(Tag::SPAN_TYPE, Type::SQL);
            $span->setTag(Tag::SERVICE_NAME, 'PDO');
            $span->setTag(Tag::RESOURCE_NAME, $args[0]);
            $span->setTraceAnalyticsCandidate();
            PDOIntegration::setConnectionTags($this, $span);

            // PHP 5.4 compatible try-catch-finally
            $thrown = null;
            $result = null;
            try {
                $result = dd_trace_forward_call();
                PDOIntegration::detectError($span, $this);
                PDOIntegration::storeStatementFromConnection($this, $result);
                try {
                    if ($result instanceof \PDOStatement) {
                        $span->setTag('db.rowcount', $result->rowCount());
                    }
                } catch (\Exception $e) {
                }
            } catch (\Exception $e) {
                PDOIntegration::setErrorOnException($span, $e);
                $thrown = $e;
            }

            $scope->close();
            if ($thrown) {
                throw $thrown;
            }

            return $result;
        });

        // public bool PDO::commit ( void )
        dd_trace('PDO', 'commit', function () {
            $tracer = GlobalTracer::get();
            if ($tracer->limited()) {
                return dd_trace_forward_call();
            }

            $scope = $tracer->startIntegrationScopeAndSpan(PDOIntegration::getInstance(), 'PDO.commit');
            $span = $scope->getSpan();
            $span->setTag(Tag::SPAN_TYPE, Type::SQL);
            $span->setTag(Tag::SERVICE_NAME, 'PDO');
            PDOIntegration::setConnectionTags($this, $span);

            // PHP 5.4 compatible try-catch-finally
            $thrown = null;
            $result = null;
            try {
                $result = dd_trace_forward_call();
                PDOIntegration::detectError($span, $this);
            } catch (\Exception $e) {
                PDOIntegration::setErrorOnException($span, $e);
                $thrown = $e;
            }

            $scope->close();
            if ($thrown) {
                throw $thrown;
            }

            return $result;
        });

        // public PDOStatement PDO::prepare ( string $statement [, array $driver_options = array() ] )
        dd_trace('PDO', 'prepare', function () {
            $tracer = GlobalTracer::get();
            if ($tracer->limited()) {
                return dd_trace_forward_call();
            }

            $args = func_get_args();
            $scope = $tracer->startIntegrationScopeAndSpan(PDOIntegration::getInstance(), 'PDO.prepare');
            $span = $scope->getSpan();
            $span->setTag(Tag::SPAN_TYPE, Type::SQL);
            $span->setTag(Tag::SERVICE_NAME, 'PDO');
            $span->setTag(Tag::RESOURCE_NAME, $args[0]);
            PDOIntegration::setConnectionTags($this, $span);

            // PHP 5.4 compatible try-catch-finally
            $thrown = null;
            $result = null;
            try {
                $result = dd_trace_forward_call();
                PDOIntegration::storeStatementFromConnection($this, $result);
            } catch (\Exception $e) {
                PDOIntegration::setErrorOnException($span, $e);
                $thrown = $e;
            }

            $scope->close();
            if ($thrown) {
                throw $thrown;
            }

            return $result;
        });

        // public bool PDOStatement::execute ([ array $input_parameters ] )
        dd_trace('PDOStatement', 'execute', function () {
            $tracer = GlobalTracer::get();
            if ($tracer->limited()) {
                return dd_trace_forward_call();
            }

            $scope = $tracer->startIntegrationScopeAndSpan(
                PDOIntegration::getInstance(),
                'PDOStatement.execute'
            );
            $span = $scope->getSpan();
            $span->setTag(Tag::SPAN_TYPE, Type::SQL);
            $span->setTag(Tag::SERVICE_NAME, 'PDO');
            $span->setTag(Tag::RESOURCE_NAME, $this->queryString);
            $span->setTraceAnalyticsCandidate();
            PDOIntegration::setStatementTags($this, $span);

            // PHP 5.4 compatible try-catch-finally
            $thrown = null;
            $result = null;
            try {
                $result = dd_trace_forward_call();
                PDOIntegration::detectError($span, $this);
                try {
                    if ($result === true) {
                        $span->setTag('db.rowcount', $this->rowCount());
                    }
                } catch (\Exception $e) {
                }
            } catch (\Exception $e) {
                PDOIntegration::setErrorOnException($span, $e);
                $thrown = $e;
            }

            $scope->close();
            if ($thrown) {
                throw $thrown;
            }

            return $result;
        });

        return Integration::LOADED;
    }

    /**
     * @param \DDTrace\Span $span
     * @param \PDO|\PDOStatement $pdo_or_statement
     */
    public static function detectError($span, $pdo_or_statement)
    {
        $errorCode = null;

        try {
            $errorCode = $pdo_or_statement->errorCode();
        } catch (\Exception $e) {
            $span->setRawError(
                "SQL error: couldn't get error code",
                get_class($pdo_or_statement) . ' error'
            );
            return;
        }
        // Error codes follows the ANSI SQL-92 convention of 5 total chars:
        //   - 2 chars for class value
        //   - 3 chars for subclass value
        // Non error class values are: '00', '01', 'IM'
        // @see: http://php.net/manual/en/pdo.errorcode.php
        if (strlen($errorCode) != 5) {
            return;
        }

        $class = strtoupper(substr($errorCode, 0, 2));
        if (in_array($class, ['00', '01', 'IM'])) {
            // Not an error
            return;
        }
        $errorInfo = $pdo_or_statement->errorInfo();
        $span->setRawError(
            'SQL error: ' . $errorCode . '. Driver error: ' . $errorInfo[1],
            get_class($pdo_or_statement) . ' error'
        );
    }

    /**
     * @param \DDTrace\Span $span
     * @param \PDO $pdo
     * @param null $exception
     */
    public static function setErrorOnException($span, $exception)
    {
        $span->setRawError(
            self::extractErrorInfo($exception->getMessage()),
            get_class($exception)
        );
    }

    private static function extractErrorInfo($message)
    {
        $matches = [];
        $isKnownFormat = preg_match('/^(SQLSTATE\[\w.*\] \[\d.*\]).*/', $message, $matches);
        return $isKnownFormat ? ('Sql error: ' . $matches[1]) : 'Sql error';
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
        $hash = is_object($pdo) ? spl_object_hash($pdo) : '';
        if (count($constructorArgs) > 0) {
            $tags = self::parseDsn($constructorArgs[0]);
            if (isset($constructorArgs[1])) {
                $tags['db.user'] = $constructorArgs[1];
            }
            self::$connections[$hash] = $tags;
        } else {
            self::$connections[$hash] = array();
        }
    }

    public static function storeStatementFromConnection($pdo, $stmt)
    {
        if (!$stmt) {
            // When an error occurs 'FALSE' will be returned in place of the statement.
            return;
        }
        $pdoHash = is_object($pdo) ? spl_object_hash($pdo) : '';
        if (isset(self::$connections[$pdoHash])) {
            self::$statements[is_object($stmt) ? spl_object_hash($stmt) : ''] = $pdoHash;
        }
    }

    public static function setConnectionTags($pdo, $span)
    {
        $hash = is_object($pdo) ? spl_object_hash($pdo) : '';
        if (!isset(self::$connections[$hash])) {
            return;
        }

        foreach (self::$connections[$hash] as $tag => $value) {
            $span->setTag($tag, $value);
        }
    }

    public static function setStatementTags($stmt, $span)
    {
        $stmtHash = is_object($stmt) ? spl_object_hash($stmt) : '';
        if (!isset(self::$statements[$stmtHash])) {
            return;
        }
        if (!isset(self::$connections[self::$statements[$stmtHash]])) {
            return;
        }

        foreach (self::$connections[self::$statements[$stmtHash]] as $tag => $value) {
            $span->setTag($tag, $value);
        }
    }
}
