<?php

namespace DDTrace\Integrations\Eloquent;

use DDTrace\Integrations\Integration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;

class EloquentIntegration extends Integration
{
    const NAME = 'eloquent';

    /**
     * @var string The app name. Note that this value is used as a cache, you should use method getAppName().
     */
    private static $appName = null;

    /**
     * {@inheritDoc}
     */
    public static function init(): int
    {
        \DDTrace\trace_method(
            'Illuminate\Database\Eloquent\Builder',
            'getModels',
            function (SpanData $span) {
                $span->name = 'eloquent.get';
                $sql = $this->getQuery()->toSql();
                $span->resource = $sql;
                $span->meta[Tag::DB_STATEMENT] = $sql;
                EloquentIntegration::setCommonValues($span);
            }
        );

        \DDTrace\trace_method(
            'Illuminate\Database\Eloquent\Model',
            'performInsert',
            function (SpanData $span) {
                $span->name = 'eloquent.insert';
                $span->resource = get_class($this);
                EloquentIntegration::setCommonValues($span);
            }
        );

        \DDTrace\trace_method(
            'Illuminate\Database\Eloquent\Model',
            'performUpdate',
            function (SpanData $span) {
                $span->name = 'eloquent.update';
                $span->resource = get_class($this);
                EloquentIntegration::setCommonValues($span);
            }
        );

        \DDTrace\trace_method(
            'Illuminate\Database\Eloquent\Model',
            'delete',
            function (SpanData $span) {
                $span->name = 'eloquent.delete';
                $span->resource = get_class($this);
                EloquentIntegration::setCommonValues($span);
            }
        );

        \DDTrace\trace_method(
            'Illuminate\Database\Eloquent\Model',
            'destroy',
            function (SpanData $span) {
                $span->name = 'eloquent.destroy';
                $span->resource = get_called_class();
                EloquentIntegration::setCommonValues($span);
            }
        );

        \DDTrace\trace_method(
            'Illuminate\Database\Eloquent\Model',
            'refresh',
            function (SpanData $span) {
                $span->name = 'eloquent.refresh';
                $span->resource = get_class($this);
                EloquentIntegration::setCommonValues($span);
            }
        );

        return Integration::LOADED;
    }

    /**
     * Set common values shared by many different spans.
     *
     * @param SpanData $span
     */
    public static function setCommonValues(SpanData $span)
    {
        $span->type = Type::SQL;
        Integration::handleInternalSpanServiceName($span, self::getAppName());
        $span->meta[Tag::SPAN_KIND] = 'client';
        $span->meta[Tag::COMPONENT] = EloquentIntegration::NAME;
        $span->meta[Tag::DB_SYSTEM] = 'other_sql';
    }

    /**
     * @return string
     */
    public static function getAppName()
    {
        if (null !== $name = self::$appName) {
            return $name;
        }

        $name = \ddtrace_config_app_name();
        if (empty($name) && is_callable('config')) {
            $name = config('app.name');
        }

        return self::$appName = $name ?: 'laravel';
    }
}
