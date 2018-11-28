<?php

namespace DDTrace\Integrations\Eloquent;

use DDTrace\Tags;
use DDTrace\Types;
use OpenTracing\GlobalTracer;


class EloquentIntegration
{
    public static function load()
    {
        if (!extension_loaded('ddtrace')) {
            trigger_error('The ddtrace extension is required to instrument Eloquent', E_USER_WARNING);
            return;
        }
        if (!class_exists('Illuminate\Database\Eloquent\Builder')) {
            trigger_error('Eloquent is not loaded and cannot be instrumented', E_USER_WARNING);
            return;
        }

        // getModels($columns = ['*'])
        dd_trace('Illuminate\Database\Eloquent\Builder', 'getModels', function () {
            $args = func_get_args();
            $scope = GlobalTracer::get()->startActiveSpan('eloquent.get');
            $span = $scope->getSpan();
            $sql = $this->getQuery()->toSql();
            $span->setTag(Tags\RESOURCE_NAME, $sql);
            $span->setTag(Tags\DB_STATEMENT, $sql);
            $span->setTag(Tags\SPAN_TYPE, Types\SQL);

            try {
                return call_user_func_array([$this, 'getModels'], $args);
            } catch (\Exception $e) {
                $span->setError($e);
                throw $e;
            } finally {
                $scope->close();
            }
        });

        // performInsert(Builder $query)
        dd_trace('Illuminate\Database\Eloquent\Model', 'performInsert', function () {
            $args = func_get_args();
            $eloquentQueryBuilder = $args[0];
            $scope = GlobalTracer::get()->startActiveSpan('eloquent.insert');
            $span = $scope->getSpan();
            $sql = $eloquentQueryBuilder->getQuery()->toSql();
            $span->setTag(Tags\RESOURCE_NAME, $sql);
            $span->setTag(Tags\DB_STATEMENT, $sql);
            $span->setTag(Tags\SPAN_TYPE, Types\SQL);

            try {
                // Eloquent for 4.2 can receive $options
                return call_user_func_array([$this, 'performInsert'], $args);
            } catch (\Exception $e) {
                $span->setError($e);
                throw $e;
            } finally {
                $scope->close();
            }
        });

        // performUpdate(Builder $query)
        dd_trace('Illuminate\Database\Eloquent\Model', 'performUpdate', function () {
            $args = func_get_args();
            $eloquentQueryBuilder = $args[0];
            $scope = GlobalTracer::get()->startActiveSpan('eloquent.update');
            $span = $scope->getSpan();
            $sql = $eloquentQueryBuilder->getQuery()->toSql();
            $span->setTag(Tags\RESOURCE_NAME, $sql);
            $span->setTag(Tags\DB_STATEMENT, $sql);
            $span->setTag(Tags\SPAN_TYPE, Types\SQL);

            try {
                return call_user_func_array([$this, 'performUpdate'], $args);
            } catch (\Exception $e) {
                $span->setError($e);
                throw $e;
            } finally {
                $scope->close();
            }
        });

        // public function delete()
        dd_trace('Illuminate\Database\Eloquent\Model', 'delete', function () {
            $scope = GlobalTracer::get()->startActiveSpan('eloquent.delete');
            $scope->getSpan()->setTag(Tags\SPAN_TYPE, Types\SQL);

            try {
                return $this->delete();
            } catch (\Exception $e) {
                $span = $scope->getSpan();
                $span->setError($e);
                throw $e;
            } finally {
                $scope->close();
            }
        });
    }
}
