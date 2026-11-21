<?php
/**
 * This file ends the helper's request early, so that the extension's own
 * request_shutdown violates the protocol: the helper logs an error, with
 * backtrace. Used by the integration tests.
 */

// This needs DD_APPSEC_TESTING_INVALID_COMMAND=1 to be set
$result = \datadog\appsec\testing\send_invalid_msg();

header('Content-Type: text/plain');
echo $result ? 'sent' : 'failed';
