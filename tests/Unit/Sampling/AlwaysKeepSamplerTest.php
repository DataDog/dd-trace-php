<?php

namespace DDTrace\Tests\Unit\Sampling;

use DDTrace\Sampling\AlwaysKeepSampler;
use DDTrace\Span;
use DDTrace\SpanContext;
use DDTrace\Tests\Unit\BaseTestCase;

final class AlwaysKeepSamplerTest extends BaseTestCase
{
    public function testReturnsAlwaysAutoKeep()
    {
        $sampler = new AlwaysKeepSampler();
        $context = new SpanContext('', '');
        $this->assertSame(1, $sampler->sample(new Span('', $context, '', '')));
    }
}
