<?php

namespace DDTrace;

use OpenTracing\Scope as OpenTracingScope;
use OpenTracing\ScopeManager as OpenTracingScopeManager;
use OpenTracing\Span as OpenTracingSpan;

final class ScopeManager implements OpenTracingScopeManager
{
    /**
     * @var array|OpenTracingScope[]
     */
    private $scopes = [];

    /**
     * {@inheritdoc}
     * @param Span|OpenTracingSpan $span
     */
    public function activate(OpenTracingSpan $span)
    {
        $this->scopes[] = new Scope($this, $span);
    }

    /**
     * {@inheritdoc}
     */
    public function getActive()
    {
        if (empty($this->scopes)) {
            return null;
        }

        return $this->scopes[count($this->scopes) - 1];
    }

    /**
     * {@inheritdoc}
     * @param Span $span
     */
    public function getScope(OpenTracingSpan $span)
    {
        $scopeLength = count($this->scopes);

        for ($i = 0; $i < $scopeLength; $i++) {
            if ($span === $this->scopes[$i]->getSpan()) {
                return $this->scopes[$i];
            }
        }

        return null;
    }

    public function deactivate(Scope $scope)
    {
        $scopeLength = count($this->scopes);

        for ($i = 0; $i < $scopeLength; $i++) {
            if ($scope === $this->scopes[$i]) {
                unset($this->scopes[$i]);
            }
        }
    }
}
