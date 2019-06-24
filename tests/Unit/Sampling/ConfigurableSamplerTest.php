<?php

namespace DDTrace\Tests\Unit\Sampling;

use DDTrace\Configuration;
use DDTrace\ID;
use DDTrace\Sampling\ConfigurableSampler;
use DDTrace\Span;
use DDTrace\SpanContext;
use DDTrace\Tests\Unit\BaseTestCase;

final class ConfigurableSamplerTest extends BaseTestCase
{
    const REPETITIONS = 5000;

    /**
     * @dataProvider samplingRatesScenarios
     * @param float $samplingRate
     * @param float $lower
     * @param float $upper
     */
    public function testSpansAreKept($samplingRate, $lower, $upper)
    {
        Configuration::replace(\Mockery::mock(Configuration::get(), [
            'getSamplingRate' => $samplingRate,
        ]));
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
            [1.0, 1.0, 1.0],
            [100, 1.0, 1.0],
            [-20, 0.0, 0.0],

            // Common cases
            [0.5, 0.47, 0.53],
            [0.8, 0.77, 0.83],
            [0.2, 0.17, 0.23],
        ];
    }
}
