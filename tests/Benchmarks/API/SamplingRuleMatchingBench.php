<?php

declare(strict_types=1);

namespace Benchmarks\API;

class SamplingRuleMatchingBench
{
    /**
     * @Revs(1000)
     * @Iterations(10)
     * @OutputTimeUnit("microseconds")
     * @RetryThreshold(10.0)
     * @Warmup(1)
     */
    public function benchGlobMatching1(): void
    {
        $this->runSamplingRuleMatchingBenchmark("webserver.non-matching", "web.request", "/bar", "glob");
    }

    /**
     * @Revs(1000)
     * @Iterations(10)
     * @OutputTimeUnit("microseconds")
     * @RetryThreshold(10.0)
     * @Warmup(1)
     */
    public function benchGlobMatching2(): void
    {
        $this->runSamplingRuleMatchingBenchmark("webserver", "web.request.non-matching", "/bar", "glob");
    }

    /**
     * @Revs(1000)
     * @Iterations(10)
     * @OutputTimeUnit("microseconds")
     * @RetryThreshold(10.0)
     * @Warmup(1)
     */
    public function benchGlobMatching3(): void
    {
        $this->runSamplingRuleMatchingBenchmark("webserver", "web.request", "/bar.non-matching", "glob");
    }

    /**
     * @Revs(1000)
     * @Iterations(10)
     * @OutputTimeUnit("microseconds")
     * @RetryThreshold(10.0)
     * @Warmup(1)
     */
    public function benchGlobMatching4(): void
    {
        $this->runSamplingRuleMatchingBenchmark("webserver", "web.request", "/b?r", "glob");
    }

    /**
     * @Revs(1000)
     * @Iterations(10)
     * @OutputTimeUnit("microseconds")
     * @RetryThreshold(10.0)
     * @Warmup(1)
     */
    public function benchRegexMatching1(): void
    {
        $this->runSamplingRuleMatchingBenchmark("webserver\.non-matching", "web\.request", "\/bar", "regex");
    }

    /**
     * @Revs(1000)
     * @Iterations(10)
     * @OutputTimeUnit("microseconds")
     * @RetryThreshold(10.0)
     * @Warmup(1)
     */
    public function benchRegexMatching2(): void
    {
        $this->runSamplingRuleMatchingBenchmark("webserver", "web\.request\.non-matching", "\/bar", "regex");
    }

    /**
     * @Revs(1000)
     * @Iterations(10)
     * @OutputTimeUnit("microseconds")
     * @RetryThreshold(10.0)
     * @Warmup(1)
     */
    public function benchRegexMatching3(): void
    {
        $this->runSamplingRuleMatchingBenchmark("webserver", "web\.request", "\/bar\.non-matching", "regex");
    }

    /**
     * @Revs(1000)
     * @Iterations(10)
     * @OutputTimeUnit("microseconds")
     * @RetryThreshold(10.0)
     * @Warmup(1)
     */
    public function benchRegexMatching4(): void
    {
        $this->runSamplingRuleMatchingBenchmark("webserver", "web\.request", "\/b\?r", "regex");
    }

    public function runSamplingRuleMatchingBenchmark($servicePattern, $namePattern, $resourcePattern, $format): void
    {
        ini_set("datadog.trace.sampling_rules_format", $format);
        ini_set("datadog.trace.sampling_rules", '[{"name":"' . $namePattern . '","service":"' . $servicePattern . '","resource":"' . $resourcePattern . '","sample_rate":0.7},{"sample_rate": 0.3}]');

        $root = \DDTrace\root_span();
        $root->service = "webserver";
        $root->name = "web.request";
        $root->resource = "/bar";

        \DDTrace\get_priority_sampling();
    }
}
