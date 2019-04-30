<?php

namespace DDTrace\Integrations\Eloquent;

use DDTrace\Integrations\Integration;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\GlobalTracer;

class EloquentIntegration extends Integration
{
    const NAME = 'eloquent';

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

    public static function load()
    {
        $integration = self::getInstance();

        // getModels($columns = ['*'])
        dd_trace('Illuminate\Database\Eloquent\Builder', 'getModels', function () use ($integration) {
            $tracer = GlobalTracer::get();
            if ($tracer->limited()) {
                return dd_trace_forward_call();
            }

            $scope = $tracer->startIntegrationScopeAndSpan($integration, 'eloquent.get');
            $span = $scope->getSpan();
            $sql = $this->getQuery()->toSql();
            $span->setTag(Tag::RESOURCE_NAME, $sql);
            $span->setTag(Tag::DB_STATEMENT, $sql);
            $span->setTag(Tag::SPAN_TYPE, Type::SQL);

            return include __DIR__ . '/../../try_catch_finally.php';
        });

        // performInsert(Builder $query)
        dd_trace('Illuminate\Database\Eloquent\Model', 'performInsert', function () use ($integration) {
            $tracer = GlobalTracer::get();
            if ($tracer->limited()) {
                return dd_trace_forward_call();
            }

            list($eloquentQueryBuilder) = func_get_args();
            $scope = $tracer->startIntegrationScopeAndSpan($integration, 'eloquent.insert');
            $span = $scope->getSpan();
            $sql = $eloquentQueryBuilder->getQuery()->toSql();
            $span->setTag(Tag::RESOURCE_NAME, $sql);
            $span->setTag(Tag::DB_STATEMENT, $sql);
            $span->setTag(Tag::SPAN_TYPE, Type::SQL);

            return include __DIR__ . '/../../try_catch_finally.php';
        });

        // performUpdate(Builder $query)
        dd_trace('Illuminate\Database\Eloquent\Model', 'performUpdate', function () use ($integration) {
            list($eloquentQueryBuilder) = func_get_args();
            $tracer = GlobalTracer::get();
            if ($tracer->limited()) {
                return dd_trace_forward_call();
            }

            $scope = $tracer->startIntegrationScopeAndSpan($integration, 'eloquent.update');
            $span = $scope->getSpan();
            $sql = $eloquentQueryBuilder->getQuery()->toSql();
            $span->setTag(Tag::RESOURCE_NAME, $sql);
            $span->setTag(Tag::DB_STATEMENT, $sql);
            $span->setTag(Tag::SPAN_TYPE, Type::SQL);

            return include __DIR__ . '/../../try_catch_finally.php';
        });

        // public function delete()
        dd_trace('Illuminate\Database\Eloquent\Model', 'delete', function () use ($integration) {
            $tracer = GlobalTracer::get();
            if ($tracer->limited()) {
                return dd_trace_forward_call();
            }

            $scope = $tracer->startIntegrationScopeAndSpan($integration, 'eloquent.delete');
            $scope->getSpan()->setTag(Tag::SPAN_TYPE, Type::SQL);

            return include __DIR__ . '/../../try_catch_finally.php';
        });

        return Integration::LOADED;
    }
}
