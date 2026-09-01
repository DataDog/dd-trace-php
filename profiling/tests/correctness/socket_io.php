<?php
// Test socket I/O profiling over a loopback connection on an ephemeral port.

function write_to_socket($socket, $data) {
    return fwrite($socket, $data);
}

function read_from_socket($socket, $length) {
    $read = [$socket];
    $write = $except = [];
    if (stream_select($read, $write, $except, 1) !== 1) {
        throw new RuntimeException('Socket did not become readable');
    }
    fread($socket, $length);
}

function main() {
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $client = stream_socket_client('tcp://' . stream_socket_get_name($server, false));
    $socket = stream_socket_accept($server);
    $data = str_repeat('A', 8192);

    $end = microtime(true) + ($_ENV['EXECUTION_TIME'] ?? 10);
    while (microtime(true) < $end) {
        read_from_socket($socket, write_to_socket($client, $data));
    }

    fclose($socket);
    fclose($client);
    fclose($server);
}
main();
