<?php

namespace DDTrace\OpenTracer;

use DDTrace\Contracts\ScopeManager as ScopeManagerInterface;
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
    public function activate(OTSpan $span, $finishSpanOnClose = true)
    {
        return $this->scopeManager->activate(
            $span instanceof Span ?
                $span->unwrapped()
                : Span::toDDSpan($span),
            $finishSpanOnClose
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getActive()
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
