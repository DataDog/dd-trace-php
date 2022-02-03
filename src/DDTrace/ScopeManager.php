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
     * @var SpanContext
     */
    private $rootContext;

    public function __construct(SpanContext $rootContext = null)
    {
        $this->rootContext = $rootContext;
    }

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

        $this->scopes[count($this->scopes)] = $scope;

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
        $this->reconcileInternalAndUserland();
        for ($i = count($this->scopes) - 1; $i >= 0; --$i) {
            $scope = $this->scopes[$i];
            $span = $scope->getSpan();
            if (!($span instanceof Span) || !isset($span->ddtrace_scope_activated) || $span->ddtrace_scope_activated) {
                return $scope;
            }
        }
        return null;
    }

    public function deactivate(Scope $scope)
    {
        $i = array_search($scope, $this->scopes, true);

        if (false === $i) {
            return;
        }

        array_splice($this->scopes, $i, 1);

        $span = $scope->getSpan();
        if ($span instanceof Span && isset($span->ddtrace_scope_activated)) {
            $span->ddtrace_scope_activated = false;
        }
    }

    /** @internal */
    public function getPrimaryRoot()
    {
        $this->reconcileInternalAndUserland();
        return reset($this->scopes) ?: null;
    }

    /** @internal */
    public function getTopScope()
    {
        return $this->reconcileInternalAndUserland();
    }

    /** @return Scope|null the current top scope */
    private function reconcileInternalAndUserland()
    {
        $topScope = null; // prevent false positive from phpstan

        for ($i = count($this->scopes) - 1; $i >= 0; --$i) {
            $topScope = $this->scopes[$i];
            if ($topScope->getSpan()->isFinished()) {
                unset($this->scopes[$i]);
            } else {
                break;
            }
        }

        if (empty($this->scopes)) {
            if ($internalRootSpan = root_span()) {
                $traceId = trace_id();
                if ($this->rootContext) {
                    $parentId = $this->rootContext->spanId;
                } else {
                    $parentId = null;
                }
                $context = new SpanContext($traceId, $internalRootSpan->id, $parentId, []);
                $context->parentContext = $this->rootContext;
                $topScope = $this->scopes[0] = new Scope($this, new Span($internalRootSpan, $context), false);
            } else {
                return null;
            }
        }

        $currentSpanId = $topScope->getSpan()->getSpanId();
        $newScopes = [];
        for ($span = active_span(); $span->id != $currentSpanId; $span = $span->parent) {
            $scope = new Scope($this, new Span($span, new SpanContext(trace_id(), $span->id, $span->parent->id)), true);
            $newScopes[] = $scope;
        }
        foreach (array_reverse($newScopes) as $scope) {
            // it's a DDTrace\SpanContext in any case, but phpstan doesn't know this
            // @phpstan-ignore-next-line
            $scope->getSpan()->getContext()->parentContext = end($this->scopes)->getSpan()->getContext();
            $this->scopes[count($this->scopes)] = $scope;
        }

        return $newScopes ? $newScopes[0] : $topScope;
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
