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

        // NOTE: we do not apply the knuth hashing algorithm here and this is fine for the sole purpose of setting the
        // sampling priority. This would not work though if we were using this value for client sampling, as the value
        // and statistical distribution should exactly match the one used by the agent.
        $shouldKeep = (int) $span->getSpanId() <= $rate * PHP_INT_MAX;

        return $shouldKeep ? PrioritySampling::AUTO_KEEP : PrioritySampling::AUTO_REJECT;
    }
}
