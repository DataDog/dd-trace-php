<?php

namespace DDTrace\OpenTracer1;

use DDTrace\Contracts\ScopeManager as ScopeManagerInterface;
use OpenTracing\Scope as OTScope;
use OpenTracing\ScopeManager as OTScopeManager;
use OpenTracing\Span as OTSpan;

final class ScopeManager implements OTScopeManager
{
    /**
     * @var ScopeManagerInterface
     */
    private $scopeManager;

    /**
     * @param ScopeManagerInterface $scopeManager
     */
    public function __construct(ScopeManagerInterface $scopeManager)
    {
        $this->scopeManager = $scopeManager;
    }

    /**
     * {@inheritdoc}
     */
    public function activate(OTSpan $span, bool $finishSpanOnClose = true): OTScope
    {
        $scope = $this->scopeManager->activate(
            $span instanceof Span ?
                $span->unwrapped()
                : Span::toDDSpan($span),
            $finishSpanOnClose
        );
        return new Scope($scope);
    }

    /**
     * {@inheritdoc}
     */
    public function getActive(): ?OTScope
    {
        $activeScope = $this->scopeManager->getActive();
        if (null === $activeScope) {
            return null;
        }
        return new Scope($activeScope);
    }

    /**
     * @return ScopeManagerInterface
     */
    public function unwrapped()
    {
        return $this->scopeManager;
    }
}
