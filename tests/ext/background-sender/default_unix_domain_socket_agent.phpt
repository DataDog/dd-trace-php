--TEST--
If an agent unix domain socket exists it will try to connect to it
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die('skip: run-tests crashes with shell commands on PHP 5'); ?>
<?php include __DIR__ . '/../startup_logging_skipif.inc'; ?>
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
<?php @mkdir("/var/run/datadog"); if (!is_dir("/var/run/datadog")) { `sudo mkdir /var/run/datadog <&-; sudo chown $(id -u) /var/run/datadog`; } if (!is_file("/var/run/datadog/apm.socket") && !is_writable("/var/run/datadog")) die("skip: no permissions to create a /var/run/datadog/apm.socket"); ?>
--ENV--
DD_AGENT_HOST=
--FILE--
<?php
include __DIR__ . '/../includes/request_replayer.inc';
include_once __DIR__ . '/../startup_logging.inc';

if (!file_exists("/var/run/datadog/apm.socket")) {
    @unlink("/var/run/datadog/apm.socket");
    $p = popen(PHP_BINARY . ' -r \'$s=stream_socket_server("unix:///var/run/datadog/apm.socket");print 1;$c=stream_socket_accept($s);$t=stream_socket_client("request-replayer:80");$a=$r=[$c,$t];foreach($r as $f)stream_set_blocking($f, false);while(stream_select($r,$w,$e,null)){$d=fread($f=reset($r),4096);if($d=="")exit;fwrite($f==$t?$c:$t,$d);$r=$a;}\'', 'r');
    fread($p, 1); // ready
}

$logs = dd_get_startup_logs([], ['DD_TRACE_DEBUG=1']);

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
