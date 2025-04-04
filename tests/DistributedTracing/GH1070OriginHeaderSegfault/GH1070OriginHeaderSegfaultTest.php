<?php

namespace DDTrace\Tests\DistributedTracing\GH1070OriginHeaderSegfault;

use DDTrace\Tests\Common\IntegrationTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

final class GH1070OriginHeaderSegfaultTest extends IntegrationTestCase
{
    const URL = 'http://httpbin-integration';

    public function testDistributedTracingWithDatadogOriginHeader()
    {
        $curlInfo = [];
        $response = null;
        $this->inWebServer(
            function ($execute) use (&$response) {
                $response = json_decode($execute(GetSpec::create(
                    'GET',
                    '/',
                    [
                        'x-datadog-trace-id: 123',
                        'x-datadog-parent-id: 456',
                        'x-datadog-sampling-priority: 1',
                        'x-datadog-origin: some-origin',
                    ]
                )), 1);
            },
            __DIR__ . '/index.php',
            [
                'DD_TRACE_DEBUG' => 1,
            ],
            [],
            $curlInfo
        );

        $this->assertSame(200, $curlInfo['http_code']);
        $this->assertNull($response);
    }
}
