<?php

namespace DDTrace\Tests\Unit\Sampling;

use DDTrace\Configuration;
use DDTrace\Sampling\ConfigurableSampler;
use DDTrace\Span;
use DDTrace\SpanContext;
use DDTrace\Tests\Unit\BaseTestCase;

final class ConfigurableSamplerTest extends BaseTestCase
{
    public function testSpansAreKept()
    {
        Configuration::replace(\Mockery::mock('DDtrace\Configuration', [
            'getSamplingRate' => 0.5,
        ]));
        $sampler = new ConfigurableSampler();
        $context = new SpanContext('', (string)(int)(PHP_INT_MAX * 0.49));
        $this->assertSame(1, $sampler->getPrioritySampling(new Span('', $context, '', '')));
    }

    public function testSpansAreKeptRejected()
    {
        Configuration::replace(\Mockery::mock('DDtrace\Configuration', [
            'getSamplingRate' => 0.5,
        ]));
        $sampler = new ConfigurableSampler();
        $context = new SpanContext('', (string)(int)(PHP_INT_MAX * 0.51));
        $this->assertSame(0, $sampler->getPrioritySampling(new Span('', $context, '', '')));
    }

    public function testCanBeAlwaysOn()
    {
        Configuration::replace(\Mockery::mock('DDtrace\Configuration', [
            'getSamplingRate' => 1.0,
        ]));
        $sampler = new ConfigurableSampler();
        $context = new SpanContext('', '1');
        $this->assertSame(1, $sampler->getPrioritySampling(new Span('', $context, '', '')));
    }

    public function testCanBeAlwaysOff()
    {
        Configuration::replace(\Mockery::mock('DDtrace\Configuration', [
            'getSamplingRate' => 0.0,
        ]));
        $sampler = new ConfigurableSampler();
        $context = new SpanContext('', (string) PHP_INT_MAX);
        $this->assertSame(0, $sampler->getPrioritySampling(new Span('', $context, '', '')));
    }
}
