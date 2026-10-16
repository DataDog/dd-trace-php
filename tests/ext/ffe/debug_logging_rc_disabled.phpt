--TEST--
FFE startup diagnostics report Remote Configuration disabled despite flagging being enabled
--INI--
datadog.trace.log_file={PWD}/debug_logging_rc_disabled.log
datadog.trace.log_level=debug
datadog.trace.generate_root_span=0
datadog.experimental_flagging_provider_enabled=1
datadog.remote_config_enabled=0
--FILE--
<?php
require __DIR__ . '/logging.inc';

$logs = read_log_snapshot(__DIR__ . '/debug_logging_rc_disabled.log');
var_dump(strpos($logs, 'Feature Flags: configuration subscription disabled; set DD_REMOTE_CONFIG_ENABLED=true at startup') !== false);
var_dump(strpos($logs, 'Feature Flags: enabled; starting remote_config') === false);
?>
--EXPECT--
bool(true)
bool(true)
--CLEAN--
<?php @unlink(__DIR__ . '/debug_logging_rc_disabled.log'); ?>
