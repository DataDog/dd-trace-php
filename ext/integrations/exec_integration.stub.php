<?php

namespace DDTrace\Integrations\Exec {

    /**
     * Associates a popen() stream resource with a span so that when the resource is destroyed, the span is updated
     * with the exit code and closed.
     *
     * It is not validated that the stream is actually a popen() stream resource.
     *
     * Only one span can be associated with a stream resource. A subsequent call replaces the first span.
     *
     * @internal for use by the exec integration only
     * @param resource $stream A stream resource
     * @param \DDTrace\SpanData $span The command_execution span to associate
     * @return bool true on success or false if the resource is not a stream
     */
    function register_stream($stream, \DDTrace\SpanData $span): bool {}

    /**
     * Associates a proc_open() process handle with a span so that when the handle is closed, there is a waitpid()
     * call on the child, and the span is updated with the exit code and closed.
     *
     * Only one call to this function can be made per process handle. A subsequent call has undefined behavior.
     *
     * @internal for use by the exec integration only
     * @param resource $proc_h A process handle
     * @param \DDTrace\SpanData $span The command_execution span to associate
     * @return bool true on success or false if the resource is not a process handle
     */
    function proc_assoc_span($proc_h, \DDTrace\SpanData $span) : bool {}

    /**
     * Retrieves a span previously associated with a process handle by proc_assoc_span(), or
     * null if there's none.
     *
     * @internal for use by the exec integration only
     * @param resource $proc_h A process handle
     * @return \DDTrace\SpanData|null The associated span, if any.
     */
    function proc_get_span($proc_h) : ?\DDTrace\SpanData {}

    /**
     * Retrieves the pid associated with a process handle.
     *
     * @internal for use by the testes of the exec integration only
     * @param resource $proc_h A process handle
     * @return int|null The associated span, if any.
     */
    function proc_get_pid($proc_h) : ?int {}

    /**
     * Closes the spans associated with live resources opened by popen() and proc_open()
     *
     * @internal for use by the testes of the exec integration only
     * @return bool
     */
    function test_rshutdown() : bool {}
}

