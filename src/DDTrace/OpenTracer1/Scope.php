<?php

namespace DDTrace\OpenTracer;

use DDTrace\Contracts\Scope as ScopeInterface;
use OpenTracing\Scope as OTScope;
use OpenTracing\Span as OTSpan;

final class Scope implements OTScope
{
    /**
     * @var ScopeInterface
     */
    private $scope;

    /**
     * @var Span
     */
    private $span;

    /**
     * @param ScopeInterface $scope
     */
    public function __construct(ScopeInterface $scope)
    {
        $this->scope = $scope;
    }

    /**
     * {@inheritdoc}
     */
    public function close(): void
    {
        $this->scope->close();
    }

    /**
     * {@inheritdoc}
     */
    public function getSpan(): OTSpan
    {
        if (isset($this->span)) {
            return $this->span;
        }
        return $this->span = new Span($this->scope->getSpan());
    }

    /**
     * @return ScopeInterface
     */
    public function unwrapped()
    {
        return $this->scope;
    }
}
