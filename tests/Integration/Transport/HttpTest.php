<?php

namespace DDTrace\Tests\Integration\Transport;

use DDTrace\Encoders\Json;
use DDTrace\Tests\Common\AgentReplayerTrait;
use DDTrace\Tests\Unit\BaseTestCase;
use DDTrace\Tracer;
use DDTrace\Transport\Http;
use DDTrace\GlobalTracer;

final class HttpTest extends BaseTestCase
{
    use AgentReplayerTrait;

    protected function tearDown()
    {
        // reset the circuit breker consecutive failures count and close it
        \dd_tracer_circuit_breaker_register_success();
        putenv('DD_TRACE_AGENT_ATTEMPT_RETRY_TIME_MSEC=default');

        parent::tearDown();
    }

    public function agentUrl()
    {
        return 'http://' . ($_SERVER["DDAGENT_HOSTNAME"] ? $_SERVER["DDAGENT_HOSTNAME"] :  "localhost") . ':8126';
    }

    public function agentTracesUrl()
    {
        return $this->agentUrl() . '/v0.3/traces';
    }

    public function testSpanReportingFailsOnUnavailableAgent()
    {
        $logger = $this->withDebugLogger();

        $httpTransport = new Http(new Json(), [
            'endpoint' => 'http://0.0.0.0:8127/v0.3/traces'
        ]);
        $tracer = new Tracer($httpTransport);
        GlobalTracer::set($tracer);

        $span = $tracer->startSpan('test', [
            'tags' => [
                'key1' => 'value1',
            ]
        ]);

        $span->finish();

        $httpTransport->send($tracer);
        $this->assertTrue($logger->has(
            'error',
            'Reporting of spans failed: 7 / Failed to connect to 0.0.0.0 port 8127: Connection refused'
        ));
    }

    public function testCircuitBreakerBehavingAsExpected()
    {
        // make the circuit breaker fail fast
        putenv('DD_TRACE_AGENT_MAX_CONSECUTIVE_FAILURES=1');

        $logger = $this->withDebugLogger();

        $badHttpTransport = new Http(new Json(), [
            'endpoint' => 'http://0.0.0.0:8127/v0.3/traces'
        ]);
        $goodHttpTransport = new Http(new Json(), [
            'endpoint' => $this->agentTracesUrl()
        ]);

        $tracer = new Tracer(null);
        GlobalTracer::set($tracer);
        $tracer->startSpan('test', [])->finish();

        $this->assertTrue(\dd_tracer_circuit_breaker_info()['closed']);
        $badHttpTransport->send($tracer); // bad transport will immediately open the circuit
        $this->assertFalse(\dd_tracer_circuit_breaker_info()['closed']);

        $goodHttpTransport->send($tracer); // good transport
        $this->assertFalse(\dd_tracer_circuit_breaker_info()['closed']);

        // should close the circuit once retry time has passed
        putenv('DD_TRACE_AGENT_ATTEMPT_RETRY_TIME_MSEC=0');
        $goodHttpTransport->send($tracer);

        $this->assertTrue(\dd_tracer_circuit_breaker_info()['closed']);

        $this->assertTrue($logger->has(
            'error',
            'Reporting of spans failed: 7 / Failed to connect to 0.0.0.0 port 8127: Connection refused'
        ));

        $this->assertTrue($logger->has(
            'error',
            'Reporting of spans skipped due to open circuit breaker'
        ));
    }

    public function testSpanReportingSuccess()
    {
        $logger = $this->withDebugLogger();

        $httpTransport = new Http(new Json(), [
            'endpoint' => $this->agentTracesUrl()
        ]);
        $tracer = new Tracer($httpTransport);
        GlobalTracer::set($tracer);

        $span = $tracer->startSpan('test', [
            'tags' => [
                'key1' => 'value1',
            ]
        ]);

        $childSpan = $tracer->startSpan('child_test', [
            'child_of' => $span,
            'tags' => [
                'key2' => 'value2',
            ]
        ]);

        $childSpan->finish();

        $span->finish();

        $httpTransport->send($tracer);
        $this->assertTrue($logger->has('debug', 'About to send trace(s) to the agent'));
        $this->assertTrue($logger->has('debug', 'Traces successfully sent to the agent'));
    }

    public function testSendsMetaHeaders()
    {
        $httpTransport = new Http(new Json(), [
            'endpoint' => $this->getAgentReplayerEndpoint(),
        ]);
        $tracer = new Tracer($httpTransport);
        GlobalTracer::set($tracer);

        $span = $tracer->startSpan('test');
        $span->finish();

        $httpTransport->send($tracer);

        $traceRequest = $this->getLastAgentRequest();

        $this->assertEquals('php', $traceRequest['headers']['Datadog-Meta-Lang']);
        $this->assertEquals(\PHP_VERSION, $traceRequest['headers']['Datadog-Meta-Lang-Version']);
        $this->assertEquals(\PHP_SAPI, $traceRequest['headers']['Datadog-Meta-Lang-Interpreter']);
        $this->assertEquals(Tracer::version(), $traceRequest['headers']['Datadog-Meta-Tracer-Version']);
        $this->assertRegExp('/^[0-9a-f]{64}$/', $traceRequest['headers']['Datadog-Container-Id']);
        $this->assertEquals('1', $traceRequest['headers']['X-Datadog-Trace-Count']);
    }

    public function testSetHeader()
    {
        $httpTransport = new Http(new Json(), [
            'endpoint' => $this->getAgentReplayerEndpoint(),
        ]);
        $tracer = new Tracer($httpTransport);
        GlobalTracer::set($tracer);

        $span = $tracer->startSpan('test');
        $span->finish();

        $httpTransport->setHeader('X-my-custom-header', 'my-custom-value');
        $httpTransport->send($tracer);

        $traceRequest = $this->getLastAgentRequest();

        $this->assertEquals('my-custom-value', $traceRequest['headers']['X-my-custom-header']);
    }
}
