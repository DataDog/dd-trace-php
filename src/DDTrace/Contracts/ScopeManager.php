<?php

/**
 * Ported from opentracing/opentracing
 * @see https://github.com/opentracing/opentracing-php/blob/master/src/OpenTracing/ScopeManager.php
 */

namespace DDTrace\Contracts;

/**
 * Keeps track of the current active `Span`.
 */
interface ScopeManager
{
    const DEFAULT_FINISH_SPAN_ON_CLOSE = true;

    /**
     * Activates an `Span`, so that it is used as a parent when creating new spans.
     * The implementation must keep track of the active spans sequence, so
     * that previous spans can be resumed after a deactivation.
     *
     * @param Span $span the {@link Span} that should become the {@link #active()}
     * @param bool $finishSpanOnClose whether span should automatically be finished
     * when {@link Scope#close()} is called. Its default value is true.
     *
     * @return Scope instance to control the end of the active period for the {@link Span}. It is a
     * programming error to neglect to call {@link Scope#close()} on the returned instance.
     */
    public function activate(Span $span, $finishSpanOnClose = self::DEFAULT_FINISH_SPAN_ON_CLOSE);

    /**
     * Return the currently active {@link Scope} which can be used to access the
     * currently active {@link Scope#getSpan()}.
     *
     * If there is an {@link Scope non-null scope}, its wrapped {@link Span} becomes an implicit parent
     * (as {@link References#CHILD_OF} reference) of any
     * newly-created {@link Span} at {@link Tracer.SpanBuilder#startActive(boolean)} or {@link SpanBuilder#start()}
     * time rather than at {@link Tracer#buildSpan(String)} time.
     *
     * @return Scope|null
     */
    public function getActive();

    /**
     * Closes all the current request root spans. Typically there only will be one.
     */
    public function close();
}
