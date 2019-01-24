<?php

namespace DDTrace\Sampling;

use DDTrace\Configuration;
use DDTrace\Contracts\Span;

/**
 * A sampler configurable using global a global configuration parameter.
 */
class ConfigurableSampler implements Sampler
{
    /**
     * @param Span $span
     * @return int
     */
    public function getPrioritySampling(Span $span)
    {
        $rate = Configuration::get()->getSamplingRate();
        $shouldKeep = (int) $span->getSpanId() <= $rate * PHP_INT_MAX;
        return $shouldKeep ? PrioritySampling::AUTO_KEEP : PrioritySampling::AUTO_REJECT;
    }
}
