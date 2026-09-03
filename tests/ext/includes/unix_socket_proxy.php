<?php

/**
 * Bidirectional proxy from a unix domain socket to the request-replayer, used
 * by .phpt tests that exercise the tracer's unix socket agent transport.
 *
 * Launched by RequestReplayer::launchUnixProxy(), not meant to be run directly.
 *
 * Usage: php unix_socket_proxy.php <socket-path> [<host:port>] [<log-file>]
 *
 * Parent protocol:
 *  - prints "1\n" on stdout once the socket accepts connections
 *  - exits once stdin hits EOF, i.e. once the test process launching it is gone
 */

const CHUNK_SIZE = 65536;
/* Stop reading from a socket while this much data is still queued for its peer. */
const MAX_BUFFERED = 4 * CHUNK_SIZE;

if (!isset($argv[1])) {
    fwrite(STDERR, "usage: unix_socket_proxy.php <socket-path> [<host:port>] [<log-file>]\n");
    exit(1);
}

$socketPath = $argv[1];
$upstreamAddr = isset($argv[2]) ? $argv[2] : 'request-replayer:80';
$logFile = isset($argv[3]) ? $argv[3] : '/tmp/unix-proxy-' . basename($socketPath);

ignore_user_abort(true);

@unlink($socketPath);
$server = @stream_socket_server('unix://' . $socketPath, $errno, $errstr);
if (!$server) {
    fwrite(STDERR, "unix_socket_proxy: cannot listen on $socketPath: $errstr ($errno)\n");
    exit(1);
}
stream_set_blocking($server, false);

echo "1\n"; /* ready marker, awaited by launchUnixProxy() */
flush();

/* id => ['client' => resource, 'upstream' => resource, 'out' => [side => string], ...] */
$conns = [];
/* (int) resource => [id, side] */
$owner = [];
$nextId = 0;

function other_side($side)
{
    return $side === 'client' ? 'upstream' : 'client';
}

/**
 * Tests grep the proxied bytes out of this file, so keep the format as is:
 * one chunk per write, each starting on a fresh line.
 */
function proxy_log($data)
{
    global $logFile;
    file_put_contents($logFile, $data . "\n", FILE_APPEND);
}

function close_pair($id)
{
    global $conns, $owner;
    foreach (['client', 'upstream'] as $side) {
        $stream = $conns[$id][$side];
        unset($owner[(int) $stream]);
        @fclose($stream);
    }
    unset($conns[$id]);
}

function accept_pending()
{
    global $server, $upstreamAddr, $conns, $owner, $nextId;

    while (($client = @stream_socket_accept($server, 0)) !== false) {
        $upstream = @stream_socket_client('tcp://' . $upstreamAddr, $errno, $errstr, 5);
        if (!$upstream) {
            proxy_log("upstream connect to $upstreamAddr failed: $errstr ($errno)");
            fclose($client);
            continue;
        }
        stream_set_blocking($client, false);
        stream_set_blocking($upstream, false);

        $id = $nextId++;
        $conns[$id] = [
            'client' => $client,
            'upstream' => $upstream,
            'out' => ['client' => '', 'upstream' => ''],
            'eof' => ['client' => false, 'upstream' => false],
            'shutdown' => ['client' => false, 'upstream' => false],
        ];
        $owner[(int) $client] = [$id, 'client'];
        $owner[(int) $upstream] = [$id, 'upstream'];
        proxy_log('connected');
    }
}

while (true) {
    $read = [STDIN, $server];
    $write = [];
    foreach ($conns as $id => $conn) {
        foreach (['client', 'upstream'] as $side) {
            if (!$conn['eof'][$side] && strlen($conn['out'][other_side($side)]) < MAX_BUFFERED) {
                $read[] = $conn[$side];
            }
            if ($conn['out'][$side] !== '') {
                $write[] = $conn[$side];
            }
        }
    }

    $except = null;
    if (@stream_select($read, $write, $except, null) === false) {
        break; /* interrupted by a signal */
    }

    foreach ($read as $stream) {
        if ($stream === STDIN) {
            /* The test process that launched us is gone. */
            @unlink($socketPath);
            exit(0);
        }

        if ($stream === $server) {
            accept_pending();
            continue;
        }

        if (!isset($owner[(int) $stream])) {
            continue; /* closed while handling an earlier stream in this batch */
        }
        list($id, $side) = $owner[(int) $stream];

        $data = @fread($stream, CHUNK_SIZE);
        if ($data === false || $data === '') {
            $conns[$id]['eof'][$side] = true;
            proxy_log('end');
        } else {
            proxy_log($data);
            $conns[$id]['out'][other_side($side)] .= $data;
        }
    }

    foreach ($conns as $id => $conn) {
        foreach (['client', 'upstream'] as $side) {
            if ($conns[$id]['out'][$side] === '') {
                continue;
            }
            /* Sockets are non-blocking: a short write is normal, keep the rest queued. */
            $written = @fwrite($conns[$id][$side], $conns[$id]['out'][$side]);
            if ($written === false) {
                $conns[$id]['out'][$side] = '';
                $conns[$id]['eof'][$side] = true;
                continue;
            }
            $conns[$id]['out'][$side] = substr($conns[$id]['out'][$side], $written);
        }
    }

    foreach ($conns as $id => $conn) {
        /*
         * Forward each half-close once everything read before it has been
         * handed over. Responses delimited by connection close depend on this.
         */
        foreach (['client', 'upstream'] as $side) {
            $peer = other_side($side);
            if ($conns[$id]['eof'][$side]
                && $conns[$id]['out'][$peer] === ''
                && !$conns[$id]['shutdown'][$peer]
            ) {
                @stream_socket_shutdown($conns[$id][$peer], STREAM_SHUT_WR);
                $conns[$id]['shutdown'][$peer] = true;
            }
        }

        if ($conns[$id]['eof']['client'] && $conns[$id]['eof']['upstream']
            && $conns[$id]['out']['client'] === '' && $conns[$id]['out']['upstream'] === ''
        ) {
            close_pair($id);
        }
    }
}
