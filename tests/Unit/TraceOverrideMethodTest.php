<?php

namespace DDTrace\Tests\Unit;

use DDTrace\Scope;
use DDTrace\ScopeManager;
use OpenTracing\Span;
use PHPUnit\Framework;

final class TraceOverrideMethodTest extends Framework\TestCase
{
    protected function setUp()
    {
        if (!extension_loaded('ddtrace')) {
            $this->markTestSkipped(
                'The ddtrace extension is not loaded.'
            );
        }
    }

    public function testMethodInvokesExpectedResults()
    {
        dd_trace(Scope::class, "close", function (...$args) {
            $this->close(...$args);
        });
        $val = 0;

        $span = $this->prophesize(Span::class);
        $span->finish()->shouldBeCalled();
        $scope = new Scope(new ScopeManager(), $span->reveal(), true);
        $scope->close();
    }

    public function testMethodCanBeOverridenByTrace()
    {
        dd_trace(Scope::class, "close", function (...$args) {
            // Don't call close() to verify the method was successfully overwritten
        });
        $val = 0;

        $span = $this->prophesize(Span::class);
        $span->finish()->shouldNotBeCalled();
        $scope = new Scope(new ScopeManager(), $span->reveal(), true);
        $scope->close();
    }
}
