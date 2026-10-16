--TEST--
FFE debug diagnostics stay silent until flags are evaluated with the subscription disabled
--INI--
datadog.trace.log_file={PWD}/debug_logging_unused.log
datadog.trace.log_level=debug
datadog.trace.generate_root_span=0
datadog.experimental_flagging_provider_enabled=0
--FILE--
<?php
require __DIR__ . '/logging.inc';

$file = __DIR__ . '/debug_logging_unused.log';
$hint = 'Feature Flags: flagging provider is disabled, so no flag configuration will be received and default values are returned; set DD_EXPERIMENTAL_FLAGGING_PROVIDER_ENABLED=true at startup (environment or php.ini) and restart';

// Applications that never use feature flags must not get FFE startup noise.
var_dump(strpos((string) @read_log_snapshot($file), 'Feature Flags:') === false);

DDTrace\ffe_evaluate('flag', DDTrace\FFE_BOOL, null, array());
DDTrace\ffe_evaluate('flag', DDTrace\FFE_BOOL, null, array());
var_dump(substr_count(read_log_snapshot($file), $hint) === 1);
?>
--EXPECT--
bool(true)
bool(true)
--CLEAN--
<?php @unlink(__DIR__ . '/debug_logging_unused.log'); ?>
