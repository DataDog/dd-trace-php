<?php

namespace DDTrace\Integrations\Eloquent;

use DDTrace\Tags;
use DDTrace\Types;
use DDTrace\Util\TryCatchFinally;
use DDTrace\GlobalTracer;

class EloquentIntegration
{
    const NAME = 'eloquent';

    public static function load()
    {
        if (!class_exists('Illuminate\Database\Eloquent\Builder')) {
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

            return TryCatchFinally::executePublicMethod($scope, $this, 'getModels', $args);
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

            return TryCatchFinally::executePublicMethod($scope, $this, 'performInsert', $args);
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

            return TryCatchFinally::executePublicMethod($scope, $this, 'performUpdate', $args);
        });

        // public function delete()
        dd_trace('Illuminate\Database\Eloquent\Model', 'delete', function () {
            $scope = GlobalTracer::get()->startActiveSpan('eloquent.delete');
            $scope->getSpan()->setTag(Tags\SPAN_TYPE, Types\SQL);

            return TryCatchFinally::executePublicMethod($scope, $this, 'delete', []);
        });
    }
}
