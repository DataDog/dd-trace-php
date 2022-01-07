<?php

namespace DDTrace;

/**
 * Ported from opentracing/opentracing
 * @see https://github.com/opentracing/opentracing-php/blob/master/src/OpenTracing/NoopScope.php
 */

use DDTrace\Contracts\Scope as ScopeInterface;

final class NoopScope implements ScopeInterface
{
    public static function create()
    {
        return new self();
    }

    /**
     * {@inheritdoc}
     */
    public function close()
    {
    }

    /**
     * {@inheritdoc}
     */
    public function getSpan()
    {
        return NoopSpan::create();
    }
}
