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
     * {@inheritdoc}
     * @param Span|SpanInterface $span
     */
    public function activate(SpanInterface $span, $finishSpanOnClose = self::DEFAULT_FINISH_SPAN_ON_CLOSE)
    {
        $scope = new Scope($this, $span, $finishSpanOnClose);

        if ($span instanceof Span && isset($span->ddtrace_scope_activated)) {
            $span->ddtrace_scope_activated = true;
        }

        $this->scopes[$span->getContext()->getSpanId()] = $scope;

        return $scope;
    }

    /**
     * {@inheritdoc}
     */
    public function getActive()
    {
        $span = active_span();

        // No active span ==> no active scope
        if ($span === null) {
            return null;
        }

        // Active span is either from legacy or has already been wrapped from internal
        $spanId = $span->id;
        if (\array_key_exists($spanId, $this->scopes)) {
            return $this->scopes[$spanId];
        }

        // This is the first time a scope is returned for the currently active span. We keep track of it to return
        // the same object for multiple calls.
        return $this->scopes[$spanId] = $this->newScopeForInternalSpan($span);
    }

    private function newScopeForInternalSpan($span)
    {
        // ... generate a scope from the internal span ...
        return null; // actually this will be a Scope()
    }

    public function deactivate(Scope $scope)
    {
        $span = $scope->getSpan();
        if ($span === null) {
            return;
        }

        $spanId = $span->getContext()->getSpanId();
        if (!\array_key_exists($spanId, $this->scopes)) {
            return;
        }

        if ($span instanceof Span && isset($span->ddtrace_scope_activated)) {
            $span->ddtrace_scope_activated = false;
        }

        unset($this->scopes[$spanId]);
    }

    public function getPrimaryRoot()
    {
        $rootSpan = root_span();

        if ($rootSpan === null) {
            return null;
        }

        $rootSpanId = $rootSpan->id;
        if (\array_key_exists($rootSpanId, $this->scopes)) {
            return $this->scopes[$rootSpanId];
        }

        return $this->scopes[$rootSpanId] = $this->newScopeForInternalSpan($rootSpan);
    }

    /**
     * Closes all the current request root spans. Typically there only will be one.
     */
    public function close()
    {
        // What is the purpose of this method is unclear
    }
}
