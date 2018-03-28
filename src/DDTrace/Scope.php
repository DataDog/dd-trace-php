<?php

namespace DDTrace;

use OpenTracing\Scope as OpenTracingScope;

final class Scope implements OpenTracingScope
{
    /**
     * @var Span
     */
    private $span;

    /**
     * @var ScopeManager
     */
    private $scopeManager;

    public function __construct(ScopeManager $scopeManager, Span $span)
    {
        $this->scopeManager = $scopeManager;
        $this->span = $span;
    }

    /**
     * {@inheritdoc}
     */
    public function close()
    {
        $this->scopeManager->deactivate($this);
    }

    /**
     * {@inheritdoc}
     */
    public function getSpan()
    {
        return $this->span;
    }
}
