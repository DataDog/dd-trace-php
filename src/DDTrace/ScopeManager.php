<?php

namespace DDTrace;

use DDTrace\Contracts\Scope as ScopeInterface;
use DDTrace\Contracts\ScopeManager as ScopeManagerInterface;
use DDTrace\Contracts\Span as SpanInterface;

final class ScopeManager implements ScopeManagerInterface
{
    /**
     * @var array|ScopeInterface[]
     */
    private $scopes = [];

    /**
     * Represents the current request root span. In case of distributed tracing, this represents the request root span.
     *
     * @var ScopeInterface[]
     */
    private $hostRootScopes = [];

    /**
     * {@inheritdoc}
     * @param Span|SpanInterface $span
     */
    public function activate(SpanInterface $span, $finishSpanOnClose = self::DEFAULT_FINISH_SPAN_ON_CLOSE)
    {
        $scope = new Scope($this, $span, $finishSpanOnClose);
        $this->scopes[] = $scope;

        if ($span->getContext()->isHostRoot()) {
            $this->hostRootScopes[] = $scope;
        }

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

    /**
     * Closes all the current request root spans. Typically there only will be one.
     */
    public function close()
    {
        foreach ($this->hostRootScopes as $scope) {
            $scope->close();
        }
    }
}
