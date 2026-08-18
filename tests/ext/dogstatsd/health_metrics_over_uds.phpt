--TEST--
Health metrics (heartbeat) are delivered over a Unix Domain Socket
--DESCRIPTION--
Regression test for https://github.com/DataDog/dd-trace-php/issues/4100:
the tracer's internal health-metrics DogStatsD client (tracer/dogstatsd_client.c)
built a SOCK_STREAM socket for unix:// URLs, while DogStatsD unix sockets are
always SOCK_DGRAM. Connecting a stream socket to a real agent's datagram
socket fails with EPROTOTYPE, so the heartbeat metric was silently dropped.

Health metrics are sent once, from RINIT, before the --FILE-- script itself
runs. So the listener has to be already bound before the test process starts:
this test binds it here and forks a child PHP process to reproduce RINIT.
--SKIPIF--
<?php
if (strncasecmp(PHP_OS, "WIN", 3) == 0) die('skip: There are no unix sockets on Windows');
if (!extension_loaded('sockets')) die('skip: the sockets extension is required for this test');
if (PHP_VERSION_ID < 70200) die('skip: this test triggers a bug in PHP < 7.2 (See https://github.com/php/php-src/pull/3408)');
?>
--FILE--
<?php

$path = '/tmp/ddtrace-test-health_metrics_over_uds.socket';
@unlink($path);

$socket = socket_create(AF_UNIX, SOCK_DGRAM, 0);
if (!$socket || !socket_bind($socket, $path)) {
    die("Could not create/bind the UDS listener\n");
}
chmod($path, 0777);

$php = getenv('TEST_PHP_EXECUTABLE');
$args = getenv('TEST_PHP_ARGS') . " " . getenv("TEST_PHP_EXTRA_ARGS");

putenv('DD_TRACE_HEALTH_METRICS_ENABLED=1');
putenv('DD_TRACE_HEALTH_METRICS_HEARTBEAT_SAMPLE_RATE=1');
putenv("DD_DOGSTATSD_URL=unix://$path");
putenv('DD_TRACE_GENERATE_ROOT_SPAN=0');
putenv('DD_INSTRUMENTATION_TELEMETRY_ENABLED=0');
putenv('DD_REMOTE_CONFIG_ENABLED=0');
putenv('DD_TRACE_CLI_ENABLED=1');

system($php . ' ' . $args . " -r 'echo \"child done\", PHP_EOL;'");

$buf = '';
for ($i = 0; $i < 5000; ++$i) {
    usleep(1000);
    if (socket_recvfrom($socket, $buf, 2048, MSG_DONTWAIT, $remote_ip, $remote_port)) {
        break;
    }
}
socket_close($socket);
@unlink($path);

if (preg_match('/^datadog\.tracer\.heartbeat:1\|g\|#lang:php,lang_version:[^,]+,tracer_version:\S+$/', $buf)) {
    echo "heartbeat received\n";
} else {
    echo "no heartbeat received, got: ", var_export($buf, true), "\n";
}

?>
--EXPECT--
child done
heartbeat received
