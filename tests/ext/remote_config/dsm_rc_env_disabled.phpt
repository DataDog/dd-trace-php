--TEST--
DSM2-611: with DD_DATA_STREAMS_ENABLED=0, Remote Config wins and removing its key restores the env value
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_REMOTE_CONFIG_POLL_INTERVAL_SECONDS=0.1
DD_DATA_STREAMS_ENABLED=0
DD_TRACE_AGENT_TEST_SESSION_TOKEN=remote-config/dsm_rc_env_disabled
--FILE--
<?php

require __DIR__ . "/dsm_remote_config.inc";
include __DIR__ . '/../includes/request_replayer.inc';

reset_request_replayer();
$rr = new RequestReplayer();
\DDTrace\start_span();

dsm_state("startup");

$path = dsm_put_config(["data_streams_enabled" => false], 0.31);
dsm_state("rc false");

dsm_remove_config($path, 0.31);
dsm_state("key removed");

$path = dsm_put_config(["data_streams_enabled" => true], 0.32);
dsm_state("rc true");
// Remote Config owns the setting while its key is present.
var_dump(ini_set("datadog.data_streams_enabled", "0"));
dsm_state("ini_set while rc true");

dsm_remove_config($path, 0.32);
dsm_state("key removed again");

?>
--CLEAN--
<?php
require __DIR__ . "/remote_config.inc";
reset_request_replayer();
?>
--EXPECT--
startup: setting=off checkpoint=none
rc false: setting=off checkpoint=none
key removed: setting=off checkpoint=none
rc true: setting=on checkpoint=set
bool(false)
ini_set while rc true: setting=on checkpoint=set
key removed again: setting=off checkpoint=none
