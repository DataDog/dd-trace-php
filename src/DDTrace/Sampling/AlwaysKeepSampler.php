<?php

namespace DDTrace\Sampling;

use DDTrace\Span;

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
