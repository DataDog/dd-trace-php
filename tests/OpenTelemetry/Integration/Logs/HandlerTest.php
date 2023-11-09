<?php

namespace DDTrace\Tests\OpenTelemetry\Integration\Logs;

use ArrayObject;
use DDTrace\Tests\Common\IntegrationTestCase;
use Monolog\Logger;
use OpenTelemetry\API\Logs\LogRecord;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextStorage;
use OpenTelemetry\Contrib\Logs\Monolog\Handler;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeFactory;
use OpenTelemetry\SDK\Logs\Exporter\InMemoryExporter;
use OpenTelemetry\SDK\Logs\LoggerProvider;
use OpenTelemetry\SDK\Logs\Processor\SimpleLogRecordProcessor;
use OpenTelemetry\SDK\Logs\ReadWriteLogRecord;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;
use function DDTrace\active_span;
use function DDTrace\close_span;
use function DDTrace\start_span;
use function DDTrace\trace_id;

class HandlerTest extends IntegrationTestCase
{
    private ArrayObject $storage;
    private Logger $logger;

    public function setUp(): void
    {
        Context::setStorage(new ContextStorage()); // Reset OpenTelemetry context
        $this->storage = new ArrayObject();
        $exporter = new InMemoryExporter($this->storage);
        $loggerProvider = new LoggerProvider(
            new SimpleLogRecordProcessor($exporter),
            new InstrumentationScopeFactory(Attributes::factory()),
        );
        $handler = new Handler($loggerProvider, 200);
        $this->logger = new Logger('test');
        $this->logger->pushHandler($handler);
    }

    public function ddTearDown()
    {
        parent::ddTearDown();
        $this->putEnvAndReloadConfig([
            'DD_TRACE_APPEND_TRACE_IDS_TO_LOGS=',
            'APP_NAME=',
            'DD_ENV=',
            'DD_SERVICE=',
            'DD_VERSION=',
            'DD_TRACE_128_BIT_TRACEID_LOGGING_ENABLED=',
            'DD_TRACE_128_BIT_TRACEID_GENERATION_ENABLED='
        ]);
        \dd_trace_serialize_closed_spans();
    }

    public static function getTracer()
    {
        // Generate a unique key of length 10
        $uniqueKey = substr(md5(uniqid()), 0, 10);
        $tracer = (new TracerProvider([], new AlwaysOnSampler()))->getTracer("OpenTelemetry.TracerTest$uniqueKey");
        return $tracer;
    }

    public function test_log_info(): void
    {
        $this->assertCount(0, $this->storage);
        /** @psalm-suppress UndefinedDocblockClass */
        $this->logger->info('foo');
        $this->assertCount(1, $this->storage);
        /** @var ReadWriteLogRecord $record */
        $record = $this->storage->offsetGet(0);
        $this->assertInstanceOf(LogRecord::class, $record);
        $this->assertSame('INFO', $record->getSeverityText());
        $this->assertSame(9, $record->getSeverityNumber());
        $this->assertGreaterThan(0, $record->getTimestamp());
        $this->assertSame('test', $record->getInstrumentationScope()->getName(), 'scope name is set from logger name');
    }

    public function test_log_debug_is_not_handled(): void
    {
        //handler is configured with info level, so debug should be ignored
        $this->assertCount(0, $this->storage);
        /** @psalm-suppress UndefinedDocblockClass */
        $this->logger->debug('debug message');
        $this->assertCount(0, $this->storage);
    }

    public function test_logs_correlation_context(): void
    {
        $this->putEnvAndReloadConfig([
            'DD_TRACE_APPEND_TRACE_IDS_TO_LOGS=0',
            'APP_NAME=logs_test',
            'DD_ENV=my-env',
            'DD_SERVICE=my-service',
            'DD_VERSION=4.2',
            'DD_TRACE_128_BIT_TRACEID_LOGGING_ENABLED=1',
            'DD_TRACE_128_BIT_TRACEID_GENERATION_ENABLED=1'
        ]);

        $this->assertCount(0, $this->storage);

        $spanId = "";
        $traceId = "";

        $this->isolateTracer(function () use (&$spanId, &$traceId) {
            $tracer = self::getTracer();
            $span = $tracer->spanBuilder('test_span')->startSpan();
            $scope = $span->activate();

            $traceId = $span->getContext()->getTraceId();
            $spanId = active_span()->id;

            /** @psalm-suppress UndefinedDocblockClass */
            $this->logger->warning('My Warning Message');

            $scope->detach();
            $span->end();
        });

        $this->assertCount(1, $this->storage);
        /** @var ReadWriteLogRecord $record */
        $record = $this->storage->offsetGet(0);
        $context = $record->getAttributes()->toArray()['context'];

        $this->assertSame($spanId, $context['dd.span_id']);
        $this->assertSame($traceId, $context['dd.trace_id']);
        $this->assertSame('my-service', $context['dd.service']);
        $this->assertSame('4.2', $context['dd.version']);
        $this->assertSame('my-env', $context['dd.env']);
    }

    public function test_logs_correlation_placeholders(): void
    {
        $this->putEnvAndReloadConfig([
            'DD_TRACE_APPEND_TRACE_IDS_TO_LOGS=0',
            'APP_NAME=logs_test',
            'DD_ENV=my-env',
            'DD_SERVICE=my-service',
            'DD_VERSION=4.2',
            'DD_TRACE_128_BIT_TRACEID_LOGGING_ENABLED=1',
            'DD_TRACE_128_BIT_TRACEID_GENERATION_ENABLED=1'
        ]);

        $this->assertCount(0, $this->storage);

        $spanId = "";
        $traceId = "";

        $this->isolateTracer(function () use (&$spanId, &$traceId) {
            $tracer = self::getTracer();
            $span = $tracer->spanBuilder('test_span')->startSpan();
            $scope = $span->activate();

            $traceId = $span->getContext()->getTraceId();
            $spanId = active_span()->id;

            /** @psalm-suppress UndefinedDocblockClass */
            $this->logger->info('My Warning Message [%dd.trace_id% %dd.span_id% %dd.service% %dd.version% %dd.env%]');

            $scope->detach();
            $span->end();
        });

        $this->assertCount(1, $this->storage);
        /** @var ReadWriteLogRecord $record */
        $record = $this->storage->offsetGet(0);
        $this->assertSame(
            "My Warning Message [dd.trace_id=\"$traceId\" dd.span_id=\"$spanId\" dd.service=\"my-service\" dd.version=\"4.2\" dd.env=\"my-env\"]",
            $record->getBody()
        );
    }
}
