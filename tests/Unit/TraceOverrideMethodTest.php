<?php

namespace DDTrace\Tests\Unit;

use DDTrace\Scope;
use DDTrace\ScopeManager;
use PHPUnit\Framework;

final class TraceOverrideMethodTest extends Framework\TestCase
{
    protected function tearDown()
    {
        // Why this?
        // Test 'testMethodCanBeOverridenByTrace' trace this method and prevent it from being called, as it adds an
        // empty hook. This causes other tests they relies on Scope::close executed after this test to fail.
        // The proper way to fix is is probably to add a dd_trace_clear() the drops entirely the lookup table.
        // In the meantime we can propose this workaround.
        dd_trace('DDTrace\Scope', "close", function () {
            call_user_func_array([$this, 'close'], func_get_args());
        });
        parent::tearDown();
    }

    public function testMethodInvokesExpectedResults()
    {
        dd_trace('DDTrace\Scope', "close", function () {
            call_user_func_array([$this, 'close'], func_get_args());
        });
        $val = 0;

        $span = $this->prophesize('DDTrace\SpanInterface');
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

        $span = $this->prophesize('DDTrace\SpanInterface');
        $span->finish()->shouldNotBeCalled();
        $scope = new Scope(new ScopeManager(), $span->reveal(), true);
        $scope->close();
    }
}
