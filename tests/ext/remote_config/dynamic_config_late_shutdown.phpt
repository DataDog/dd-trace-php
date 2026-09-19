--TEST--
Remote config is not reapplied after its request shutdown cleanup
--SKIPIF--
<?php
include __DIR__ . '/../includes/skipif_no_dev_env.inc';
if (!function_exists('posix_kill') || !defined('SIGVTALRM')) {
    die('skip: POSIX signals are required');
}
?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_REMOTE_CONFIG_POLL_INTERVAL_SECONDS=0.1
DD_TRACE_AGENT_TEST_SESSION_TOKEN=remote-config/dynamic_config_late_shutdown
--FILE--
<?php

require __DIR__ . "/remote_config.inc";
include __DIR__ . '/../includes/request_replayer.inc';

final class LateRemoteConfigWrapper
{
    public $context;

    public function stream_open($path, $mode, $options, &$opened_path): bool
    {
        return true;
    }

    public function stream_close(): void
    {
        // Resource destruction runs after module RSHUTDOWN. Make the pending
        // Remote Config interrupt observable to the VM in that interval.
        posix_kill(posix_getpid(), SIGVTALRM);
        for ($i = 0; $i < 10; $i++) {
            // execute opcodes until the VM services the interrupt
        }
        echo "late close completed\n";
    }
}

reset_request_replayer();
ini_set('datadog.logs_injection', '0');

$path = put_dynamic_config_file([
    'log_injection_enabled' => true,
]);

\DDTrace\start_span();
if (ini_get('datadog.logs_injection') !== '1') {
    dd_trace_internal_fn('await_remote_config');
}
var_dump(ini_get('datadog.logs_injection'));

stream_wrapper_register('late-remote-config', LateRemoteConfigWrapper::class);
$trigger = fopen('late-remote-config://trigger', 'r');

echo "request body complete\n";

?>
--CLEAN--
<?php
require __DIR__ . "/remote_config.inc";
reset_request_replayer();
?>
--EXPECT--
string(1) "1"
request body complete
late close completed
