<?php

namespace DDTrace\Tests\Integration\Transport;

use DDTrace\Encoders\Json;
use DDTrace\Tracer;
use DDTrace\Transport\Http;
use DDTrace\Version;
use PHPUnit\Framework;
use Prophecy\Argument;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

final class HttpTest extends Framework\TestCase
{
    public function testSpanReportingFailsOnUnavailableAgent()
    {
        $logger = $this->prophesize(LoggerInterface::class);
        $logger
            ->debug(
                'Reporting of spans failed: Failed to connect to 0.0.0.0 port 8127: Connection refused, error code 7'
            )
            ->shouldBeCalled();

        $httpTransport = new Http(new Json(), $logger->reveal(), [
            'endpoint' => 'http://0.0.0.0:8127/v0.3/traces'
        ]);
        $tracer = new Tracer($httpTransport);

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
        $logger = $this->prophesize(LoggerInterface::class);
        $logger->debug(Argument::any())->shouldNotBeCalled();

        $httpTransport = new Http(new Json(), $logger->reveal(), [
            'endpoint' => 'http://0.0.0.0:8126/v0.3/traces'
        ]);
        $tracer = new Tracer($httpTransport);

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
        $logger = $this->prophesize(LoggerInterface::class);
        $logger->debug(Argument::any())->shouldNotBeCalled();

        $httpTransport = new Http(new Json(), $logger->reveal(), [
            'endpoint' => 'http://0.0.0.0:8126/v0.3/traces'
        ]);
        $tracer = new Tracer($httpTransport);

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
        $process = new Process("php -S localhost:8500 -t " . __DIR__ . "/request_replayer");
        $process->start();
        usleep(100000);

        $httpTransport = new Http(new Json(), null, [
            'endpoint' => 'http://localhost:8500/test-trace',
        ]);
        $tracer = new Tracer($httpTransport);

        $span = $tracer->startSpan('test');
        $span->finish();

        $traces = [[$span]];
        $httpTransport->send($traces);

        $traceRequest = json_decode(file_get_contents('http://localhost:8500/replay'), true);

        $this->assertEquals('php', $traceRequest['headers']['Datadog-Meta-Lang']);
        $this->assertEquals(\PHP_VERSION, $traceRequest['headers']['Datadog-Meta-Lang-Version']);
        $this->assertEquals(\PHP_SAPI, $traceRequest['headers']['Datadog-Meta-Lang-Interpreter']);
        $this->assertEquals(Version\VERSION, $traceRequest['headers']['Datadog-Meta-Tracer-Version']);

        $process->stop(0);
    }

    public function testSetHeader()
    {
        $process = new Process("php -S localhost:8500 -t " . __DIR__ . "/request_replayer");
        $process->start();
        usleep(100000);

        $httpTransport = new Http(new Json(), null, [
            'endpoint' => 'http://localhost:8500/test-trace',
        ]);
        $tracer = new Tracer($httpTransport);

        $span = $tracer->startSpan('test');
        $span->finish();

        $traces = [[$span]];
        $httpTransport->setHeader('X-my-custom-header', 'my-custom-value');
        $httpTransport->send($traces);

        $traceRequest = json_decode(file_get_contents('http://localhost:8500/replay'), true);

        $this->assertEquals('my-custom-value', $traceRequest['headers']['X-my-custom-header']);

        $process->stop(0);
    }
}
