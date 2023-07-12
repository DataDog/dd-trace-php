<?php

namespace DDTrace\Integrations\Eloquent;

use DDTrace\Integrations\Integration;
use DDTrace\Integrations\SpanTaxonomy;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;

class EloquentIntegration extends Integration
{
    const NAME = 'eloquent';

    /**
     * @var string The app name. Note that this value is used as a cache, you should use method getAppName().
     */
    private $appName;

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return self::NAME;
    }

    /**
     * {@inheritDoc}
     */
    public function init()
    {
        $integration = $this;

        \DDTrace\trace_method(
            'Illuminate\Database\Eloquent\Builder',
            'getModels',
            function (SpanData $span) use ($integration) {
                $span->name = 'eloquent.get';
                $sql = $this->getQuery()->toSql();
                $span->resource = $sql;
                $span->meta[Tag::DB_STATEMENT] = $sql;
                $integration->setCommonValues($span);
            }
        );

        \DDTrace\trace_method(
            'Illuminate\Database\Eloquent\Model',
            'performInsert',
            function (SpanData $span) use ($integration) {
                $span->name = 'eloquent.insert';
                $span->resource = get_class($this);
                $integration->setCommonValues($span);
            }
        );

        \DDTrace\trace_method(
            'Illuminate\Database\Eloquent\Model',
            'performUpdate',
            function (SpanData $span) use ($integration) {
                $span->name = 'eloquent.update';
                $span->resource = get_class($this);
                $integration->setCommonValues($span);
            }
        );

        \DDTrace\trace_method(
            'Illuminate\Database\Eloquent\Model',
            'delete',
            function (SpanData $span) use ($integration) {
                $span->name = 'eloquent.delete';
                $span->resource = get_class($this);
                $integration->setCommonValues($span);
            }
        );

        \DDTrace\trace_method(
            'Illuminate\Database\Eloquent\Model',
            'destroy',
            function (SpanData $span) use ($integration) {
                $span->name = 'eloquent.destroy';
                $span->resource = get_called_class();
                $integration->setCommonValues($span);
            }
        );

        \DDTrace\trace_method(
            'Illuminate\Database\Eloquent\Model',
            'refresh',
            function (SpanData $span) use ($integration) {
                $span->name = 'eloquent.refresh';
                $span->resource = get_class($this);
                $integration->setCommonValues($span);
            }
        );

        return Integration::LOADED;
    }

    /**
     * Set common values shared by many different spans.
     *
     * @param SpanData $span
     */
    public function setCommonValues(SpanData $span)
    {
        $span->type = Type::SQL;
        SpanTaxonomy::instance()->handleServiceName($span, $this->getFallbackAppName());
        $span->meta[Tag::SPAN_KIND] = 'client';
        $span->meta[Tag::COMPONENT] = EloquentIntegration::NAME;
        $span->meta[Tag::DB_SYSTEM] = 'other_sql';
    }

    /**
     * @deprecated This function should not be used, the SpanTaxonomy::handleServiceName should be used instead.
     * @return string
     */
    public function getAppName()
    {
        if (null !== $this->appName) {
            return $this->appName;
        }

        $name = \ddtrace_config_app_name();
        if (empty($name) && is_callable('config')) {
            $name = config('app.name');
        }

        $this->appName = $name ?: 'laravel';
        return $this->appName;
    }

    /**
     * @internal
     * @return string
     */
    private function getFallbackAppName()
    {
        $name = is_callable('config') ? config('app.name') : null;
        return $name ?: 'laravel';
    }
}
