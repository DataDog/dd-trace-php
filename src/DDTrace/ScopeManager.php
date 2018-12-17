<?php

namespace DDTrace;

use DDTrace\OpenTracing\Scope as OpenTracingScope;
use DDTrace\OpenTracing\ScopeManager as OpenTracingScopeManager;
use DDTrace\OpenTracing\Span as OpenTracingSpan;

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
    public function activate(OpenTracingSpan $span, $finishSpanOnClose = self::DEFAULT_FINISH_SPAN_ON_CLOSE)
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
        $i = array_search($scope, $this->scopes, true);

        if (false === $i) {
            return;
        }

        array_splice($this->scopes, $i, 1);
    }
}
