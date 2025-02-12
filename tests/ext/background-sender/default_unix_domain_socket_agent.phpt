--TEST--
If an agent unix domain socket exists it will try to connect to it
--SKIPIF--
<?php if (false !== getenv('CI') || false !== getenv('CIRCLECI')) die('skip: This test is flaky in CI environments.'); ?>
<?php include __DIR__ . '/../startup_logging_skipif.inc'; ?>
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
<?php @mkdir("/var/run/datadog"); if (!is_dir("/var/run/datadog")) { `sudo mkdir /var/run/datadog <&-; sudo chown $(id -u) /var/run/datadog`; } if (!is_file("/var/run/datadog/apm.socket") && !is_writable("/var/run/datadog")) die("skip: no permissions to create a /var/run/datadog/apm.socket"); ?>
--ENV--
DD_AGENT_HOST=
--FILE--
<?php
include __DIR__ . '/../includes/request_replayer.inc';
include_once __DIR__ . '/../startup_logging.inc';

if (file_exists("/var/run/datadog/apm.socket")) {
    unlink("/var/run/datadog/apm.socket");
}

$proxy = RequestReplayer::launchUnixProxy("/var/run/datadog/apm.socket");

$logs = dd_get_startup_logs([], ['DD_TRACE_LOG_LEVEL' => 'error,startup=info']);

dd_dump_startup_logs($logs, [
    'agent_error', // should be absent
    'agent_url',
]);
?>
--CLEAN--
<?php
if (!@stream_socket_client("/var/run/datadog/apm.socket")) @unlink("/var/run/datadog/apm.socket");
?>
--EXPECT--
agent_url: "unix:///var/run/datadog/apm.socket"
