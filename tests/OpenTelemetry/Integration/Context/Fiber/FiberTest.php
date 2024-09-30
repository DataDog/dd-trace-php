<?php

namespace DDTrace\Tests\OpenTelemetry\Integration;

use DDTrace\HookData;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Tests\Common\BaseTestCase;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\SpanAssertionTrait;
use DDTrace\Tests\Common\TracerTestTrait;
use Fiber;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ExecutionContextAwareInterface;
use OpenTelemetry\SDK\Trace\TracerProvider;
use function DDTrace\close_span;
use function DDTrace\start_span;

final class FiberTest extends BaseTestCase
{
    use TracerTestTrait, SpanAssertionTrait;

    public function ddSetUp(): void
    {
        \dd_trace_serialize_closed_spans();
        parent::ddSetUp();
    }

    public function ddTearDown()
    {
        parent::ddTearDown();
    }

    public function testFiberInteroperabilityStackSwitch()
    {
        // See tests/ext/fiber_stack_switch.phpt

        if (version_compare(PHP_VERSION, '8.1.0', '<')) {
            $this->markTestSkipped('Fibers are only supported in PHP 8.1+');
        }

        $traces = $this->isolateTracer(function () {
            $tracer = (new TracerProvider())->getTracer('OpenTelemetry.TestTracer');

            $parentSpan = $tracer->spanBuilder('parent')
                ->setSpanKind(SpanKind::KIND_SERVER)
                ->startSpan();
            $parentSpanScope = $parentSpan->activate();

            $otherFiberFn = function () use ($tracer) {
                $currentSpan = Span::getCurrent();
                $this->assertSame('otherFiber', $currentSpan->getName());

                $span = start_span();
                $span->name = 'dd.otherFiber';

                $currentSpan = Span::getCurrent();
                $this->assertSame('dd.otherFiber', $currentSpan->getName());

                Fiber::suspend();

                $currentSpan = Span::getCurrent();
                $this->assertSame('dd.otherFiber', $currentSpan->getName());

                close_span();

                $currentSpan = Span::getCurrent();
                $this->assertSame('otherFiber', $currentSpan->getName());

                throw new \Exception("ex");
            };

            $otherFiber = null;
            $inFiberFn = function () use ($parentSpan, $otherFiberFn, &$otherFiber, $tracer) {
                $currentSpan = Span::getCurrent();
                $this->assertSame('inFiber', $currentSpan->getName());

                $inFiberOTelSpan = $tracer->spanBuilder('otel.inFiber')->startSpan();
                $inFiberOTelScope = $inFiberOTelSpan->activate();

                $otherFiber = new Fiber($otherFiberFn(...));
                $otherFiber->start();

                $currentSpan = Span::getCurrent();
                $this->assertSame('otel.inFiber', $currentSpan->getName());

                Fiber::suspend(123);

                $currentSpan = Span::getCurrent();
                $this->assertSame('otel.inFiber', $currentSpan->getName());

                $inFiberOTelScope->detach();
                $inFiberOTelSpan->end();

                $currentSpan = Span::getCurrent();
                $this->assertSame('inFiber', $currentSpan->getName());
            };

            \DDTrace\trace_method('Fiber', 'start', function (SpanData $span) {
                $span->name = 'fiber.start';
            });

            \DDTrace\trace_method('Fiber', 'suspend', [
                'posthook' => function (SpanData $span) {
                    $span->name = 'fiber.suspend';
                },
                'recurse' => true
            ]);

            \DDTrace\trace_method('Fiber', 'resume', function (SpanData $span) {
                $span->name = 'fiber.resume';
            });

            \DDTrace\install_hook($inFiberFn, function (HookData $hook) {
                $span = $hook->span();
                $span->name = 'inFiber';
            });

            \DDTrace\install_hook($otherFiberFn, function (HookData $hook) {
                $span = $hook->span();
                $span->name = 'otherFiber';
            });

            $currentSpan = Span::getCurrent();
            $this->assertSame('parent', $currentSpan->getName());

            $fiber = new Fiber($inFiberFn(...));
            $fiber->start();

            $currentSpan = Span::getCurrent();
            $this->assertSame('parent', $currentSpan->getName());

            $parentSpan->setAttribute('http.method', 'GET');

            $fiber->resume();

            $currentSpan = Span::getCurrent();
            $this->assertSame('parent', $currentSpan->getName());

            $parentSpan->setAttribute('http.uri', '/parent');

            try {
                $otherFiber->resume();
            } catch (\Exception) {
            }

            $currentSpan = Span::getCurrent();
            $this->assertSame('parent', $currentSpan->getName());

            $parentSpanScope->detach();
            $parentSpan->end();
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('server.request', 'parent')->withChildren([
                SpanAssertion::exists('fiber.start')->withChildren([
                    SpanAssertion::exists('inFiber')->withChildren([
                        SpanAssertion::exists('internal', 'otel.inFiber')->withChildren([
                            SpanAssertion::exists('otherFiber')->withChildren([
                                SpanAssertion::exists('dd.otherFiber')->withChildren([
                                    SpanAssertion::exists('fiber.suspend')
                                ])
                            ])->withExistingTagsNames([
                                Tag::ERROR_TYPE, Tag::ERROR_STACK, Tag::ERROR_MSG
                            ]),
                            SpanAssertion::exists('fiber.suspend')
                        ])
                    ])
                ]),
                SpanAssertion::exists('fiber.resume'),
                SpanAssertion::exists('fiber.resume')->withExistingTagsNames([
                    Tag::ERROR_TYPE, Tag::ERROR_STACK, Tag::ERROR_MSG
                ])
            ])
        ]);
    }
}
