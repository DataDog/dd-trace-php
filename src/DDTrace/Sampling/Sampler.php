<?php

namespace DDTrace\Sampling;

use DDTrace\Contracts\Span;

/**
 * Defines a priority sampling value provider.
 */
interface Sampler
{
    /**
     * Sample the current span. Note the it might have side effects, e.g. setting metrics on the span.
     *
     * @param Span $span
     * @return int
     */
    public function sample(Span $span);
}
