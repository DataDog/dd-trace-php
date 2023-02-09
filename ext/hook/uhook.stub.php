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
     *
     * @param SpanStack|SpanData|null $parent May be specified to start a span on a specific stack.
     *                                        As an example, when instrumenting closures, it might conceptually make
     *                                        sense to attach the Closure to the current executing function instead of
     *                                        where it ends up called. In that case the initial call to span() needs to
     *                                        provide the proper stack.
     */
    public function span(SpanStack|SpanData|null $parent = null): SpanData;
}

function install_hook(string|\Closure|\Generator $target, ?\Closure $begin, ?\Closure $end): int {}
function remove_hook(int $id): void {}
