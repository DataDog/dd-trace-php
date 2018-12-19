<?php

/**
 * Ported from opentracing/opentracing
 * @see https://github.com/opentracing/opentracing-php/blob/master/src/OpenTracing/NoopScope.php
 */

namespace DDTrace\OpenTracing;

use DDTrace\Contracts\Scope;

final class NoopScope implements Scope
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
