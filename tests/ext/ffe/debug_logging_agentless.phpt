--TEST--
FFE startup diagnostics report that agentless tracing disables Agent Remote Configuration
--INI--
datadog.trace.log_file={PWD}/debug_logging_agentless.log
datadog.trace.log_level=debug
datadog.trace.generate_root_span=0
datadog.experimental_flagging_provider_enabled=1
datadog.remote_config_enabled=1
datadog.trace.agentless=1
--FILE--
<?php
require __DIR__ . '/logging.inc';

$logs = read_log_snapshot(__DIR__ . '/debug_logging_agentless.log');
var_dump(strpos($logs, 'Feature Flags: configuration subscription disabled; DD_TRACE_AGENTLESS=true at startup disables Agent Remote Configuration') !== false);
var_dump(strpos($logs, 'Feature Flags: enabled; starting remote_config') === false);
?>
--EXPECT--
bool(true)
bool(true)
--CLEAN--
<?php @unlink(__DIR__ . '/debug_logging_agentless.log'); ?>
