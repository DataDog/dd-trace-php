<?php

namespace DDTrace\Tests\OpenTelemetry\Integration;

use DDTrace\Tests\Common\BaseTestCase;
use DDTrace\Tests\Common\TracerTestTrait;
use Fiber;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextStorage;
use OpenTelemetry\SDK\Sdk;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\TracerProvider;

final class FiberTest extends BaseTestCase
{
    use TracerTestTrait;

    public function ddSetUp(): void
    {
        \dd_trace_serialize_closed_spans();
        parent::ddSetUp();
    }

    public function ddTearDown()
    {
        parent::ddTearDown();
        Context::setStorage(new ContextStorage()); // Reset OpenTelemetry context
    }

    /**
     * @throws \Throwable
     */
    public function test_context_switching_ffi_observer()
    {
        $key = Context::createKey('-');
        $scope = Context::getCurrent()
            ->with($key, 'main')
            ->activate();

        $fiber = new Fiber(function () use ($key) {
            $scope = Context::getCurrent()
                ->with($key, 'fiber')
                ->activate();

            $this->assertSame('fiber:fiber', 'fiber:' . Context::getCurrent()->get($key));

            Fiber::suspend();

            $this->assertSame('fiber:fiber', 'fiber:' . Context::getCurrent()->get($key));

            $scope->detach();
        });

        $this->assertSame('main:main', 'main:' . Context::getCurrent()->get($key));

        $fiber->start();

        $this->assertSame('main:main', 'main:' . Context::getCurrent()->get($key));

        $fiber->resume();

        $this->assertSame('main:main', 'main:' . Context::getCurrent()->get($key));

        $scope->detach();
    }

    public function test_context_switching_ffi_observer_registered_on_startup()
    {
        $key = Context::createKey('-');

        $fiber = new Fiber(function () use ($key) {
            $scope = Context::getCurrent()
                ->with($key, 'fiber')
                ->activate();

            $this->assertSame('fiber:fiber', 'fiber:' . Context::getCurrent()->get($key));

            Fiber::suspend();

            $this->assertSame('fiber:fiber', 'fiber:' . Context::getCurrent()->get($key));

            $scope->detach();
        });


        $fiber->start();

        $this->assertSame('main:', 'main:' . Context::getCurrent()->get($key));

        $scope = Context::getCurrent()
            ->with($key, 'main')
            ->activate();

        $fiber->resume();

        $this->assertSame('main:main', 'main:' . Context::getCurrent()->get($key));

        $scope->detach();
    }
}
