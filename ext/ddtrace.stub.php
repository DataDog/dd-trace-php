<?php

/** @generate-class-entries */

// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses

namespace DDTrace {
    /**
     * @var int
     * @cvalue DD_TRACE_DBM_PROPAGATION_DISABLED
     */
    const DBM_PROPAGATION_DISABLED = UNKNOWN;

    /**
     * @var int
     * @cvalue DD_TRACE_DBM_PROPAGATION_SERVICE
     */
    const DBM_PROPAGATION_SERVICE = UNKNOWN;

    /**
     * @var int
     * @cvalue DD_TRACE_DBM_PROPAGATION_FULL
     */
    const DBM_PROPAGATION_FULL = UNKNOWN;

    class SpanData {
        /**
         * @var string|null The span name
         */
        public string|null $name = "";

        /**
         * @var string|null The resource you are tracing
         */
        public string|null $resource = "";

        /**
         * @var string|null The service you are tracing. Defaults to active service at the time of span creation (i.e.,
         * the parent span), or datadog.service initialization settings if no parent exists
         */
        public string|null $service = "";

        /**
         * @var string|null The type of request which can be set to: web, db, cache, or custom (Optional). Inherited
         * from parent.
         */
        public string|null $type = "";

        /**
         * @var string[] $meta An array of key-value span metadata; keys and values must be strings.
         */
        public array $meta = [];

        /**
         * @var float[] $metrics An array of key-value span metrics; keys must be strings and values must be floats.
         */
        public array $metrics = [];

        /**
         * @var \Throwable|null $exception An exception generated during the execution of the original function, if any.
         */
        public \Throwable|null $exception = null;

        /**
         * @var string The unique identifier of the span
         */
        public readonly string $id = "";

        /**
         * @var SpanData|null The parent span, or 'null' if there is none
         */
        public readonly SpanData|null $parent = null;

        /**
         * @var SpanStack The span's stack trace
         */
        public readonly SpanStack $stack;

        /**
         * @return int Get the current span duration
         */
        public function getDuration(): int {}

        /**
         * @return int Get the start time of the span
         */
        public function getStartTime(): int {}
    }

    /**
     * The SpanStack class allows context transfer between spans.
     *
     * When a new span is created, it will always be on the top the of active stack, becoming the new active span. This
     * new span's stack property is assigned the stack of the previous active span (or a primary stack is initialized in
     * the case of a root span). The parent of a SpanStack is the active stack at the time of the creation of the new
     * stack.
     *
     * Each SpanStack has only one active span at a time, and can be manipulated using the 'create_stack' and
     * 'switch_stack' functions. 'create_stack' creates a new SpanStack whose parent is the currently active stack,
     * while 'switch_stack' switches the active span.
     */
    readonly class SpanStack {
        /**
         * @var SpanStack|null The parent stack, or 'null' if there is none
         */
        public readonly SpanStack|null $parent = null;

        /**
         * @var SpanData The active span
         */
        public SpanData|null $active = null;
    }

    // phpcs:disable Generic.Files.LineLength.TooLong

    /**
     * Instrument (trace) a specific method call. This function automatically handle the following tasks:
     * - Open a span before the code executes
     * - Set any errors from the instrumented call on the span
     * - Close the span when the instrumented call is done.
     *
     * Additional tags are set on the span from the closure (called a tracing closure).
     *
     * @param string $className The name of the class that contains the method.
     * @param string $methodName The name of the method to instrument.
     * @param \Closure(SpanData, array, mixed, \Exception|null):void|array{prehook?: \Closure, posthook?: \Closure, instrument_when_limited?: int, recurse?: bool} $tracingClosureOrConfigArray
     * The tracing closure is a function that adds extra tags to the span after the
     * instrumented call is executed. It accepts four parameters, namely, an instance of 'DDTrace\SpanData', an array of
     * arguments from the instrumented call, the return value of the instrumented call, and an instance of the exception
     * that was thrown in the instrumented call (if any), or 'null' if no exception was thrown.
     *
     * Alternatively, an associative configuration array can be given whose recognized keys are:
     * - 'prehook': a closure to be run prior to the method call
     * - 'posthook': a closure to be run after the method was executed
     * - 'instrument_when_limited': set to 1 shall the method be traced in limited mode (e.g., when span limit
     * exceeded)
     * - 'recurse': a boolean to state whether should recursive calls be traced as well
     * @return bool 'true' if the call was successfully instrumented, else 'false'
     */
    function trace_method(
        string $className,
        string $methodName,
        \Closure|array $tracingClosureOrConfigArray
    ): bool {}

    /**
     * Instrument (trace) a specific function call. This function automatically handle the following tasks:
     * - Open a span before the code executes
     * - Set any errors from the instrumented call on the span
     * - Close the span when the instrumented call is done.
     *
     * Additional tags are set on the span from the closure (called a tracing closure).
     *
     * @param string $functionName The name of the function to trace.
     * @param \Closure(SpanData, array, mixed, \Exception|null):void|array{prehook?: \Closure, posthook?: \Closure, instrument_when_limited?: int, recurse?: bool} $tracingClosureOrConfigArray
     * The tracing closure is a function that adds extra tags to the span after the
     * instrumented call is executed. It accepts four parameters, namely, an instance of 'DDTrace\SpanData' that writes
     * to the span properties, an array of arguments from the instrumented call, the return value of the instrumented
     * call, and an instance of the exception that was thrown in the instrumented call (if any), or 'null' if no
     * exception was thrown.
     *
     * Alternatively, an associative configuration array can be given whose recognized keys are:
     * - 'prehook': a closure to be run prior to the function call
     * - 'posthook': a closure to be run after the function was executed
     * - 'instrument_when_limited': set to 1 shall the function be traced in limited mode (e.g., when span limit
     * exceeded)
     * - 'recurse': a boolean to state whether should recursive calls be traced as well
     * @return bool 'true' if the call was successfully instrumented, else 'false'
     */
    function trace_function(string $functionName, \Closure|array $tracingClosureOrConfigArray): bool {}

    /**
     * This function allows to define pre- and post-hooks that will be executed before and after the function is called.
     *
     * @param string $functionName The name of the function to be instrumented.
     * @param \Closure(mixed $args):void|null|array{prehook?: \Closure, posthook?: \Closure, instrument_when_limited?: int, recurse?: bool} $prehookOrConfigArray
     * A pre-hook function that will be called before the instrumented function is
     * executed. This can be useful for things like asserting the function is passed the  correct arguments.
     *
     * Alternatively, an associative configuration array can be given whose recognized keys are:
     * - 'prehook': a closure to be run prior to the function call
     * - 'posthook': a closure to be run after the function was executed
     * - 'instrument_when_limited': set to 1 shall the function be traced in limited mode (e.g., when span limit
     * exceeded)
     * - 'recurse': a boolean to state whether should recursive calls be traced as well
     * @param \Closure(mixed $args, mixed $retval):void|null $posthook A post-hook function that will be called after
     * the instrumented function is executed. This can be useful for things like formatting output data or logging the
     * results of the function call.
     *
     * Note that the $posthook argument shouldn't be given when calling hook_function if a configuration array has been
     * passed.
     * @return bool 'false' if the hook could not be successfully installed, else 'true'
     */
    function hook_function(
        string $functionName,
        \Closure|array|null $prehookOrConfigArray = null,
        ?\Closure $posthook = null
    ): bool {}

    /**
     * This function allows to define pre- and post-hooks that will be executed before and after the method is called.
     *
     * @param string $className The name of the class that contains the method.
     * @param string $methodName The name of the method to instrument.
     * @param \Closure(mixed $args):void|null|array{prehook?: \Closure, posthook?: \Closure, instrument_when_limited?: int, recurse?: bool} $prehookOrConfigArray
     * A pre-hook function that will be called before the instrumented
     * method is executed. This can be useful for things like asserting the method is passed the correct arguments.
     *
     * Alternatively, an associative configuration array can be given whose recognized keys are:
     * - 'prehook': a closure to be run prior to the function call
     * - 'posthook': a closure to be run after the function was executed
     * - 'instrument_when_limited': set to 1 shall the function be traced in limited mode (e.g., when span limit
     * exceeded)
     * - 'recurse': a boolean to state whether should recursive calls be traced as well
     * @param \Closure(mixed $args, mixed $retval):void|null $posthook A post-hook function that will be called after
     * the instrumented method is executed. This can be useful for things like formatting output data or logging the
     * results of the method call.
     *
     * Note that the $posthook argument shouldn't be given when calling hook_function if a configuration array has been
     * passed.
     * @return bool 'false' when no hook is passed, else 'true'
     */
    function hook_method(
        string $className,
        string $methodName,
        \Closure|array|null $prehookOrConfigArray = null,
        ?\Closure $posthook = null
    ): bool {}

    // phpcs:enable Generic.Files.LineLength.TooLong

    /**
     * Add a tag to be automatically applied to every span that is created, if tracing is enabled.
     *
     * @param string $key Tag key
     * @param string $value Tag Value
     */
    function add_global_tag(string $key, string $value): void {}

    /**
     * Add a tag to be propagated along distributed traces' information. It also adds the tag to the local root span.
     *
     * @param string $key Tag key
     * @param string $value Tag value
     */
    function add_distributed_tag(string $key, string $value): void {}

    /**
     * Add user information to monitor authenticated requests in the application.
     *
     * @param string $userId Unique identifier of the user (usr.id)
     * @param array $metadata User monitoring tags (usr.<TAG_NAME>) applied to the 'meta' section of the root span
     * @param bool|null $propagate If set to 'true', user's information will be propagated in distributed traces
     */
    function set_user(string $userId, array $metadata = [], bool|null $propagate = null): void {}

    /**
     * Close child spans of a parent span if a non-internal span is given,
     * else if 'null' is given, close active, non-internal spans
     *
     * @param SpanData|null $span The parent span
     * @return false|int 'false' if spans couldn't be closed, else the number of span closed
     */
    function close_spans_until(?SpanData $span): false|int {}

    /**
     * Get the active span
     *
     * @return SpanData|null 'null' if tracing isn't enabled or there isn't any active span, else the active span
     */
    function active_span(): null|SpanData {}

    /**
     * Get the root span
     *
     * @return SpanData|null 'null' if tracing isn't enabled or if the active stack doesn't have a root span,
     * else the root span of the active stack
     */
    function root_span(): null|SpanData {}

    /**
     * Start a new custom user-span on the top of the stack. If no active span exists, the new created span will be a
     * root span, on its own new span stack (i.e., it is equivalent to 'start_trace_span'). In that case, distributed
     * tracing information will be applied if available.
     *
     * @param float $startTime Start time of the span in seconds.
     * @return SpanData|false The newly started span, or 'false' if a wrong parameter was given.
     */
    function start_span(float $startTime = 0): SpanData|false {}

    /**
     * Close the currently active user-span on the top of the stack
     *
     * @param float $finishTime Finish time in seconds.
     * @return false|null 'false' if unexpected parameters were given, else 'null'
     */
    function close_span(float $finishTime = 0): false|null {}

    /**
     * Start a new trace
     *
     * More precisely, a new root span stack will be created and switched on to, and a new span started.
     *
     * @return SpanData The newly created root span
     */
    function start_trace_span(): SpanData {}

    /**
     * Get the active stack
     *
     * @return SpanStack|null A copy of the active stack, or 'null' if the tracer is disabled. Won't happen
     * under normal operation.
     */
    function active_stack(): SpanStack|null {}

    /**
     * Initialize a new span stack and switch to it. If tracing isn't enabled, a root span stack will be created.
     *
     * @return SpanStack The newly created span stack
     */
    function create_stack(): SpanStack {}

    /**
     * Switch back to a specific stack (even if there is no active span on that stack), or to the parent of the active
     * stack if no stack is given.
     *
     * @param SpanData|SpanStack|null $newStack Stack to switch to. If 'null' is given, switches to the parent of the
     * active stack. If a SpanData object is given, it will switch to the stack of the latter.
     * @return null|false|SpanStack The newly active stack, or 'null' if the tracer is disabled (Won't happen under
     * normal operation), or 'false' if unexpected parameters were given.
     */
    function switch_stack(SpanData|SpanStack|null $newStack = null): null|false|SpanStack {}

    /**
     * Set the priority sampling level
     *
     * @param int $priority The priority level to be set to.
     * @param bool|null $global If set to 'true' and if there is no active stack (or the active stack doesn't have a
     * root span), then the default priority sampling will be set to the provided priority level. Otherwise, the root's
     * priority sampling level will be updated with the new value.
     */
    function set_priority_sampling(int $priority, bool $global = false): void {}

    /**
     * Get the priority sampling level
     *
     * @param bool|null $global If set to 'true' and if there is no active stack (or the active stack doesn't have a
     * root span), then the default priority sampling will be returned, else it will be fetched from the root.
     * @return int|null The priority sampling level, or 'null' if an unexpected parameter was given.
     */
    function get_priority_sampling(bool $global = false): int|null {}

    /**
     * Sanitize an exception
     *
     * @param \Exception|\Throwable $exception
     * @return string
     */
    function get_sanitized_exception_trace(\Exception|\Throwable $exception): string {}

    /**
     * Update datadog headers for distributed tracing for new spans. Also applies this information to the current trace,
     * if there is one, as well as the future ones if it isn't overwritten
     *
     * @param callable(string):mixed $callback Given a header name for distributed tracing, return the value it should
     * be updated to
     */
    function consume_distributed_tracing_headers(callable $callback): void {}

    /**
     * Get information on the key-value pairs of the datadog headers for distributed tracing
     *
     * @param array|null $inject dfdsq
     *
     * @return array{x-datadog-sampling-priority: string,
     *               x-datadog-origin: string,
     *               x-datadog-trace-id: string,
     *               x-datadog-parent-id: string,
     *               traceparent: string,
     *               tracestate: string
     *          }
     */
    function generate_distributed_tracing_headers(array $inject = null): array {}

    /**
     * Searches parent frames to see whether it's currently within a catch block and returns that exception.
     *
     * @return \Throwable|null The active exception if there is one, else 'null'.
     */
    function find_active_exception(): \Throwable|null {}

    /**
     * Retrieve IPs from the given array if valid headers are found, and return them in
     * a metadata formatting
     *
     * @param string[] $headers
     * @return array
     */
    function extract_ip_from_headers(array $headers): array {}

    /**
     * Get startup information in JSON format
     *
     * @return string Startup information
     */
    function startup_logs(): string {}

    /**
     * Return the id of the current trace
     *
     * @return string The id of the current trace
     */
    function trace_id(): string {}

    /**
     * Get information on the current context
     *
     * @return array{trace_id: string, span_id: string, version: string, env: string}
     */
    function current_context(): array {}

    /**
     * Apply the distributed tracing information on the current and future spans. That API can be called if there is no
     * other currently active span.
     *
     * The distributed tracing context can be reset by calling 'set_distributed_tracing_context("0", "0")'
     *
     * @param string $traceId The unique integer (128-bit unsigned) ID of the trace containing this span
     * @param string $parentId The span integer ID of the parent span
     * @param string|null $origin The distributed tracing origin
     * @param array|string|null $propagated_tags If provided, propagated tags from the root span will be cleared and
     * replaced by the given tags and applied to existing spans
     * @return bool 'true' if the distributed tracing context was properly set, else 'false' if an error occurred
     */
    function set_distributed_tracing_context(
        string $traceId,
        string $parentId,
        ?string $origin = null,
        array|string|null $propagated_tags = null
    ): bool {}

    /**
     * Closes all spans and force-send finished traces to the agent
     */
    function flush(): void {}
}

namespace DDTrace\System {

    /**
     * Get the unique identifier of the container
     *
     * @return string|null The container id, or 'null' if no id was found
     */
    function container_id(): string|null {}
}

namespace DDTrace\Config {

    /**
     * Check if the app analytics of an app is enabled for a given integration
     *
     * @param string $integrationName The name of the integration (e.g., mysqli)
     * @return bool The status of the app analytics of the integration
     */
    function integration_analytics_enabled(string $integrationName): bool {}

    /**
     * Check the app analytics sample rate of a given integration
     *
     * @param string $integrationName The name of the integration (e.g., mysqli)
     * @return float The sample rate of the app analytics of the integration
     */
    function integration_analytics_sample_rate(string $integrationName): float {}
}

namespace DDTrace\Testing {
    /**
     * Overrides PHP's default error handling.
     *
     * @param string $message Error message
     * @param int $errorType Error Type. Supported error types are: E_ERROR, E_WARNING, E_PARSE, E_NOTICE, E_CORE_ERROR,
     * E_CORE_WARNING, E_COMPILE_ERROR, E_USER_ERROR, E_USER_WARNING, E_USER_NOTICE, E_STRICT, E_RECOVERABLE_ERROR,
     * E_DEPRECATED, E_USER_DEPRECATED
     */
    function trigger_error(string $message, int $errorType): void {}
}

namespace {

    /**
     * @var string
     * @cvalue PHP_DDTRACE_VERSION
     */
    const DD_TRACE_VERSION = UNKNOWN;

    /**
     * @var int
     * @cvalue PRIORITY_SAMPLING_AUTO_KEEP
     */
    const DD_TRACE_PRIORITY_SAMPLING_AUTO_KEEP = UNKNOWN;

    /**
     * @var int
     * @cvalue PRIORITY_SAMPLING_AUTO_REJECT
     */
    const DD_TRACE_PRIORITY_SAMPLING_AUTO_REJECT = UNKNOWN;

    /**
     * @var int
     * @cvalue PRIORITY_SAMPLING_USER_KEEP
     */
    const DD_TRACE_PRIORITY_SAMPLING_USER_KEEP = UNKNOWN;

    /**
     * @var int
     * @cvalue PRIORITY_SAMPLING_USER_REJECT
     */
    const DD_TRACE_PRIORITY_SAMPLING_USER_REJECT = UNKNOWN;

    /**
     * @var int
     * @cvalue DDTRACE_PRIORITY_SAMPLING_UNKNOWN
     */
    const DD_TRACE_PRIORITY_SAMPLING_UNKNOWN = UNKNOWN;

    /**
     * @var int
     * @cvalue DDTRACE_PRIORITY_SAMPLING_UNSET
     */
    const DD_TRACE_PRIORITY_SAMPLING_UNSET = UNKNOWN;


    /**
     * Get the value of a DD environment variable
     *
     * @param string $envName Environment variable name
     * @return mixed Value of the environment variable
     */
    function dd_trace_env_config(string $envName): mixed {}

    /**
     * Disable tracing in the current request
     *
     * @return bool
     */
    function dd_trace_disable_in_request(): bool {}

    /**
     * (Noop/To do) Untrace traced functions and methods
     *
     * @internal
     * @return bool 'true' if reset was successful, else 'false'
     */
    function dd_trace_reset(): bool {}

    /**
     * If tracing is enabled, serialize the trace into a string to send to the agent
     *
     * @internal
     * @param array $traceArray Serialize values must be of type array, string, int, float, bool or null
     * @return bool|string The serialized array, else 'false' if an error was encountered
     */
    function dd_trace_serialize_msgpack(array $traceArray): bool|string {}

    /**
     * Null function to easily breakpoint the execution at specific PHP line in GDB
     *
     * @internal
     * @return bool Return 'true' if tracing is enabled, else 'false'
     */
    function dd_trace_noop(): bool {}

    /**
     * Get the parsed value of the memory limit DD_TRACE_MEMORY_LIMIT in binary bytes
     *
     * @return int The memory limit
     */
    function dd_trace_dd_get_memory_limit(): int {}

    /**
     * Check if the current memory usage is under the memory limit DD_TRACE_MEMORY_LIMIT
     *
     * @return bool 'true' if the current memory usage is under the memory limit, else 'false'
     */
    function dd_trace_check_memory_under_limit(): bool {}

    /**
     * Register a failure into the circuit breaker
     *
     * @return true
     */
    function dd_tracer_circuit_breaker_register_error(): bool {}

    /**
     * Reset the number of consecutive failures of the circuit breaker to zero, and close it
     *
     * In other words, calling this function will close the circuit breaker, hence allowing protected calls to be made
     *
     * @return bool true
     */
    function dd_tracer_circuit_breaker_register_success(): bool {}

    /**
     * Check whether the circuit breaker is closed or half-opened.
     *
     * The circuit breaker avoids making protected call when the circuit is opened (i.e., once the failures reach
     * the set threshold 'DD_TRACE_CIRCUIT_BREAKER_DEFAULT_MAX_CONSECUTIVE_FAILURES', all further calls to the circuit
     * will raise an error). While in this opened state, the circuit will self-reset to a half-opened state after the
     * interval set by 'DD_TRACE_AGENT_ATTEMPT_RETRY_TIME_MSEC', and will be ready to make a trial call to see if the
     * problem is fixed.
     *
     * @return bool 'true' if a protected call can be made, else 'false'
     */
    function dd_tracer_circuit_breaker_can_try(): bool {}

    /**
     * Get information about the circuit breaker status
     *
     * @return array{
     *     closed: bool,
     *     total_failures: int,
     *     consecutive_failures: int,
     *     opened_timestamp: int,
     *     last_failure_timestamp: int
     * }
     */
    function dd_tracer_circuit_breaker_info(): array {}

    /**
     * Get the name of the app (DD_SERVICE)
     *
     * @param string|null $fallbackName Fallback name if the app's name wasn't set
     * @return string|null The app name, else the fallback name. Return 'null' if the app name isn't set and no
     * fallback name is provided.
     */
    function ddtrace_config_app_name(?string $fallbackName = null): null|string {}

    /**
     * Check if distributed tracing is enabled (DD_DISTRIBUTED_TRACING)
     *
     * @return bool 'true' if distributed tracing is enabled, else 'false'
     */
    function ddtrace_config_distributed_tracing_enabled(): bool {}

    /**
     * Check if tracing is enabled (DD_TRACE_ENABLED)
     *
     * @return bool 'true' is tracing is enabled, else 'false'
     */
    function ddtrace_config_trace_enabled(): bool {}

    /**
     * Check if a specific integration is enabled
     *
     * @param string $integrationName The name of the integration (e.g., mysqli)
     * @return bool The status of the integration, or 'false' if tracing isn't enabled.
     */
    function ddtrace_config_integration_enabled(string $integrationName): bool {}

    /**
     * Initialize the tracer and executes the dd_init.php in the sandbox
     *
     * @internal
     * @param string $dir Directory where 'dd_init.php' is located
     * @return bool 'true' if the initialization was successful, else 'false'
     */
    function ddtrace_init(string $dir): bool {}

    /**
     * Send payload to background sender's buffer
     *
     * @internal
     * @param int $numTraces Trace count. Note that at the moment, the background sender is only capable of sending
     * exactly one trace
     * @param array $curlHeaders HTTP Headers
     * @param string $payload HTTP Body
     * @return bool 'true' if tracers were successfully sent or if the tracer is disabled, and 'false' if not exactly
     * one trace was sent or if the procedure was unsuccessful
     */
    function dd_trace_send_traces_via_thread(int $numTraces, array $curlHeaders, string $payload): bool {}

    /**
     * Serializes and sends traces to the agent (in the format dd_trace_serialize_closed_spans() returns spans).
     *
     * @internal
     * @param array $traceArray Array in the format returned by dd_trace_serialize_closed_spans()
     */
    function dd_trace_buffer_span(array $traceArray): bool {}

    /**
     * Used to send any already buffered spans to the agent
     *
     * @internal
     * @return int
     */
    function dd_trace_coms_trigger_writer_flush(): int {}

    /**
     * Execute a given internal function
     *
     * Internal functions are: init_and_start_writer, ddtrace_coms_next_group_id, ddtrace_coms_buffer_span,
     * ddtrace_coms_buffer_data, shutdown_writer, set_writer_send_on_flush, test_consumer, test_writers,
     * test_msgpack_consumer, synchronous_flush, and root_span_add_tag
     *
     * @internal
     * @param string $functionName Internal function name
     * @param mixed $args,... Arguments of the function
     * @return bool 'true' if void function was properly executed, else the return value of it
     */
    function dd_trace_internal_fn(string $functionName, mixed ...$args): bool {}

    /**
     * Set the distributed trace id
     *
     * @param string|null $traceId New trace id
     * @return bool 'true' if the change was properly applied, else 'false'
     */
    function dd_trace_set_trace_id(?string $traceId = null): bool {}

    /**
     * Tracks closed spans
     *
     * @return int Number of closed spans
     */
    function dd_trace_closed_spans_count(): int {}

    /**
     * Check if the tracer's current memory usage is higher than the set limits
     *
     * @return bool 'true' if memory is overused, else 'false'
     */
    function dd_trace_tracer_is_limited(): bool {}

    /**
     * Get the compiling time of all files compiled up to now (in µs)
     *
     * @return int Compile time
     */
    function dd_trace_compile_time_microseconds(): int {}

    /**
     * Get serialized information about closed spans as arrays. Note that calling this function will result in
     * automatically closing unfinished spans (destroys the open span stack).
     *
     * @return array Closed spans data
     */
    function dd_trace_serialize_closed_spans(): array {}

    /**
     * Get the currently active span id, or the distributed parent trace id if there is no currently active span
     *
     * @return string Currently active span/trace unique identifier
     */
    function dd_trace_peek_span_id(): string {}

    /**
     * @alias DDTrace_trace_function
     */
    function dd_trace_function(string $functionName, \Closure|array $tracingClosureOrConfigArray): bool {}

    /**
     * @alias DDTrace_trace_method
     */
    function dd_trace_method(
        string $className,
        string $methodName,
        \Closure|array $tracingClosureOrConfigArray
    ): bool {}

    /**
     * Untrace a function or a method.
     *
     * @param string $functionName The name of the function or method to untrace
     * @param string|null $className In the case of a method, its respective class should be provided as well
     * @return bool 'true' if the un-tracing process was successful, else 'false'
     */
    function dd_untrace(string $functionName, string $className = null): bool {}

    /**
     * Parameter combination:
     * - (class, function, closure | config_array)
     * - (function, closure | config_array)
     *
     * @param mixed $classOrFunctionName
     * @param mixed $methodNameOrTracingClosure
     * @param mixed $tracingClosure
     * @return bool
     * @deprecated
     */
    function dd_trace(
        mixed $classOrFunctionName,
        mixed $methodNameOrTracingClosure,
        mixed $tracingClosure = null
    ): bool {}

    /**
     * @deprecated
     * @return bool
     */
    function dd_trace_forward_call(): bool {}

    /**
     * Alias to dd_trace_push_span_id
     *
     * @alias dd_trace_push_span_id
     * @deprecated
     * @param string $existingID
     * @return string
     */
    function dd_trace_generate_id(string $existingID): string {}

    /**
     * @deprecated
     * @param string $existingID
     * @return string
     */
    function dd_trace_push_span_id(string $existingID): string {}

    /**
     * @deprecated
     * @return string
     */
    function dd_trace_pop_span_id(): string {}

    /**
     * @deprecated
     * @return array
     */
    function additional_trace_meta(): array {}
}
