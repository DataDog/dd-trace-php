<?php

namespace DDTrace\Tests\Integrations\Custom\Autoloaded;

use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

final class CircuitBreakerTest extends WebFrameworkTestCase
{
    const FLUSH_INTERVAL_MS = 500;

    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Custom/Version_Autoloaded/public/index.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_TRACE_BETA_SEND_TRACES_VIA_THREAD' => true,
            'DD_TRACE_BGS_ENABLED' => true,
            'DD_TRACE_ENCODER' => 'msgpack',
            'DD_TRACE_AGENT_MAX_CONSECUTIVE_FAILURES' => 2,
            'DD_TRACE_AGENT_FLUSH_INTERVAL' => self::FLUSH_INTERVAL_MS,
        ]);
    }

    protected function tearDown()
    {
        parent::tearDown();
        \dd_tracer_circuit_breaker_register_success();
    }

    public function testScenario()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $spec = GetSpec::create('Failed circuit breaker', '/circuit_breaker');
            $this->call($spec);

            // allow time for background sender to trigger
            usleep(self::FLUSH_INTERVAL_MS * 2 * 1000);
        });

        $this->assertExpectedSpans(
            $traces,
            [
                SpanAssertion::build(
                    'web.request',
                    'web.request',
                    'web',
                    'GET /circuit_breaker'
                )->withExactTags([
                    'http.method' => 'GET',
                    'http.url' => '/circuit_breaker',
                    'http.status_code' => '200',
                    'integration.name' => 'web',
                ]),
            ]
        );
    }
}
