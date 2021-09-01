<?php

namespace DDTrace\Tests\Unit\Sampling;

use DDTrace\Sampling\ConfigurableSampler;
use DDTrace\Span;
use DDTrace\SpanContext;
use DDTrace\SpanData;
use DDTrace\Tests\Common\BaseTestCase;

final class ConfigurableSamplerTest extends BaseTestCase
{
    const REPETITIONS = 5000;

    protected function ddSetUp()
    {
        parent::ddSetUp();
        self::putenv('DD_TRACE_SAMPLING_RULES');
        self::putenv('DD_TRACE_SAMPLE_RATE');
    }

    protected function ddTearDown()
    {
        self::putenv('DD_TRACE_SAMPLING_RULES');
        self::putenv('DD_TRACE_SAMPLE_RATE');
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
        self::putenv("DD_TRACE_SAMPLE_RATE=$samplingRate");
        $sampler = new ConfigurableSampler();

        $output = 0;

        for ($i = 0; $i < self::REPETITIONS; $i++) {
            $context = new SpanContext('', dd_trace_generate_id());
            $span = PHP_VERSION_ID < 70000 ? new Span('', $context, '', '') : new Span(new SpanData(), $context);
            $output += $sampler->getPrioritySampling($span);
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

    private function createMySpan()
    {
        $context = new SpanContext('', dd_trace_generate_id());
        $span = PHP_VERSION_ID < 70000 ? new Span('', $context, '', '') : new Span(new SpanData(), $context);
        $span->operationName = 'my_name';
        $span->service = 'my_service';
        return $span;
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
        self::putenv("DD_TRACE_SAMPLE_RATE=0.3");
        self::putenv("DD_TRACE_SAMPLING_RULES=$samplingRules");

        $sampler = new ConfigurableSampler();

        $output = 0;

        for ($i = 0; $i < self::REPETITIONS; $i++) {
            $output += $sampler->getPrioritySampling($this->createMySpan());
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

    public function testMetricIsAddedToCommunicateSampleRateUsedWhenSamplingRules()
    {
        self::putenv('DD_TRACE_SAMPLING_RULES=[{"sample_rate":0.7}]');
        $sampler = new ConfigurableSampler();

        $span = $this->createMySpan();
        $sampler->getPrioritySampling($span);

        $this->assertSame(0.7, $span->getMetrics()['_dd.rule_psr']);
    }

    public function testMetricIsAddedToCommunicateSampleRateUsedWhenSamplingRulesIsEscaped()
    {
        // while we suggest to escape the json object, in some cases the `'` are passed to the string and we have to
        // verify that parsing still works.
        self::putenv('DD_TRACE_SAMPLING_RULES=\'[{"sample_rate":0.7}]\'');
        $sampler = new ConfigurableSampler();

        $span = $this->createMySpan();
        $sampler->getPrioritySampling($span);

        $this->assertSame(0.7, $span->getMetrics()['_dd.rule_psr']);
    }

    public function testMetricIsAddedToCommunicateSampleRateUsedWhenSampleRate()
    {
        self::putenv('DD_TRACE_SAMPLE_RATE=0.3');
        $sampler = new ConfigurableSampler();

        $span = $this->createMySpan();
        $sampler->getPrioritySampling($span);

        $this->assertSame(0.3, $span->getMetrics()['_dd.rule_psr']);
    }

    public function testMetricIsAddedToCommunicateSampleRateNothingSet()
    {
        $sampler = new ConfigurableSampler();

        $span = $this->createMySpan();
        $sampler->getPrioritySampling($span);

        $this->assertSame(1.0, $span->getMetrics()['_dd.rule_psr']);
    }
}
