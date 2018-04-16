<?php

namespace DDTrace\Tests\Unit;

use DDTrace\Scope;
use DDTrace\ScopeManager;
use OpenTracing\Span;
use PHPUnit_Framework_TestCase;

final class ScopeTest extends PHPUnit_Framework_TestCase
{
    public function testScopeFinishesSpanOnClose()
    {
        $span = $this->prophesize(Span::class);
        $span->finish()->shouldBeCalled();
        $scope = new Scope(new ScopeManager(), $span->reveal(), true);
        $scope->close();
    }

    public function testScopeDoesNotFinishesSpanOnClose()
    {
        $span = $this->prophesize(Span::class);
        $span->finish()->shouldNotBeCalled();
        $scope = new Scope(new ScopeManager(), $span->reveal(), false);
        $scope->close();
    }
}
