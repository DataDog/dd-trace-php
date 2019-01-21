<?php

namespace DDTrace\Tests\Integration\Transport;

use DDTrace\Encoders\Json;
use DDTrace\Tests\Common\AgentReplayerTrait;
use DDTrace\Tracer;
use DDTrace\Transport\Http;
use DDTrace\Version;
use DDTrace\GlobalTracer;
use PHPUnit\Framework;
use Prophecy\Argument;

final class HttpTest extends Framework\TestCase
{
    use AgentReplayerTrait;

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
        $logger = $this->prophesize('DDTrace\Log\LoggerInterface');
        $logger
            ->debug(
                'Reporting of spans failed: Failed to connect to 0.0.0.0 port 8127: Connection refused, error code 7'
            )
            ->shouldBeCalled();

        $httpTransport = new Http(new Json(), $logger->reveal(), [
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

        $traces = [
            [$span],
        ];

        $httpTransport->send($traces);
    }

    public function testSpanReportingSuccess()
    {
        $logger = $this->prophesize('DDTrace\Log\LoggerInterface');
        $logger->debug(Argument::any())->shouldNotBeCalled();

        $httpTransport = new Http(new Json(), $logger->reveal(), [
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

        $traces = [
            [$span, $childSpan],
        ];

        $httpTransport->send($traces);
    }

    public function testSilentlySendTraces()
    {
        $logger = $this->prophesize('DDTrace\Log\LoggerInterface');
        $logger->debug(Argument::any())->shouldNotBeCalled();

        $httpTransport = new Http(new Json(), $logger->reveal(), [
            'endpoint' => $this->agentTracesUrl()
        ]);
        $tracer = new Tracer($httpTransport);
        GlobalTracer::set($tracer);

        $span = $tracer->startSpan('test');
        $span->finish();

        $traces = [[$span]];

        ob_start();
        $httpTransport->send($traces);
        $output = ob_get_clean();

        $this->assertSame('', $output);
    }

    public function testSendsMetaHeaders()
    {
        $httpTransport = new Http(new Json(), null, [
            'endpoint' => $this->getAgentReplayerEndpoint(),
        ]);
        $tracer = new Tracer($httpTransport);
        GlobalTracer::set($tracer);

        $span = $tracer->startSpan('test');
        $span->finish();

        $traces = [[$span]];
        $httpTransport->send($traces);

        $traceRequest = $this->getLastAgentRequest();

        $this->assertEquals('php', $traceRequest['headers']['Datadog-Meta-Lang']);
        $this->assertEquals(\PHP_VERSION, $traceRequest['headers']['Datadog-Meta-Lang-Version']);
        $this->assertEquals(\PHP_SAPI, $traceRequest['headers']['Datadog-Meta-Lang-Interpreter']);
        $this->assertEquals(Tracer::VERSION, $traceRequest['headers']['Datadog-Meta-Tracer-Version']);
    }

    public function testSetHeader()
    {
        $httpTransport = new Http(new Json(), null, [
            'endpoint' => $this->getAgentReplayerEndpoint(),
        ]);
        $tracer = new Tracer($httpTransport);
        GlobalTracer::set($tracer);

        $span = $tracer->startSpan('test');
        $span->finish();

        $traces = [[$span]];
        $httpTransport->setHeader('X-my-custom-header', 'my-custom-value');
        $httpTransport->send($traces);

        $traceRequest = $this->getLastAgentRequest();

        $this->assertEquals('my-custom-value', $traceRequest['headers']['X-my-custom-header']);
    }
}
