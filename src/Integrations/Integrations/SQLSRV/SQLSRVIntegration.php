<?php

namespace DDTrace\Integrations\SQLSRV;

use DDTrace\Integrations\Integration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;

class SQLSRVIntegration extends Integration
{
    const NAME = 'sqlsrv';
    const SYSTEM = 'sqlsrv';

    /**
     * @return string The integration name.
     */
    public function getName()
    {
        return self::NAME;
    }

    /**
     * Load the integration
     *
     * @return int
     */
    public function init()
    {
        if (!extension_loaded('sqlsrv')) {
            return Integration::NOT_AVAILABLE;
        }

        $integration = $this;

        \DDTrace\trace_function('sqlsrv_connect', null);

        \DDTrace\trace_function('sqlsrv_query', null);

        \DDTrace\trace_function('sqlsrv_prepare', null);

        \DDTrace\trace_function('sqlsrv_commit', null);

        \DDTrace\trace_function('sqlsrv_execute', null);
    }

    public static function setDefaultAttributes(SpanData $span, $name, $resource, $result = null)
    {
        $span->name = $name;
        $span->resource = $resource;
        $span->type= Type::SQL;
        $span->service = 'sqlsrv';
        $span->meta[Tag::SPAN_KIND] = 'client';
        $span->meta[Tag::COMPONENT] = SQLSRVIntegration::NAME;
        $span->meta[Tag::DB_SYSTEM] = SQLSRVIntegration::SYSTEM;
        if (is_object($result)) {
            $span->metrics[Tag::DB_ROW_COUNT] = sqlsrv_num_rows($result);
        }
    }
}
