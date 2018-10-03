<?php

namespace DDTrace\Tests\Unit;

use DDTrace\Scope;
use DDTrace\ScopeManager;
use OpenTracing\Span;
use OpenTracing\NoopSpan;
use PHPUnit\Framework;

final class DDTraceTest extends Framework\TestCase
{
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
            //Don't call close to test if the method was successfully overriden
        });
        $val = 0;

        $span = $this->prophesize(Span::class);
        $span->finish()->shouldNotBeCalled();
        $scope = new Scope(new ScopeManager(), $span->reveal(), true);
        $scope->close();
    }
}
