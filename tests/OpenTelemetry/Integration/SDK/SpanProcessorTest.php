<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Integration\SDK;

use DDTrace\Tests\Common\BaseTestCase;
use DDTrace\Tests\Common\SpanAssertionTrait;
use DDTrace\Tests\Common\TracerTestTrait;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\ContextStorage;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Sdk;
use OpenTelemetry\SDK\Trace\Behavior\SpanExporterTrait;
use OpenTelemetry\SDK\Trace\ReadableSpanInterface;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\SpanProcessor\MultiSpanProcessor;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\SpanProcessorInterface;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class SpanProcessorTest extends BaseTestCase
{
    use TracerTestTrait, SpanAssertionTrait;

    public function ddSetUp(): void
    {
        \dd_trace_serialize_closed_spans();
        self::putEnv("DD_TRACE_GENERATE_ROOT_SPAN=0");
        parent::ddSetUp();
    }

    public function ddTearDown()
    {
        Context::setStorage(new ContextStorage()); // Reset OpenTelemetry context
        self::putEnv("DD_TRACE_GENERATE_ROOT_SPAN=");
        parent::ddTearDown();
        \dd_trace_serialize_closed_spans();
    }

    public function test_parent_context_should_be_passed_to_span_processor(): void
    {
        $parentContext = Context::getRoot();

        $spanProcessor = $this->createMock(SpanProcessorInterface::class);
        $spanProcessor
            ->expects($this->once())
            ->method('onStart')
            ->with($this->isInstanceOf(SpanInterface::class), $this->equalTo($parentContext))
        ;

        $tracerProvider = new TracerProvider($spanProcessor);
        $tracer = $tracerProvider->getTracer('OpenTelemetry.Test');
        $tracer->spanBuilder('test.span')->setParent($parentContext)->startSpan();
    }

    public function test_current_context_should_be_passed_to_span_processor_by_default(): void
    {
        $currentContext = Context::getCurrent();

        $spanProcessor = $this->createMock(SpanProcessorInterface::class);
        $spanProcessor
            ->expects($this->once())
            ->method('onStart')
            ->with($this->isInstanceOf(SpanInterface::class), $this->equalTo($currentContext))
        ;

        $tracerProvider = new TracerProvider($spanProcessor);
        $tracer = $tracerProvider->getTracer('OpenTelemetry.Test');
        $tracer->spanBuilder('test.span')->startSpan();
    }

    public function test_overwrite_span_resource_in_span_processor(): void
    {
        $overwriteSpanProcessor = new class implements SpanProcessorInterface {
            public function onStart(SpanInterface $span, ContextInterface $parentContext): void
            {
                $span->updateName('overwritten');
            }

            public function onEnd(ReadableSpanInterface $span): void
            {
            }

            public function shutdown(?CancellationInterface $cancellation = null): bool
            {
            }

            /**
             * @inheritDoc
             */
            public function forceFlush(?CancellationInterface $cancellation = null): bool
            {
            }
        };

        $traces = $this->isolateTracer(function () use ($overwriteSpanProcessor) {
            $tracer =  (new TracerProvider($overwriteSpanProcessor))->getTracer('OpenTelemetry.Test');
            $span = $tracer->spanBuilder('test.span')->startSpan();
            $span->end();
        });

        $span = $traces[0][0];
        $this->assertSame('overwritten', $span['resource']);
    }

    public function test_span_exporter_to_array(): void
    {
        $spanExporter = new class implements SpanExporterInterface
        {
            use SpanExporterTrait;

            private array $spans = [];

            public function __construct(?array $spans = null)
            {
                $this->spans = $spans ?? [];
            }

            protected function doExport(iterable $spans): bool
            {
                foreach ($spans as $span) {
                    $this->spans[] = $span;
                }

                return true;
            }

            public function getSpans(): array
            {
                return $this->spans;
            }

            public function getStorage(): array
            {
                return $this->spans;
            }
        };

        $tracerProvider = new TracerProvider(new SimpleSpanProcessor($spanExporter));
        $tracer = $tracerProvider->getTracer('OpenTelemetry.Test');

        $span = $tracer->spanBuilder('test.span')->startSpan();
        $span->end();

        $this->assertCount(1, $spanExporter->getSpans());
        $this->assertSame($span->getContext(), $spanExporter->getSpans()[0]->getContext());
    }
}
