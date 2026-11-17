<?php
// Test socket I/O profiling over a loopback connection on an ephemeral port.

const OPERATION_COUNT = 65536;
const OPERATION_SIZE = 8 * 1024;

function read_from_socket($socket, $length) {
    // A successful write precedes every read. A stream_select for every
    // operation would emit an unsampled timeline event and swamp the queue
    // with events unrelated to the byte-upscaling assertions below.
    while ($length > 0) {
        $data = fread($socket, $length);
        if ($data === false || $data === '') {
            throw new RuntimeException('Socket read did not make progress');
        }
        $length -= strlen($data);
    }
}

function write_to_socket($writer, $reader, $data) {
    $offset = 0;
    do {
        $written = fwrite($writer, $offset ? substr($data, $offset) : $data);
        if (!$written) {
            throw new RuntimeException('Socket write did not make progress');
        }
        read_from_socket($reader, $written);
        $offset += $written;
    } while ($offset < strlen($data));
}

function small_socket_writes($writer, $reader, $data) {
    for ($i = 0; $i < OPERATION_COUNT; $i++) {
        write_to_socket($writer, $reader, $data);
        // Test byte upscaling without overflowing the bounded sample queue.
        if (($i & 63) === 63) usleep(1000);
    }
}

function large_socket_writes($writer, $reader, $data) {
    for ($i = 0; $i < OPERATION_COUNT * 2; $i++) {
        write_to_socket($writer, $reader, $data);
        if (($i & 63) === 63) usleep(1000);
    }
}

function main() {
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $client = stream_socket_client('tcp://' . stream_socket_get_name($server, false));
    $socket = stream_socket_accept($server);
    stream_set_timeout($socket, 1);
    $data = str_repeat('A', OPERATION_SIZE);

    small_socket_writes($client, $socket, $data);
    large_socket_writes($client, $socket, $data);

    fclose($socket);
    fclose($client);
    fclose($server);
}
main();
