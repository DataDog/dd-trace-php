<?php

namespace DDTrace\OpenTracer;

use DDTrace\Contracts\Scope as ScopeInterface;
use OpenTracing\Scope as OpenTracingScope;

final class Scope implements ScopeInterface
{
    /**
     * @var OpenTracingScope
     */
    private $scope;

    /**
     * @var Span
     */
    private $span;

    /**
     * @param OpenTracingScope $scope
     */
    public function __construct(OpenTracingScope $scope)
    {
        $this->scope = $scope;
    }

    /**
     * {@inheritdoc}
     */
    public function close()
    {
        $this->scope->close();
    }

    /**
     * {@inheritdoc}
     */
    public function getSpan()
    {
        if (isset($this->span)) {
            return $this->span;
        }
        return $this->span = new Span($this->scope->getSpan());
    }
}
