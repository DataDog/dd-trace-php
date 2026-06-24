<?php
/**
 * This file forces the helper into an error path by:
 *   1. Calling rshutdown so the helper returns to its outer loop awaiting request_init.
 *   2. Calling request_exec directly: the helper receives request_exec when it expects
 *      request_init and bails with `unexpected command {:?}`. That error message is
 *      logged at ERROR level and ends up in telemetry containing WafString(...) entries
 *      built from the request_exec payload.
 *
 * Used by the integration test for telemetry WafString redaction.
 */

\datadog\appsec\testing\rshutdown();

\datadog\appsec\testing\request_exec([
    'server.request.headers.no_cookies' => [
        'x-test' => 'APPSEC_REDACT_TEST_SENTINEL_XYZ',
    ],
]);

header('Content-Type: text/plain');
echo 'done';
