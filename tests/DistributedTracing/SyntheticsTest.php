<?php

namespace DDTrace\Tests\DistributedTracing;

use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\RequestSpec;

class SyntheticsTest extends WebFrameworkTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../Frameworks/Custom/Version_Not_Autoloaded/index.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            // Ensure that Synthetics requests do not get sampled
            // even with a really low sampling rate
            'DD_SAMPLING_RATE' => '0.0',
            // Disabling priority sampling will break Synthetic requests
            'DD_PRIORITY_SAMPLING' => 'true',
            // Disabling distributed tracing will break Synthetic requests
            'DD_DISTRIBUTED_TRACING' => 'true',
            'DD_TRACE_NO_AUTOLOADER' => 'true',
        ]);
    }

    public function testSyntheticsRequest()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $spec = new RequestSpec(
                'Synthetics Request',
                'GET',
                '/index.php',
                [
                    'x-datadog-trace-id: 123456',
                    'x-datadog-parent-id: 0',
                    'x-datadog-sampling-priority: 1',
                    'x-datadog-origin: synthetics-browser',
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
            )->withExactTags([
                'http.method' => 'GET',
                'http.url' => '/index.php',
                'http.status_code' => '200',
                'integration.name' => 'web',
                '_dd.origin' => 'synthetics-browser',
            ])->withExactMetrics([
                '_sampling_priority_v1' => 1,
            ])
        );
        $this->assertSame(123456, $traces[0][0]['trace_id']);
    }
}
