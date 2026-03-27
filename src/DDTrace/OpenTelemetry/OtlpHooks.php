<?php

// This file installs hooks that improve OTLP export reliability:
//   1. A one-time actionable warning when the Datadog Agent returns HTTP 404
//      (i.e. Agent < 7.48.0 that does not support OTLP ingest)
//   2. A graceful fallback to 'http/protobuf' when an unrecognized protocol
//      value reaches Protocols::contentType(), preventing an uncaught
//      UnexpectedValueException from silently killing all exports

// One-time 404 warning ---------------------------------------------------
// PsrTransport wraps 4xx (non-408/429) errors in ErrorFuture. Peek at the
// stored exception via reflection so we can emit a human-actionable message
// once, without modifying the future that the caller receives.
\DDTrace\install_hook(
    'OpenTelemetry\SDK\Common\Export\Http\PsrTransport::send',
    null,
    function (\DDTrace\HookData $hook) {
        $future = $hook->returned;
        if (!($future instanceof \OpenTelemetry\SDK\Common\Future\ErrorFuture)) {
            return;
        }

        try {
            $r = new \ReflectionProperty(\OpenTelemetry\SDK\Common\Future\ErrorFuture::class, 'throwable');
            $r->setAccessible(true);
            $e = $r->getValue($future);
        } catch (\Throwable $ignored) {
            return;
        }

        if (!($e instanceof \RuntimeException) || $e->getCode() !== 404) {
            return;
        }

        static $warned404 = false;
        if (!$warned404) {
            $warned404 = true;
            trigger_error(
                'Datadog OpenTelemetry OTLP export received HTTP 404 Not Found. '
                . 'Ensure Datadog Agent >= 7.48.0 is running and configured to accept OTLP data '
                . '(set DD_OTLP_CONFIG_RECEIVER_PROTOCOLS_HTTP_ENDPOINT or equivalent).',
                E_USER_WARNING
            );
        }
    }
);

// Protocol fallback -------------------------------------------------------
// The OTel SDK's Protocols::contentType() calls validate() which throws
// UnexpectedValueException for unknown protocols. We replace an invalid value
// with 'http/protobuf' before the method body runs, emitting a one-time
// warning so the user knows their configuration was ignored.
\DDTrace\install_hook(
    'OpenTelemetry\Contrib\Otlp\Protocols::contentType',
    function (\DDTrace\HookData $hook) {
        $protocol = $hook->args[0] ?? null;
        if ($protocol === null) {
            return;
        }

        static $valid = ['grpc', 'http/protobuf', 'http/json', 'http/ndjson'];
        if (in_array($protocol, $valid, true)) {
            return;
        }

        static $warnedProtocol = false;
        if (!$warnedProtocol) {
            $warnedProtocol = true;
            trigger_error(
                "OpenTelemetry OTLP protocol '$protocol' is not recognized. "
                . "Valid values are: grpc, http/protobuf, http/json, http/ndjson. "
                . "Falling back to 'http/protobuf'.",
                E_USER_WARNING
            );
        }

        $hook->args[0] = 'http/protobuf';
    }
);
