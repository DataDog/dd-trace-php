<?php

/**
 * Ported from opentracing/opentracing
 * @see https://github.com/opentracing/opentracing-php/blob/master/src/OpenTracing/NoopScopeManager.php
 */

namespace DDTrace\OpenTracing;

final class NoopScopeManager implements ScopeManager
{
    public static function create()
    {
        return new self();
    }

    /**
     * {@inheritdoc}
     */
    public function activate(Span $span, $finishSpanOnClose = ScopeManager::DEFAULT_FINISH_SPAN_ON_CLOSE)
    {
    }

    /**
     * {@inheritdoc}
     */
    public function getActive()
    {
        return NoopScope::create();
    }
}
