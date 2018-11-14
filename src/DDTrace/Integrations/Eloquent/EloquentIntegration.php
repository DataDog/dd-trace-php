<?php

namespace DDTrace\Integrations\Eloquent;

use DDTrace\Tags;
use DDTrace\Types;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use OpenTracing\GlobalTracer;


class EloquentIntegration
{
    public static function load()
    {
        if (!extension_loaded('ddtrace')) {
            trigger_error('The ddtrace extension is required to instrument Eloquent', E_USER_WARNING);
            return;
        }
        if (!class_exists(Builder::class)) {
            trigger_error('Eloquent is not loaded and connot be instrumented', E_USER_WARNING);
        }

        // getModels($columns = ['*'])
        dd_trace(Builder::class, 'getModels', function (...$args) {
            $scope = GlobalTracer::get()->startActiveSpan('eloquent.get');
            $span = $scope->getSpan();
            $sql = $this->toBase()->toSql();
            $span->setResource($sql);
            $span->setTag(Tags\DB_STATEMENT, $sql);
            $span->setTag(Tags\SPAN_TYPE, Types\SQL);

            try {
                return $this->getModels(...$args);
            } catch (\Exception $e) {
                $span->setError($e);
                throw $e;
            } finally {
                $scope->close();
            }
        });

        // performInsert(Builder $query)
        dd_trace(Model::class, 'performInsert', function ($query) {
            $scope = GlobalTracer::get()->startActiveSpan('eloquent.insert');
            $span = $scope->getSpan();
            $sql = $query->toBase()->toSql();
            $span->setResource($sql);
            $span->setTag(Tags\DB_STATEMENT, $sql);
            $span->setTag(Tags\SPAN_TYPE, Types\SQL);

            try {
                return $this->performInsert($query);
            } catch (\Exception $e) {
                $span->setError($e);
                throw $e;
            } finally {
                $scope->close();
            }
        });

        // performUpdate(Builder $query)
        dd_trace(Model::class, 'performUpdate', function ($query) {
            $scope = GlobalTracer::get()->startActiveSpan('eloquent.update');
            $span = $scope->getSpan();
            $sql = $query->toBase()->toSql();
            $span->setResource($sql);
            $span->setTag(Tags\DB_STATEMENT, $sql);
            $span->setTag(Tags\SPAN_TYPE, Types\SQL);

            try {
                return $this->performUpdate($query);
            } catch (\Exception $e) {
                $span->setError($e);
                throw $e;
            } finally {
                $scope->close();
            }
        });

        // public function delete()
        dd_trace(Model::class, 'delete', function () {
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
