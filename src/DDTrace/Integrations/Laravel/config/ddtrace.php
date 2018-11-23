<?php

return [

    /*
     |--------------------------------------------------------------------------
     | DDTrace Settings
     |--------------------------------------------------------------------------
     |
     | Service name used for instrumentation and toggle that defines if the tracer
     | is enabled or not. If set to false the code could be still instrumented
     | because of other settings, but no spans are sent to the local trace agent.
     |
     */

    'service_name' => env('DD_SERVICE_NAME', PHP_SAPI),
    'trace_enabled' => env('DD_TRACE_ENABLED', true),

    /*
     |--------------------------------------------------------------------------
     | Distributed Tracing
     |--------------------------------------------------------------------------
     |
     | Distributed tracing allows traces to be propagated across multiple instrumented
     | applications, so that a request can be presented as a single trace, rather
     | than a separate trace per service.
     |
     */

    'distributed_tracing' => env('DD_DISTRIBUTED_TRACING', true),

    /*
     |--------------------------------------------------------------------------
     | Priority Sampling
     |--------------------------------------------------------------------------
     |
     | Priority sampling consists in deciding if a trace will be kept by using a
     | priority attribute that will be propagated for distributed traces. Its value
     | gives indication to the Agent and to the backend on how important the trace is.
     |
     */
    'priority_sampling' => env('DD_PRIORITY_SAMPLING', true),

    /*
     |--------------------------------------------------------------------------
     | Disabled Integrations
     |--------------------------------------------------------------------------
     |
     | A comma-separated list of integrations that should be disabled.
     |
     */

    'integrations_disabled' => env(
        'DD_INTEGRATIONS_DISABLED',
        !extension_loaded('ddtrace') ? 'laravel' : null
    ),

    /*
     |--------------------------------------------------------------------------
     | Global Tags
     |--------------------------------------------------------------------------
     |
     | Global tags set at the tracer level. These tags will be appended to each
     | span created by the tracer. Keys and values must be strings.
     |
     */

    'global_tags' => [],

];
