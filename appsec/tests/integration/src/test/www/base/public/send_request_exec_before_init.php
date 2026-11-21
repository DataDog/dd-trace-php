<?php
/**
 * This file forces the helper into an error path by:
 *   1. Calling rshutdown so the helper's request ends and it awaits request_init.
 *   2. Calling request_exec directly: the helper receives request_exec without a
 *      request_init and logs that at ERROR level, which ends up in telemetry.
 *
 * Used by the integration test checking that the log carries no request data.
 */

\datadog\appsec\testing\rshutdown();

\datadog\appsec\testing\request_exec([
    'server.request.headers.no_cookies' => [
        'x-test' => 'APPSEC_REDACT_TEST_SENTINEL_XYZ',
    ],
]);

header('Content-Type: text/plain');
echo 'done';
