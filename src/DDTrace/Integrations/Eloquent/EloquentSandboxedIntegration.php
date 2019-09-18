<?php

namespace DDTrace\Integrations\Eloquent;

use DDTrace\Integrations\Integration;
use DDTrace\Integrations\SandboxedIntegration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;

class EloquentSandboxedIntegration extends SandboxedIntegration
{
    const NAME = 'eloquent';

    /**
     * @return string The integration name.
     */
    public function getName()
    {
        return self::NAME;
    }

    /**
     * Add instrumentation to PDO requests
     */
    public function init()
    {
        $integration = $this;

        dd_trace_method(
            'Illuminate\Database\Eloquent\Builder',
            'getModels',
            function (SpanData $span, array $args) use ($integration) {
                $span->name = 'eloquent.get';
                $sql = $this->getQuery()->toSql();
                $span->resource = $sql;
                $span->meta[Tag::DB_STATEMENT] = $sql;
                $integration->setCommonValues($span);
            }
        );

        dd_trace_method(
            'Illuminate\Database\Eloquent\Model',
            'performInsert',
            function (SpanData $span, array $args) use ($integration) {
                $span->name = 'eloquent.insert';
                $span->resource = get_class($this);
                $integration->setCommonValues($span);
            }
        );

        dd_trace_method(
            'Illuminate\Database\Eloquent\Model',
            'performUpdate',
            function (SpanData $span, array $args) use ($integration) {
                $span->name = 'eloquent.update';
                $span->resource = get_class($this);
                $integration->setCommonValues($span);
            }
        );

        dd_trace_method(
            'Illuminate\Database\Eloquent\Model',
            'delete',
            function (SpanData $span, array $args) use ($integration) {
                $span->name = 'eloquent.delete';
                $span->resource = get_class($this);
                $integration->setCommonValues($span);
            }
        );

        dd_trace_method(
            'Illuminate\Database\Eloquent\Model',
            'destroy',
            function (SpanData $span) use ($integration) {
                $span->name = 'eloquent.destroy';
                $span->resource = get_called_class();
                $integration->setCommonValues($span);
            }
        );

        dd_trace_method(
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
        $span->meta[Tag::INTEGRATION_NAME] = $this->getName();
    }
}
