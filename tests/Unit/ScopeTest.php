<?php

namespace DDTrace\Tests\Unit;

use DDTrace\Scope;
use DDTrace\ScopeManager;
use DDTrace\Tests\Common\BaseTestCase;

final class ScopeTest extends BaseTestCase
{
    public function testScopeFinishesSpanOnClose()
    {
        $span = $this->prophesize('DDTrace\Contracts\Span');
        $span->finish()->shouldBeCalled();
        $scope = new Scope(new ScopeManager(), $span->reveal(), true);
        $scope->close();
    }

    public function testScopeDoesNotFinishesSpanOnClose()
    {
        $span = $this->prophesize('DDTrace\Contracts\Span');
        $span->finish()->shouldNotBeCalled();
        $scope = new Scope(new ScopeManager(), $span->reveal(), false);
        $scope->close();
    }
}
