<?php

namespace DDTrace\Tests\DistributedTracing;

use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\RequestSpec;

class SamplingPriorityTest extends WebFrameworkTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../Frameworks/Custom/Version_Autoloaded/public/index.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_PRIORITY_SAMPLING' => 'true',
        ]);
    }

    public function testKeepPriority()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $spec  = new RequestSpec(
                'Keep',
                'GET',
                '/simple',
                [
                    'x-datadog-parent-id: 0',
                    'x-datadog-trace-id: 0',
                    'x-datadog-sampling-priority: 1',
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
                'GET /simple'
            )->withExactTags(
                SpanAssertion::NOT_TESTED
            )->withExactMetrics([
                '_sampling_priority_v1' => 1,
            ])
        );
    }

    public function testDropPriority()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $spec  = new RequestSpec(
                'Drop',
                'GET',
                '/simple',
                [
                    'x-datadog-parent-id: 0',
                    'x-datadog-trace-id: 0',
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
                'GET /simple'
            )->withExactTags(
                SpanAssertion::NOT_TESTED
            )->withExactMetrics([
                '_sampling_priority_v1' => 0,
            ])
        );
    }
}
