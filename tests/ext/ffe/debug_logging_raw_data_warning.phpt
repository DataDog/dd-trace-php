--TEST--
FFE warns once per process that DD_TRACE_DEBUG logs raw evaluation data
--ENV--
DD_TRACE_DEBUG=1
--INI--
datadog.trace.log_file={PWD}/debug_logging_raw_data_warning.log
datadog.trace.generate_root_span=0
datadog.experimental_flagging_provider_enabled=1
--FILE--
<?php
require __DIR__ . '/logging.inc';

$warning = 'Feature Flags: debug logging is enabled, so feature flag logs include raw evaluation data (targeting keys, evaluation context attributes, flag values, and default values) that may contain personal or sensitive information. Unset DD_TRACE_DEBUG when you no longer need it.';

for ($i = 0; $i < 3; ++$i) {
    DDTrace\ffe_evaluate('flag', DDTrace\FFE_STRING, 'secret-user', array('email' => 'secret-email'));
}
DDTrace\ffe_evaluate('other', DDTrace\FFE_BOOL, null, array());

$logs = read_log_snapshot(__DIR__ . '/debug_logging_raw_data_warning.log');
var_dump(substr_count($logs, 'Feature Flags: native evaluation ') === 4);
var_dump(substr_count($logs, $warning) === 1);
var_dump(preg_match('/\[ddtrace\] \[warning\] \[\d+\] ' . preg_quote($warning, '/') . '$/m', $logs) === 1);
$position = strpos($logs, $warning);
var_dump($position !== false && $position < strpos($logs, 'Feature Flags: native evaluation '));
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
--CLEAN--
<?php @unlink(__DIR__ . '/debug_logging_raw_data_warning.log'); ?>
