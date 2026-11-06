--TEST--
FFE does not warn about raw evaluation data without debug logging
--ENV--
DD_TRACE_DEBUG=0
--INI--
datadog.trace.log_file={PWD}/debug_logging_raw_data_warning_debug_off.log
datadog.trace.log_level=warn
datadog.trace.generate_root_span=0
datadog.experimental_flagging_provider_enabled=1
--FILE--
<?php
require __DIR__ . '/logging.inc';

for ($i = 0; $i < 3; ++$i) {
    DDTrace\ffe_evaluate('flag', DDTrace\FFE_STRING, 'secret-user', array('email' => 'secret-email'));
}
// Warnings reach the log at this level, so only the debug gate omits it.
DDTrace\set_user('');

$logs = read_log_snapshot(__DIR__ . '/debug_logging_raw_data_warning_debug_off.log');
var_dump(strpos($logs, 'Unexpected empty user id') !== false);
var_dump(strpos($logs, 'Feature Flags: debug logging is enabled') === false);
?>
--EXPECT--
bool(true)
bool(true)
--CLEAN--
<?php @unlink(__DIR__ . '/debug_logging_raw_data_warning_debug_off.log'); ?>
