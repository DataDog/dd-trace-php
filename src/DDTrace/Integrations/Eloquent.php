<?php

namespace DDTrace\Integrations;

use DDTrace\Tags;
use DDTrace\Types;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use OpenTracing\GlobalTracer;

class Eloquent
{
    public static function load()
    {
        if (!extension_loaded('ddtrace')) {
            trigger_error('ddtrace extension required to load Eloquent integrations.', E_USER_WARNING);
            return;
        }

        // public function getModels($columns = ['*'])
        dd_trace(Builder::class, 'getModels', function ($columns = ['*']) {
            $scope = GlobalTracer::get()->startActiveSpan('eloquent/get');
            $span = $scope->getSpan();
            $sql = $this->toBase()->toSql();
            $span->setResource($sql);
            $span->setTag(Tags\DB_STATEMENT, $sql);
            $span->setTag(Tags\SPAN_TYPE, Types\SQL);

            $result = $this->getModels($columns);

            $scope->close();

            return $result;
        });

        // protected function performInsert(Builder $query)
        dd_trace(Model::class, 'performInsert', function ($query) {
            $scope = GlobalTracer::get()->startActiveSpan('eloquent/insert');
            $span = $scope->getSpan();
            $sql = $query->toBase()->toSql();
            $span->setResource($sql);
            $span->setTag(Tags\DB_STATEMENT, $sql);
            $span->setTag(Tags\SPAN_TYPE, Types\SQL);

            $result = $this->performInsert($query);

            $scope->close();

            return $result;
        });

        // protected function performUpdate(Builder $query)
        dd_trace(Model::class, 'performUpdate', function ($query) {
            $scope = GlobalTracer::get()->startActiveSpan('eloquent/update');
            $span = $scope->getSpan();
            $sql = $query->toBase()->toSql();
            $span->setResource($sql);
            $span->setTag(Tags\DB_STATEMENT, $sql);
            $span->setTag(Tags\SPAN_TYPE, Types\SQL);

            $result = $this->performUpdate($query);

            $scope->close();

            return $result;
        });

        // public function delete()
        dd_trace(Model::class, 'delete', function () {
            $scope = GlobalTracer::get()->startActiveSpan('eloquent/delete');
            $scope->getSpan()->setTag(Tags\SPAN_TYPE, Types\SQL);

            $result = $this->delete();

            $scope->close();

            return $result;
        });
    }
}
