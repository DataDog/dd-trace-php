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
    public function activate(OpenTracingSpan $span, $finishSpanOnClose)
    {
        $scope = new Scope($this, $span, $finishSpanOnClose);
        $this->scopes[] = $scope;
        return $scope;
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

    public function deactivate(Scope $scope)
    {
        $scopeLength = count($this->scopes);

        for ($i = 0; $i < $scopeLength; $i++) {
            if ($scope === $this->scopes[$i]) {
                array_splice($this->scopes, $i, 1);
            }
        }
    }
}
