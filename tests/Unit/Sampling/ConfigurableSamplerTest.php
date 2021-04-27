<?php

namespace DDTrace\Tests\Unit\Sampling;

use DDTrace\Sampling\ConfigurableSampler;
use DDTrace\Span;
use DDTrace\SpanContext;
use DDTrace\Tests\Common\BaseTestCase;

final class ConfigurableSamplerTest extends BaseTestCase
{
    const REPETITIONS = 5000;

    protected function ddSetUp()
    {
        parent::ddSetUp();
        \putenv('DD_TRACE_SAMPLING_RULES');
        \putenv('DD_TRACE_SAMPLE_RATE');
    }

    protected function ddTearDown()
    {
        \putenv('DD_TRACE_SAMPLING_RULES');
        \putenv('DD_TRACE_SAMPLE_RATE');
        parent::ddTearDown();
    }

    /**
     * @dataProvider samplingRatesScenarios
     * @param float $samplingRate
     * @param float $lower
     * @param float $upper
     */
    public function testSampleNoSamplingRules($samplingRate, $lower, $upper)
    {
        putenv("DD_TRACE_SAMPLE_RATE=$samplingRate");
        $sampler = new ConfigurableSampler();

        $output = 0;

        for ($i = 0; $i < self::REPETITIONS; $i++) {
            $context = new SpanContext('', dd_trace_generate_id());
            $output += $sampler->getPrioritySampling(new Span('', $context, '', ''));
        }

        $ratio = $output / self::REPETITIONS;
        $this->assertGreaterThanOrEqual($lower, $ratio);
        $this->assertLessThanOrEqual($upper, $ratio);
    }

    public function samplingRatesScenarios()
    {
        return [
            // Edges
            [0.0, 0.0, 0.0],
            // 0% provided as int.
            [0, 0.0, 0.0],
            [1.0, 1.0, 1.0],
            [100, 1.0, 1.0],
            [-20, 0.0, 0.0],
            // 100% provided as int.
            [1, 1.00, 1.00],

            // Common cases
            [0.5, 0.47, 0.53],
            [0.8, 0.77, 0.83],
            [0.2, 0.17, 0.23],
        ];
    }

    /**
     * @dataProvider samplingRulesScenarios
     * @param array $samplingRules
     * @param float $lower
     * @param float $upper
     */
    public function testSampleWithSamplingRules($samplingRules, $expected)
    {
        $delta = 0.05;
        putenv("DD_TRACE_SAMPLE_RATE=0.3");
        putenv("DD_TRACE_SAMPLING_RULES=$samplingRules");

        $sampler = new ConfigurableSampler();

        $output = 0;

        for ($i = 0; $i < self::REPETITIONS; $i++) {
            $context = new SpanContext('', dd_trace_generate_id());
            $output += $sampler->getPrioritySampling(new Span('my_name', $context, 'my_service', ''));
        }

        $ratio = $output / self::REPETITIONS;
        $this->assertGreaterThanOrEqual($expected - $delta, $ratio);
        $this->assertLessThanOrEqual($expected + $delta, $ratio);
    }

    public function samplingRulesScenarios()
    {
        return [
            'fallback to priority sampling when no rules' => [
                '',
                0.3,
            ],
            'fallback to priority sampling when rule does not match' => [
                '[{"service":"something_else","sample_rate":0.7}]',
                0.3,
            ],
            'set for match all' => [
                '[{"sample_rate":0.7}]',
                0.7,
            ],
            'set for match service' => [
                '[{"service":"my_.*","sample_rate":0.7}]',
                0.7,
            ],
            'set for match name' => [
                '[{"service":"my_.*","sample_rate":0.7}]',
                0.7,
            ],
            'not set for match name but not service' => [
                '[{"service":"wrong.*","sample_rate":0.7}]',
                0.3,
            ],
            'not set for match service but not name' => [
                '[{"name":"wrong.*","sample_rate":0.7}]',
                0.3,
            ],
            'first that match is used' => [
                '[{"service":"my_.*","name":"my_.*","sample_rate":0.7},{"sample_rate":0.5}]',
                0.7,
            ],
        ];
    }

    public function testMetricIsAddedToCommunicateSampleRateUsed()
    {
        putenv('DD_TRACE_SAMPLING_RULES=[{"sample_rate":0.7}]');
        $sampler = new ConfigurableSampler();

        $context = new SpanContext('', dd_trace_generate_id());
        $span = new Span('my_name', $context, 'my_service', '');
        $sampler->getPrioritySampling($span);

        $this->assertSame(0.7, $span->getMetrics()['_dd.rule_psr']);
    }
}
