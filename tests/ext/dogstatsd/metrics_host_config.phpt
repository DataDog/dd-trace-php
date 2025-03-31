--TEST--
Test DogStatsD configuration with DD_DOGSTATSD_HOST and DD_DOGSTATSD_PORT
--SKIPIF--
<?php if (!extension_loaded('sockets')) die('skip: the sockets extension is required for this test'); ?>
--ENV--
DD_DOGSTATSD_HOST='127.0.0.1'
DD_DOGSTATSD_PORT=9876
--FILE--
<?php

// Create UDP server to receive metrics
$socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
if (!$socket) {
    die("Could not create socket: " . socket_strerror(socket_last_error()));
}

if (!socket_bind($socket, '127.0.0.1', 9876)) {
    die("Could not bind socket: " . socket_strerror(socket_last_error()));
}

// Send test metric
\DDTrace\dogstatsd_count("test.host.port.config", 42, ['test' => 'host_port']);

// Receive and verify metric
$buf = '';
$from = '';
$port = 0;
socket_recvfrom($socket, $buf, 1024, 0, $from, $port);
socket_close($socket);

var_dump($buf);
?>
--EXPECTF--
string(%d) "test.host.port.config:42|c|#service:metrics_host_config.php,test:host_port" 