<?php

namespace DDTrace\Tests\DistributedTracing;

use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\RequestSpec;

class SamplingPriorityTest extends WebFrameworkTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../Frameworks/Custom/Version_Not_Autoloaded/index.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_DISTRIBUTED_TRACING' => 'true',
            'DD_PRIORITY_SAMPLING' => 'true',
            'DD_TRACE_NO_AUTOLOADER' => 'true',
        ]);
    }

    public function testKeepPriority()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $spec  = new RequestSpec(
                'Keep',
                'GET',
                '/index.php',
                [
                    'x-datadog-trace-id: 100',
                    'x-datadog-parent-id: 200',
                    'x-datadog-sampling-priority: 2',
                ]
            );
            $this->call($spec);
        });
        $this->assertOneExpectedSpan(
            $traces,
            SpanAssertion::build(
                'web.request',
                'web.request',
                'web',
                'GET /index.php'
            )->withExactTags(
                SpanAssertion::NOT_TESTED
            )->withExactMetrics([
                '_sampling_priority_v1' => 2,
            ])
        );
    }

    public function testDropPriority()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $spec  = new RequestSpec(
                'Drop',
                'GET',
                '/index.php',
                [
                    'x-datadog-trace-id: 100',
                    'x-datadog-parent-id: 200',
                    'x-datadog-sampling-priority: 0',
                ]
            );
            $this->call($spec);
        });
        $this->assertOneExpectedSpan(
            $traces,
            SpanAssertion::build(
                'web.request',
                'web.request',
                'web',
                'GET /index.php'
            )->withExactTags(
                SpanAssertion::NOT_TESTED
            )->withExactMetrics([
                '_sampling_priority_v1' => 0,
            ])
        );
    }
}
