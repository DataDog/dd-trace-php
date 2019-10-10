<?php

namespace DDTrace\Tests\Integrations\Laravel\V5_7;

use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\SpanAssertionTrait;
use DDTrace\Tests\Common\TracerTestTrait;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

class PipelineTracingTest extends WebFrameworkTestCase
{
    use TracerTestTrait;
    use SpanAssertionTrait;

    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Laravel/Version_5_7/public/index.php';
    }

    public function testPipeline()
    {
        $this->markTestSkipped('Pipeline are not properly traced and once we will fix the issue we should enable this');
        $traces = $this->tracesFromWebRequest(function () {
            $spec  = GetSpec::create('Pipeline called twice', '/pipeline_once');
            $response = $this->call($spec);
            $this->assertSame('done', $response);
        });
        $this->assertExpectedSpans($traces, [
            SpanAssertion::exists('laravel.request'),
            SpanAssertion::build(
                'laravel.pipeline.pipe',
                'laravel_test_app',
                'web',
                'Tests\Integration\DummyPipe::someHandler'
            ),
        ]);
    }

    public function testPipelineCalledTwiceProperlyTraced()
    {
        $this->markTestSkipped('Pipeline are not properly traced and once we will fix the issue we should enable this');
        $traces = $this->tracesFromWebRequest(function () {
            $spec  = GetSpec::create('Pipeline called twice', '/pipeline_twice');
            $response = $this->call($spec);
            $this->assertSame('done1/done2', $response);
        });
        $this->assertExpectedSpans($traces, [
            SpanAssertion::exists('laravel.request'),
            SpanAssertion::build(
                'laravel.pipeline.pipe',
                'laravel_test_app',
                'web',
                'Tests\Integration\DummyPipe::someHandler'
            ),
            SpanAssertion::build(
                'laravel.pipeline.pipe',
                'laravel_test_app',
                'web',
                'Tests\Integration\DummyPipe::someHandler'
            ),
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
