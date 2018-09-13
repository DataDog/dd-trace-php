<?php

namespace DDTrace\Integrations;

use DDTrace\Tags;
use DDTrace\Types;
use OpenTracing\GlobalTracer;

class PDO
{
    /**
     * Static method to add instrumentation to PDO requests
     */
    public static function load()
    {
        if (!extension_loaded('ddtrace')) {
            trigger_error('ddtrace extension required to load PDO integration.', E_USER_WARNING);
            return;
        }

        // public int PDO::exec(string $query)
        dd_trace('PDO', 'exec', function ($statement) {
            $scope = GlobalTracer::get()->startActiveSpan('PDO.exec');
            $span = $scope->getSpan();
            $span->setTag(Tags\SPAN_TYPE, Types\SQL);
            $span->setTag(Tags\SERVICE_NAME, 'PDO');
            $span->setTag(Tags\DB_STATEMENT, $statement);
            $span->setResource($statement);

            $result = $this->exec($statement);

            $span->setTag('db.rowcount', $result);
            $scope->close();

            return $result;
        });

        // public PDOStatement PDO::query(string $query)
        // public PDOStatement PDO::query(string $query, int PDO::FETCH_COLUMN, int $colno)
        // public PDOStatement PDO::query(string $query, int PDO::FETCH_CLASS, string $classname, array $ctorargs)
        // public PDOStatement PDO::query(string $query, int PDO::FETCH_INFO, object $object)
        // public int PDO::exec(string $query)
        dd_trace('PDO', 'query', function (...$args) {
            $scope = GlobalTracer::get()->startActiveSpan('PDO.query');
            $span = $scope->getSpan();
            $span->setTag(Tags\SPAN_TYPE, Types\SQL);
            $span->setTag(Tags\SERVICE_NAME, 'PDO');
            $span->setTag(Tags\DB_STATEMENT, $args[0]);
            $span->setResource($args[0]);

            $e = null;
            try {
                $result = $this->query($args[0], isset($args[1]) ? $args[1] : null, isset($args[2]) ? $args2 : null, isset($args[3]) ? $args3 : null);
                $span->setTag('db.rowcount', $result->rowCount());
            } catch (Exception $e) {
                $span->setError($e);
            }

            $scope->close();

            if (is_null($e)) {
                return $result;
            } else {
                throw $e;
            }
        });

        // public bool PDO::commit ( void )
        dd_trace('PDO', 'commit', function () {
            $scope = GlobalTracer::get()->startActiveSpan('PDO.commit');
            $span = $scope->getSpan();
            $span->setTag(Tags\SPAN_TYPE, Types\SQL);
            $span->setTag(Tags\SERVICE_NAME, 'PDO');

            $result = $this->commit();

            $scope->close();

            return $result;
        });

        // public bool PDOStatement::execute([array $params])
        dd_trace('PDOStatement', 'execute', function ($params) {
            $scope = GlobalTracer::get()->startActiveSpan('PDOStatement.execute');
            $span = $scope->getSpan();
            $span->setTag(Tags\SPAN_TYPE, Types\SQL);
            $span->setTag(Tags\SERVICE_NAME, 'PDO');
            $span->setTag(Tags\DB_STATEMENT, $this->queryString);
            $span->setResource($this->queryString);

            $result = $this->execute($params);

            $span->setTag('db.rowcount', $this->rowCount());
            $scope->close();

            return $result;
        });
    }
}
