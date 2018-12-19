<?php

namespace Tests\Integration;

use DDTrace\Tests\Integration\Common\SpanAssertion;
use DDTrace\Tests\Integration\Common\SpanAssertionTrait;
use DDTrace\Tests\Integration\Common\TracerTestTrait;
use Illuminate\Pipeline\Pipeline;
use Tests\TestCase;

class PipelineTracingTest extends TestCase
{
    use TracerTestTrait, SpanAssertionTrait;

    public function testPipeline()
    {
        $traces = $this->simulateWebRequestTracer(function() {
            $pipeline = new Pipeline();
            $result = $pipeline
                ->send(1)
                ->through(new DummyPipe())
                ->via('someHandler')
                ->then(function () {
                    return 'done';
                });
            $this->assertSame('done', $result);
        });
        $this->assertExpectedSpans($this, $traces, [
            SpanAssertion::exists('laravel.request'),
            SpanAssertion::build('laravel.pipeline.pipe', 'laravel_test_app', 'web', 'Tests\Integration\DummyPipe::someHandler'),
        ]);
    }

    public function testPipelineCalledTwiceProperlyTraced()
    {
        $traces = $this->simulateWebRequestTracer(function() {
            $pipeline = new Pipeline();
            $result1 = $pipeline
                ->send(1)
                ->through(new DummyPipe())
                ->via('someHandler')
                ->then(function () {
                    return 'done1';
                });
            $result2 = $pipeline
                ->send(2)
                ->through(new DummyPipe())
                ->via('someHandler')
                ->then(function () {
                    return 'done2';
                });
            $this->assertSame('done1', $result1);
            $this->assertSame('done2', $result2);
        });
        $this->assertExpectedSpans($this, $traces, [
            SpanAssertion::exists('laravel.request'),
            SpanAssertion::build('laravel.pipeline.pipe', 'laravel_test_app', 'web', 'Tests\Integration\DummyPipe::someHandler'),
            SpanAssertion::build('laravel.pipeline.pipe', 'laravel_test_app', 'web', 'Tests\Integration\DummyPipe::someHandler'),
        ]);
    }
}

class DummyPipe
{
    public function someHandler($value, \Closure $next)
    {
        return $next($value + 1);
    }
}
