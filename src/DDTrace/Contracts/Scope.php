<?php

/**
 * Ported from opentracing/opentracing
 * @see https://github.com/opentracing/opentracing-php/blob/master/src/OpenTracing/Scope.php
 */

namespace DDTrace\Contracts;

/**
 * A {@link Scope} formalizes the activation and deactivation of a {@link Span}, usually from a CPU standpoint.
 *
 * Many times a {@link Span} will be extant (in that {@link Span#finish()} has not been called) despite being in a
 * non-runnable state from a CPU/scheduler standpoint. For instance, a {@link Span} representing the client side of an
 * RPC will be unfinished but blocked on IO while the RPC is still outstanding. A {@link Scope} defines when a given
 * {@link Span} <em>is</em> scheduled and on the path.
 */
interface Scope
{
    /**
     * Mark the end of the active period for the current thread and {@link Scope},
     * updating the {@link ScopeManager#active()} in the process.
     *
     * NOTE: Calling {@link #close} more than once on a single {@link Scope} instance leads to undefined
     * behavior.
     */
    public function close();

    /**
     * @return Span the {@link Span} that's been scoped by this {@link Scope}
     */
    public function getSpan();
}
