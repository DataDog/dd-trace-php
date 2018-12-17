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
        dd_trace('DDTrace\Scope', "close", function () {
            call_user_func_array([$this, 'close'], func_get_args());
        });
        $val = 0;

        $span = $this->prophesize('OpenTracing\Span');
        $span->finish()->shouldBeCalled();
        $scope = new Scope(new ScopeManager(), $span->reveal(), true);
        $scope->close();
    }

    public function testMethodCanBeOverridenByTrace()
    {
        dd_trace('DDTrace\Scope', "close", function () {
            // Don't call close() to verify the method was successfully overwritten
        });
        $val = 0;

        $span = $this->prophesize('OpenTracing\Span');
        $span->finish()->shouldNotBeCalled();
        $scope = new Scope(new ScopeManager(), $span->reveal(), true);
        $scope->close();
    }
}
