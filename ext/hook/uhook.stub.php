<?php

/** @generate-class-entries */

namespace DDTrace;

class HookData {
    public mixed $data;
    public int $id;
    public array $args;
    public mixed $returned;
    public ?\Throwable $exception;
    /**
     * Creates a span if none exists yet, otherwise returns the span attached to the current function call.
     * If called outside the pre-hook and no span is attached yet, it will also return null.
     *
     * @param SpanStack|SpanData|null $parent May be specified to start a span on a specific stack.
     *                                        As an example, when instrumenting closures, it might conceptually make
     *                                        sense to attach the Closure to the current executing function instead of
     *                                        where it ends up called. In that case the initial call to span() needs to
     *                                        provide the proper stack.
     */
    public function span(SpanStack|SpanData|null $parent = null): SpanData;

    /**
     * Replaces the arguments of a function call. Must be called within a pre-hook.
     * It is not allowed to pass more arguments to a function that currently on the stack or total number or arguments,
     * whichever is greater.
     *
     * @param array $arguments An array of arguments, which will replace the hooked functions arguments.
     */
    public function overrideArguments(array $arguments): void;
}

function install_hook(string|\Closure|\Generator $target, ?\Closure $begin = null, ?\Closure $end = null): int {}
function remove_hook(int $id): void {}
