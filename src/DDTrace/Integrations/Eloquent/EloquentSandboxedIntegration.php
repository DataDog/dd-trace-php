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
        dd_trace_method('Illuminate\Database\Eloquent\Builder', 'getModels', function (SpanData $span, array $args) {
            $span->name = 'eloquent.get';
            $sql = $this->getQuery()->toSql();
            $span->type = Type::SQL;
            $span->resource = get_class($this->model);
            $span->meta[Tag::DB_STATEMENT] = $sql;
        });

        dd_trace_method('Illuminate\Database\Eloquent\Model', 'performInsert', function (SpanData $span, array $args) {
            $span->name = 'eloquent.insert';
            $span->type = Type::SQL;
            $span->resource = get_class($this);
        });

        dd_trace_method('Illuminate\Database\Eloquent\Model', 'performUpdate', function (SpanData $span, array $args) {
            $span->name = 'eloquent.update';
            $span->type = Type::SQL;
            $span->resource = get_class($this);
        });

        dd_trace_method('Illuminate\Database\Eloquent\Model', 'delete', function (SpanData $span, array $args) {
            $span->name = $span->resource = 'eloquent.delete';
            $span->type = Type::SQL;
            $span->resource = get_class($this);
        });

        return Integration::LOADED;
    }
}
