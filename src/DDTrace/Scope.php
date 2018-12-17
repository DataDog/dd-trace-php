<?php

namespace DDTrace;

use DDTrace\OpenTracing\Scope as OpenTracingScope;
use DDTrace\OpenTracing\Span as OpenTracingSpan;

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

    /**
     * @var bool
     */
    private $finishSpanOnClose;

    public function __construct(ScopeManager $scopeManager, OpenTracingSpan $span, $finishSpanOnClose)
    {
        $this->scopeManager = $scopeManager;
        $this->span = $span;
        $this->finishSpanOnClose = $finishSpanOnClose;
    }

    /**
     * {@inheritdoc}
     */
    public function close()
    {
        if ($this->finishSpanOnClose) {
            $this->span->finish();
        }

        $this->scopeManager->deactivate($this);
    }

    /**
     * {@inheritdoc}
     *
     * @return Span
     */
    public function getSpan()
    {
        return $this->span;
    }
}
