<?php

/**
 * Ported from opentracing/opentracing
 * @see https://github.com/opentracing/opentracing-php/blob/master/src/OpenTracing/NoopScopeManager.php
 */

namespace DDTrace;

use DDTrace\Contracts\ScopeManager as ScopeManagerInterface;
use DDTrace\Contracts\Span as SpanInterface;

final class NoopScopeManager implements ScopeManagerInterface
{
    public static function create()
    {
        return new self();
    }

    /**
     * {@inheritdoc}
     */
    public function activate(
        SpanInterface $span,
        $finishSpanOnClose = ScopeManagerInterface::DEFAULT_FINISH_SPAN_ON_CLOSE
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function getActive()
    {
        return NoopScope::create();
    }

    /**
     * Closes all the current request root spans. Typically there only will be one.
     */
    public function close()
    {
    }
}
