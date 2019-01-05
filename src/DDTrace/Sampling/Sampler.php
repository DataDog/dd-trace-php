<?php

namespace DDTrace\Sampling;

use DDTrace\Contracts\Span;

/**
 * Defines a priority sampling value provider.
 */
interface Sampler
{
    public function getPrioritySampling(Span $span);
}
