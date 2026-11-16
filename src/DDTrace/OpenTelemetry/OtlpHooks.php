<?php

// One-time actionable warning when the Datadog Agent returns HTTP 404 to an
// OTLP export — almost always means Agent < 7.48.0 (no OTLP ingest support)
// or the OTLP receiver isn't configured. PsrTransport wraps 4xx (non-408/429)
// errors in ErrorFuture; peek at the stored exception via reflection so we
// can emit the message once without modifying the future the caller receives.
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
