<?php

/** @generate-class-entries */

namespace DDTrace {

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
         * The returned value. This may be a reference, if the value was returned by reference.
         * Uninitialized in a begin hook.
         */
        public mixed $returned;

        /**
         * The possible thrown exception within that function.
         * Uninitialized in a begin hook.
         */
        public ?\Throwable $exception;

        /**
         * The object instance, if called on an object.
         * Uninitialized if there is no object.
         */
        public object $instance;

        /**
         * Creates a span if none exists yet, otherwise returns the span attached to the current function call.
         * If called outside the pre-hook and no span is attached yet, it will return an empty span object.
         *
         * @param SpanStack|SpanData|null $parent May be specified to start a span on a specific stack.
         *                                        As an example, when instrumenting closures, it might conceptually make sense to attach the Closure to the current executing function instead of where it ends up called.
         *                                        In that case the initial call to span() needs to provide the proper stack.
         * @return SpanData The new or existing span.
         */
        public function span(SpanStack|SpanData|null $parent = null): SpanData {}

        /**
         * Works similarly to self::spqn(), but always pushes the span onto the active span stack, even if running in limited mode.
         *
         * @param SpanStack|SpanData|null $parent See self::span().
         * @return SpanData The new or existing span.
         */
        public function unlimitedSpan(SpanStack|SpanData|null $parent = null): SpanData {}

        /**
         * Replaces the arguments of a function call. Must be called within a pre-hook.
         * It is not allowed to pass more arguments to a function that currently on the stack or total number or arguments, whichever is greater.
         *
         * @param array $arguments An array of arguments, which will replace the hooked functions arguments.
         * @return bool 'true' on success, otherwise 'false'
         */
        public function overrideArguments(array $arguments): bool {}

        /**
         * Replaces the return value of a function call. Must be called within a post-hook.
         * Note that the return value is not checked.
         *
         * Generator-creation pre-hooks may override the Generator returned to the caller.
         * The original stays alive and cannot be resumed during the callback.
         * If replaced, the paired post-hook runs immediately with a null return value.
         *
         * @prefer-ref $value
         * @param mixed $value A value which will replace the original return value.
         * @return bool 'true' on success, otherwise 'false'
         */
        public function overrideReturnValue(mixed $value): bool {}

        /**
         * Replaces the exception thrown by a function call. Must be called within a post-hook.
         *
         * @param \Throwable|null $exception An exception which will replace the original exception.
         * @return bool 'true' on success, otherwise 'false'
         */
        public function overrideException(\Throwable|null $exception): bool {}

        /**
         * Disables inlining of this method.
         * @return bool true iif we have a user function
         */
        public function disableJitInlining(): bool {}

        /**
         * Suppresses the call to the hooked function. Must be called within a pre-hook.
         * The method disableJitInlining() should be called unconditionally in hooks using this method.
         * @return bool always 'true'
         */
        public function suppressCall(): bool {}

        /**
         * By default, hooks are not called if the hooked function is called from the hook.
         * This method can be used to override this behavior. The next recursive call will trigger the hook.
         * @return bool 'true' if called from the hook, which should always be the case
         */
        public function allowNestedHook(): bool {}

        /**
         * The name of the file where the function/method call was made from.
         *
         * @return string The file name, or an empty string if the file name is not available.
         */
        public function getSourceFile(): string {}
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
     * Runs callbacks at the start and/or end of a file, function, or method.
     *
     * End-only hooks on named functions or methods also join matching calls already on the stack, except calls currently running end hooks.
     * Their arguments reflect the parameters' current values.
     *
     * @param string|\Closure|\Generator $target The function to hook.
     *                                           If a string is passed, it must be either a function name or referencing a method in "Classname::methodname" format.
     *                                           Alternatively it may be a file name or the DDTrace\HOOK_ALL_FILES constant.
     *                                           Can be a relative path starting with ./ or ../ too.
     *                                           If a Closure is passed, the hook only applies to the current instance of that Closure.
     *                                           If a Generator is passed, the active function name or Closure is extracted and the hook applied to that.
     * @param null|\Closure(\DDTrace\HookData) $begin Called before the hooked function is invoked.
     * @param null|\Closure(\DDTrace\HookData) $end Called after the hooked function returns or otherwise exits.
     * @param int $flags The only accepted flag currently is DDTrace\HOOK_INSTANCE.
     * @return int An integer which can be used to remove a hook via DDTrace\remove_hook.
     */
    function install_hook(
        string|callable|\Closure|\Generator $target,
        ?\Closure                           $begin = null,
        ?\Closure                           $end = null,
        int                                 $flags = 0
    ): int {}

    class LineHookData {
        /**
         * Data shared between the begin and end callbacks.
         */
        public mixed $data;

        /**
         * The negative id returned by install_line_hook().
         */
        public int $id;

        /**
         * The file of the instrumented frame.
         */
        public string $file;

        /**
         * The line reached. Lines without instructions resolve forward to the next executable line.
         */
        public int $line;

        /**
         * Creates a span for this execution of the line or range, or returns its existing span.
         * Create it in the begin callback; it closes automatically when the range exits, even without an end callback.
         * Returning false from the end callback drops the span.
         * If first called elsewhere, while tracing is disabled, or at the trace limit, returns an empty span.
         * A parent selects the span stack, as with HookData::span().
         * Generators use a child stack so their spans detach while yielded.
         */
        public function span(SpanStack|SpanData|null $parent = null): SpanData {}

        /**
         * Like span(), but bypasses the trace limit.
         */
        public function unlimitedSpan(SpanStack|SpanData|null $parent = null): SpanData {}

        /**
         * The value of a variable in the instrumented frame, or null if it is not defined at this point.
         *
         * @param string $name Variable name, without the leading dollar.
         * @return mixed
         */
        public function var(string $name): mixed {}

        /**
         * All variables defined in the instrumented frame at this point, as name => value.
         *
         * @return array
         */
        public function vars(): array {}
    }

    /**
     * Hooks a source line or line range.
     *
     * A range must reach its begin site before its end callback can run, including end-only ranges.
     * Installing a range does not open it for executions already inside it.
     * Overlap validation also applies to begin-only hooks.
     * Ranges sharing a begin site run their begin callbacks from outermost to innermost.
     *
     * Runtime limits, logged as warnings when detected:
     *  - Hooks may not fire in code already compiled by the JIT. Before compilation, tracing and compile-on-first-execution are supported; hot-counter and profiling triggers are not.
     *    Use opcache.jit=tracing.
     *  - Functions whose constant return is inlined by OPcache cannot be hooked at the inlined call sites.
     *  - If the shared arm table is full, instrumented sites remain armed for the life of the process pool.
     *
     * OPcache optimization can make source paths indistinguishable, including some finally entries and terminal jumps. Source-line mapping is best-effort in these cases.
     *
     * @param string $file File to hook, suffix-matched like install_hook's file targets.
     * @param int $line Line to hook. Slides forward to the next line that compiled to an instruction.
     * @param null|\Closure(\DDTrace\LineHookData) $begin Called before the resolved start line executes.
     * @param int|null $endLine Last line in the range; defaults to $line. A range extending beyond its function ends at function exit.
     * @param null|\Closure(\DDTrace\LineHookData) $end Called after the end line executes, or when control leaves the range.
     * @return int A negative id for DDTrace\remove_hook(); function-hook ids are positive. Zero is never valid.
     * @throws \Error If $endLine is before $line, if the range partially overlaps an already installed range in the same file, or if this PHP build's VM dispatch cannot be instrumented.
     */
    function install_line_hook(
        string    $file,
        int       $line,
        ?\Closure $begin = null,
        ?int      $endLine = null,
        ?\Closure $end = null
    ): int {}

    /**
     * Removes a hook using the id from install_hook(), install_line_hook(), or HookData::$id.
     *
     * New begin callbacks stop immediately.
     * Calls and ranges already in progress retain their end callbacks at the normal closing point.
     * Closures are released after the last such invocation ends; line sites remain armed until then.
     *
     * @param int $id The id to remove.
     * @param string $location A class name (which inherits this hook through inheritance), which to specifically remove this hook from.
     * @return void no return, not formally declared void because of a buggy debug assertion in PHP 7.1 ("return value must be of type void, null returned")
     */
    function remove_hook(int $id, string $location = "") {}
}
