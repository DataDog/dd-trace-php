<?php

/** @generate-class-entries */

namespace DDTrace;

class HookData {
    /**
     * Arbitrary data to be passed from a begin-hook to an end-hook
     */
    public mixed $data;

    /**
     * The hook id, which triggered that particular hook
     */
    public int $id;

    /**
     * A zero-indexed array of all arguments.
     * On file inclusions, this will be an array with a single element containing the filename.
     */
    public array $args;

    /**
     * The returned value.
     * Uninitialized in a begin hook.
     */
    public mixed $returned;

    /**
     * The a possible thrown exception within that function.
     * Uninitialized in a begin hook.
     */
    public ?\Throwable $exception;

    /**
     * Creates a span if none exists yet, otherwise returns the span attached to the current function call.
     * If called outside the pre-hook and no span is attached yet, it will return an empty span object.
     *
     * @param SpanStack|SpanData|null $parent May be specified to start a span on a specific stack.
     *                                        As an example, when instrumenting closures, it might conceptually make
     *                                        sense to attach the Closure to the current executing function instead of
     *                                        where it ends up called. In that case the initial call to span() needs to
     *                                        provide the proper stack.
     * @return SpanData The new or existing span.
     */
    public function span(SpanStack|SpanData|null $parent = null): SpanData;

    /**
     * Works similarly to self::spqn(), but always pushes the span onto the active span stack, even if running in
     * limited mode.
     *
     * @param SpanStack|SpanData|null $parent See self::span().
     * @return SpanData The new or existing span.
     */
    public function unlimitedSpan(SpanStack|SpanData|null $parent = null): SpanData;

    /**
     * Replaces the arguments of a function call. Must be called within a pre-hook.
     * It is not allowed to pass more arguments to a function that currently on the stack or total number or arguments,
     * whichever is greater.
     *
     * @param array $arguments An array of arguments, which will replace the hooked functions arguments.
     * @return bool 'true' on success, otherwise 'false'
     */
    public function overrideArguments(array $arguments): bool;
}

/**
 * @var string
 */
const HOOK_ALL_FILES = "";

/**
 * Only hooks the specific instance of the Closure, i.e. independent instantiations of the same Closure are not hooked.
 *
 * @var int
 * @cvalue HOOK_INSTANCE
 */
const HOOK_INSTANCE = UNKNOWN;

/**
 * @param string|\Closure|\Generator $target The function to hook.
 *                                           If a string is passed, it must be either a function name or referencing
 *                                           a method in "Classname::methodname" format. Alternatively it may be a file
 *                                           name or the DDTrace\HOOK_ALL_FILES constant. Can be a relative path
 *                                           starting with ./ or ../ too.
 *                                           If a Closure is passed, the hook only applies to the current instance
 *                                           of that Closure.
 *                                           If a Generator is passed, the active function name or Closure is extracted
 *                                           and the hook applied to that.
 * @param null|\Closure(\DDTrace\HookData) $begin Called before the hooked function is invoked.
 * @param null|\Closure(\DDTrace\HookData) $end Called after the hooked function is invoked.
 * @param int $flags The only accepted flag currently is DDTrace\HOOK_INSTANCE.
 * @return int An integer which can be used to remove a hook via DDTrace\remove_hook.
 */
function install_hook(
    string|callable|\Closure|\Generator $target,
    ?\Closure $begin = null,
    ?\Closure $end = null,
    int $flags = 0
): int {}

/**
 * Removes an installed hook by its id, as returned by install_hook or HookData->id.
 *
 * @param int $id The id to remove.
 */
function remove_hook(int $id): void {}
