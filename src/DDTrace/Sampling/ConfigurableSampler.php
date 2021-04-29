<?php

namespace DDTrace\Sampling;

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
        $samplingRules = \ddtrace_config_sampling_rules();
        $usedRate = null;

        foreach ($samplingRules as $rule) {
            if ($this->ruleMatches($span, $rule)) {
                $rate = $rule['sample_rate'];
                $usedRate = $rate;
                break;
            }
        }

        if (null === $usedRate) {
            $usedRate = \ddtrace_config_sampling_rate();
        }

        $span->setMetric('_dd.rule_psr', $usedRate);
        return $this->computePrioritySampling($usedRate);
    }

    /**
     * Applies the provided rule to the span and returns whether or not it matches.
     *
     * @param Span $span
     * @param array $rule
     * @return bool
     */
    private function ruleMatches(Span $span, array $rule)
    {
        $serviceName = $span->getService();
        $serviceNameMatches = $serviceName === \null
            || preg_match('/' . $rule['service'] . '/', $serviceName);

        $operationName = $span->getOperationName();
        $operationNameMatches = $operationName === \null
            || preg_match('/' . $rule['name'] . '/', $operationName);

        return $serviceNameMatches && $operationNameMatches;
    }

    /**
     * Given a float rate, it computes whether or not the current should be sampled.
     *
     * @param float $rate
     * @return int
     */
    private function computePrioritySampling($rate)
    {
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
