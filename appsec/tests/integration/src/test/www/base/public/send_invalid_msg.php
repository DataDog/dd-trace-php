<?php
/**
 * This file sends an invalid message to the helper to trigger an error with backtrace.
 * Used by the integration test for telemetry backtrace verification.
 */

// First, ensure we have a connection to the helper
// This needs DD_APPSEC_TESTING=1 to be set
$result = \datadog\appsec\testing\send_invalid_msg();

header('Content-Type: text/plain');
echo $result ? 'sent' : 'failed';
