<?php

namespace DDTrace\Tests\Unit;

use DDTrace\ScopeManager;
use DDTrace\Tracer;
use DDTrace\Transport\Noop as NoopTransport;

final class ScopeManagerTest extends BaseTestCase
{
    const OPERATION_NAME = 'test_name';

    public function testGetActiveFailsWithNoActiveSpans()
    {
        $scopeManager = new ScopeManager();
        $this->assertNull($scopeManager->getActive());
    }

    public function testActivateSuccess()
    {
        $tracer = new Tracer(new NoopTransport());
        $span = $tracer->startSpan(self::OPERATION_NAME);
        $scopeManager = new ScopeManager();
        $scopeManager->activate($span, false);
        $this->assertSame($span, $scopeManager->getActive()->getSpan());
    }

    public function testGetScopeReturnsNull()
    {
        $tracer = new Tracer(new NoopTransport());
        $tracer->startSpan(self::OPERATION_NAME);
        $this->assertNull($tracer->getScopeManager()->getActive());
    }

    public function testGetScopeSuccess()
    {
        $tracer = new Tracer(new NoopTransport());
        $span = $tracer->startSpan(self::OPERATION_NAME);
        $scope = $tracer->getScopeManager()->activate($span, false);
        $this->assertSame($scope, $tracer->getScopeManager()->getActive());
    }

    public function testDeactivateSuccess()
    {
        $tracer = new Tracer(new NoopTransport());
        $span = $tracer->startSpan(self::OPERATION_NAME);
        $scopeManager = new ScopeManager();
        $scope = $scopeManager->activate($span, false);
        $scopeManager->deactivate($scope);
        $this->assertNull($scopeManager->getActive());
    }

    public function testCanManageMultipleScopes()
    {
        $tracer = new Tracer(new NoopTransport());
        $scopeManager = new ScopeManager();

        $scope = $scopeManager->activate($tracer->startSpan(self::OPERATION_NAME), false);
        $scopeManager->deactivate($scope);

        $scope = $scopeManager->activate($tracer->startSpan(self::OPERATION_NAME), false);
        $scopeManager->deactivate($scope);


        $this->assertNull($scopeManager->getActive());
    }
}
