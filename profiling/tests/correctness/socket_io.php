<?php
// Test socket I/O profiling over a loopback connection on an ephemeral port.

const OPERATION_COUNT = 65536;
const OPERATION_SIZE = 8 * 1024;

function read_from_socket($socket, $length) {
    $read = [$socket];
    $write = $except = [];
    if (stream_select($read, $write, $except, 1) !== 1) {
        throw new RuntimeException('Socket did not become readable');
    }
    while ($length > 0) {
        $length -= strlen(fread($socket, $length));
    }
}

function small_socket_writes($writer, $reader, $data) {
    for ($i = 0; $i < OPERATION_COUNT; $i++) {
        read_from_socket($reader, fwrite($writer, $data));
    }
}

function large_socket_writes($writer, $reader, $data) {
    for ($i = 0; $i < OPERATION_COUNT * 2; $i++) {
        read_from_socket($reader, fwrite($writer, $data));
    }
}

function main() {
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $client = stream_socket_client('tcp://' . stream_socket_get_name($server, false));
    $socket = stream_socket_accept($server);
    $data = str_repeat('A', OPERATION_SIZE);

    small_socket_writes($client, $socket, $data);
    large_socket_writes($client, $socket, $data);

    fclose($socket);
    fclose($client);
    fclose($server);
}
main();
