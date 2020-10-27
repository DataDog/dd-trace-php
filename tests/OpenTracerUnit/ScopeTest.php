<?php

namespace DDTrace\Tests\OpenTracerUnit;

use DDTrace\OpenTracer\Scope;
use DDTrace\ScopeManager;
use DDTrace\Scope as DDScope;
use DDTrace\Tests\Common\BaseTestCase;

final class ScopeTest extends BaseTestCase
{
    public function testScopeFinishesSpanOnClose()
    {
        $span = $this->prophesize('DDTrace\Contracts\Span');
        $span->finish()->shouldBeCalled();
        $ddScope = new DDScope(new ScopeManager(), $span->reveal(), true);
        $scope = new Scope($ddScope);
        $scope->close();
    }

    public function testScopeDoesNotFinishesSpanOnClose()
    {
        $span = $this->prophesize('DDTrace\Contracts\Span');
        $span->finish()->shouldNotBeCalled();
        $ddScope = new DDScope(new ScopeManager(), $span->reveal(), false);
        $scope = new Scope($ddScope);
        $scope->close();
    }
}
