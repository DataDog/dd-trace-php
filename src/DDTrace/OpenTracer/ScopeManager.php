<?php

namespace DDTrace\OpenTracer;

use DDTrace\Contracts\ScopeManager as ScopeManagerInterface;
use DDTrace\Contracts\Span as SpanInterface;
use OpenTracing\ScopeManager as OpenTracingScopeManager;

final class ScopeManager implements ScopeManagerInterface
{
    /**
     * @var OpenTracingScopeManager
     */
    private $scopeManager;

    /**
     * @param OpenTracingScopeManager $scopeManager
     */
    public function __construct(OpenTracingScopeManager $scopeManager)
    {
        $this->scopeManager = $scopeManager;
    }

    /**
     * {@inheritdoc}
     */
    public function activate(SpanInterface $span, $finishSpanOnClose = self::DEFAULT_FINISH_SPAN_ON_CLOSE)
    {
        // @TODO Wrap this or implement here
        return $this->scopeManager->activate($span, $finishSpanOnClose);
    }

    /**
     * {@inheritdoc}
     */
    public function getActive()
    {
        return $this->scopeManager->getActive();
    }
}
