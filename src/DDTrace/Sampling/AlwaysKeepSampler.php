<?php

namespace DDTrace\Sampling;

use DDTrace\Contracts\Span;

/**
 * The simplest sampler, always providing a PrioritySampling::AUTO_KEEP option in any circumstance.
 */
class AlwaysKeepSampler implements Sampler
{
    /**
     * @param Span $span
     * @return int
     */
    public function getPrioritySampling(Span $span)
    {
        return PrioritySampling::AUTO_KEEP;
    }
}
