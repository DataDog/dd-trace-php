<?php

namespace DDTrace\Tests\OpenTracer1Unit;

use DDTrace\OpenTracer1\ScopeManager;
use DDTrace\OpenTracer1\Tracer;
use DDTrace\ScopeManager as DDScopeManager;
use DDTrace\Tests\Common\BaseTestCase;
use DDTrace\Transport\Noop as NoopTransport;

final class ScopeManagerTest extends BaseTestCase
{
    const OPERATION_NAME = 'test_name';

    public function testGetActiveFailsWithNoActiveSpans()
    {
        $scopeManager = new ScopeManager(new DDScopeManager());
        $this->assertNull($scopeManager->getActive());
    }

    public function testActivateSuccess()
    {
        $tracer = Tracer::make(new NoopTransport());
        $span = $tracer->startSpan(self::OPERATION_NAME);
        $scopeManager = new ScopeManager(new DDScopeManager());
        $scopeManager->activate($span, false);
        $this->assertSame(
            $span->unwrapped(),
            $scopeManager->getActive()->getSpan()->unwrapped()
        );
    }

    public function testGetScopeReturnsNull()
    {
        $tracer = Tracer::make(new NoopTransport());
        $tracer->startSpan(self::OPERATION_NAME);
        $this->assertNull($tracer->getScopeManager()->getActive());
    }

    public function testGetScopeSuccess()
    {
        $tracer = Tracer::make(new NoopTransport());
        $span = $tracer->startSpan(self::OPERATION_NAME);
        $scope = $tracer->getScopeManager()->activate($span, false);
        $this->assertSame(
            $scope->unwrapped(),
            $tracer->getScopeManager()->getActive()->unwrapped()
        );
    }
}
