<?php

namespace DDTrace\Sampling;

use DDTrace\Configuration;
use DDTrace\Contracts\Span;

/**
 * A sampler configurable using global a global configuration parameter.
 */
final class ConfigurableSampler implements Sampler
{
    /**
     * @param Span $span
     * @return int
     */
    public function getPrioritySampling(Span $span)
    {
        $rate = Configuration::get()->getSamplingRate();

        if ($rate === 1.0) {
            return PrioritySampling::AUTO_KEEP;
        } elseif ($rate === 0.0) {
            return PrioritySampling::AUTO_REJECT;
        }

        // NOTE 1: we do not apply the knuth hashing algorithm here and this is fine for the sole purpose of setting the
        // sampling priority. This would not work though if we were using this value for client sampling, as the value
        // and statistical distribution should exactly match the one used by the agent.
        //
        // NOTE 2: as SammyK correctly pointed out, we use `mt_rand()` to generate IDs which is topped to 31 bits, not
        // 63. In order to compensate for this we concatenate multiple generators which cause a major biasing of the
        // algorithm. To compensate this we use here a `mt_rand` generate value to decide how to set the priority
        // sampling. When we will introduce client sampling we will have to implement the real and final knuth hashing
        // function.
        $shouldKeep = mt_rand(1, mt_getrandmax()) <= $rate * mt_getrandmax();

        return $shouldKeep ? PrioritySampling::AUTO_KEEP : PrioritySampling::AUTO_REJECT;
    }
}
