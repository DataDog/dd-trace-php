<?php

namespace DDTrace\Propagators;

use DDTrace\Propagator;
use DDTrace\SpanContext;
use DDTrace\OpenTracing\NoopSpanContext;

final class Noop implements Propagator
{
    /**
     * {@inheritdoc}
     */
    public function inject(SpanContext $spanContext, &$carrier)
    {
    }

    /**
     * {@inheritdoc}
     */
    public function extract($carrier)
    {
        return NoopSpanContext::create();
    }
}
