<?php
// A minimal HTTP/2 cleartext server (prior knowledge, no Upgrade) that uses
// server push, for the curl_downstream_*.phpt tests. Run it with
// `php -n h2c_push_server.php [<log file> [<authority> [<delay ms>]]]` (an
// empty argument means the default): it listens on a
// free port of 127.0.0.1, prints "PORT <n>" and serves any number of
// connections (concurrently, from one select() loop). It exits after 60
// seconds without activity or when its stdin is closed (the test process
// went away). With a log file, it appends one line per frame it receives and
// per stream it answers.
//
// The client's header blocks are not decoded (no HPACK decoder: whatever the
// request, the answer is the same). Each request stream, once the client
// half-closed it (END_STREAM on HEADERS or DATA), gets:
//   - if the client did not disable push (SETTINGS_ENABLE_PUSH=0, which
//     libcurl sends when the multi handle has no CURLMOPT_PUSHFUNCTION):
//     PUSH_PROMISE for GET http://<authority>/pushed on a new even stream
//     (<authority> defaults to 127.0.0.1:<port>; libcurl < 8.1.0 cannot
//     parse an :authority with a port and denies such pushes, so with those
//     pass "127.0.0.1" and have the client use port 80 in its URL, with
//     CURLOPT_CONNECT_TO "127.0.0.1:80:127.0.0.1:<port>"), then that
//     stream's response (200, text/plain, x-pushed: yes, "pushed body\n");
//   - its own response (200, text/plain, x-main: yes, "main body\n").
// With <delay ms>, the pushed response is sent that long after the request's
// own response, instead of before it. libcurl 7.88.x needs that: when it
// reads the pushed stream's HEADERS on behalf of another transfer, before
// the pushed transfer ever ran, it copies them to a NULL buffer and crashes
// (http2.c on_frame_recv(), stream->mem is the pushed handle's
// state.buffer, still unallocated).
// What it sends is HPACK-encoded as literals without indexing, new name, no
// Huffman, so it needs no dynamic table. Bodies are far below the initial
// flow control windows, so WINDOW_UPDATE frames are just logged.

const H2_DATA = 0x0;
const H2_HEADERS = 0x1;
const H2_PRIORITY = 0x2;
const H2_RST_STREAM = 0x3;
const H2_SETTINGS = 0x4;
const H2_PUSH_PROMISE = 0x5;
const H2_PING = 0x6;
const H2_GOAWAY = 0x7;
const H2_WINDOW_UPDATE = 0x8;
const H2_CONTINUATION = 0x9;

const H2_F_END_STREAM = 0x1;
const H2_F_ACK = 0x1;
const H2_F_END_HEADERS = 0x4;
const H2_F_PADDED = 0x8;
const H2_F_PRIORITY = 0x20;

const H2_PREFACE = "PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n";

$srv = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if (!$srv) {
    fwrite(STDOUT, "ERROR $errstr\n");
    exit(1);
}
$log = isset($argv[1]) && $argv[1] !== '' ? $argv[1] : null;
$name = stream_socket_get_name($srv, false);
$port = (int)substr($name, strrpos($name, ':') + 1);
$authority = isset($argv[2]) && $argv[2] !== '' ? $argv[2] : "127.0.0.1:$port";
$delay = isset($argv[3]) && $argv[3] !== '' ? (int)$argv[3] / 1000 : null;
$pending = []; // delayed pushed responses: [due time, connection id, sid]
fwrite(STDOUT, "PORT $port\n");
fflush(STDOUT);

$conns = []; // (int)$sock => connection state
$idle_since = time();
while (time() - $idle_since < 60) {
    $r = [$srv, STDIN];
    foreach ($conns as $c) {
        $r[] = $c['sock'];
    }
    $w = $e = null;
    $timeout = 1000000;
    foreach ($pending as $p) {
        $timeout = min($timeout, max(0, (int)(($p[0] - microtime(true)) * 1000000)));
    }
    $n = @stream_select($r, $w, $e, 0, $timeout);
    pending_send($conns, $pending, $log);
    if ($n < 1) {
        continue;
    }
    $idle_since = time();
    // readable stdin means the test process closed it (see curl_server.php)
    if (in_array(STDIN, $r, true) && ((string)fread(STDIN, 1) === '' || feof(STDIN))) {
        break;
    }
    foreach ($r as $s) {
        if ($s === $srv) {
            $sock = @stream_socket_accept($srv, 5);
            if ($sock) {
                stream_set_timeout($sock, 10);
                $conns[(int)$sock] = [
                    'id' => (int)$sock,
                    'sock' => $sock, 'buf' => '', 'preface' => false,
                    'push' => true, 'next_push' => 2,
                    'hdr_sid' => 0, 'hdr_flags' => 0, // pending header block
                    'answered' => [], 'reset' => [],
                ];
                srv_log($log, 'accept');
            }
        } elseif ($s !== STDIN) {
            $id = (int)$s;
            $data = fread($s, 65536);
            if ($data === false || $data === '') {
                if (feof($s)) {
                    conn_close($conns, $id, $log, 'eof');
                }
                continue;
            }
            $conns[$id]['buf'] .= $data;
            if (!conn_process($conns[$id], $authority, $log)) {
                conn_close($conns, $id, $log, 'done');
            }
        }
    }
}

function pending_send(array &$conns, array &$pending, $log)
{
    foreach ($pending as $k => $p) {
        if ($p[0] > microtime(true)) {
            continue;
        }
        unset($pending[$k]);
        if (isset($conns[$p[1]]) && !isset($conns[$p[1]]['reset'][$p[2]])) {
            response_send($conns[$p[1]], $p[2], 'pushed body', ['x-pushed' => 'yes']);
            srv_log($log, "answered sid={$p[2]} (delayed)");
        }
    }
}

function conn_close(array &$conns, $id, $log, $why)
{
    fclose($conns[$id]['sock']);
    unset($conns[$id]);
    srv_log($log, "close ($why)");
}

// Returns false when the connection is to be closed.
function conn_process(array &$c, $authority, $log)
{
    if (!$c['preface']) {
        if (strlen($c['buf']) < strlen(H2_PREFACE)) {
            return true;
        }
        if (strncmp($c['buf'], H2_PREFACE, strlen(H2_PREFACE)) !== 0) {
            srv_log($log, 'bad preface');
            return false;
        }
        $c['buf'] = (string)substr($c['buf'], strlen(H2_PREFACE));
        $c['preface'] = true;
        // SETTINGS_MAX_CONCURRENT_STREAMS (0x3) = 100
        frame_send($c, H2_SETTINGS, 0, 0, pack('nN', 0x3, 100));
    }
    while (strlen($c['buf']) >= 9) {
        $h = unpack('Clen_hi/nlen_lo/Ctype/Cflags/Nsid', $c['buf']);
        $len = ($h['len_hi'] << 16) | $h['len_lo'];
        if (strlen($c['buf']) < 9 + $len) {
            break;
        }
        $payload = (string)substr($c['buf'], 9, $len);
        $c['buf'] = (string)substr($c['buf'], 9 + $len);
        if (!frame_handle($c, $h['type'], $h['flags'], $h['sid'] & 0x7fffffff,
                $payload, $authority, $log)) {
            return false;
        }
    }
    return true;
}

function frame_handle(array &$c, $type, $flags, $sid, $payload, $authority, $log)
{
    if ($c['hdr_sid'] && $type !== H2_CONTINUATION) {
        srv_log($log, "expected CONTINUATION, got type $type");
        return false;
    }
    switch ($type) {
    case H2_SETTINGS:
        if ($flags & H2_F_ACK) {
            srv_log($log, 'SETTINGS ack');
            break;
        }
        $desc = [];
        for ($i = 0; $i + 6 <= strlen($payload); $i += 6) {
            $s = unpack('nid/Nval', substr($payload, $i, 6));
            $desc[] = "{$s['id']}={$s['val']}";
            if ($s['id'] === 0x2) { // SETTINGS_ENABLE_PUSH
                $c['push'] = $s['val'] !== 0;
            }
        }
        srv_log($log, 'SETTINGS ' . implode(',', $desc));
        frame_send($c, H2_SETTINGS, H2_F_ACK, 0, '');
        break;
    case H2_PING:
        if (!($flags & H2_F_ACK)) {
            frame_send($c, H2_PING, H2_F_ACK, 0, $payload);
        }
        break;
    case H2_WINDOW_UPDATE:
        $v = unpack('Ninc', $payload);
        srv_log($log, "WINDOW_UPDATE sid=$sid inc=" . ($v['inc'] & 0x7fffffff));
        break;
    case H2_PRIORITY:
        break;
    case H2_RST_STREAM:
        $v = unpack('Ncode', $payload);
        srv_log($log, "RST_STREAM sid=$sid code={$v['code']}");
        $c['reset'][$sid] = true;
        break;
    case H2_GOAWAY:
        $v = unpack('Nlast/Ncode', $payload);
        srv_log($log, "GOAWAY code={$v['code']}");
        return false;
    case H2_HEADERS:
        srv_log($log, "HEADERS sid=$sid flags=$flags");
        if ($flags & H2_F_END_HEADERS) {
            if ($flags & H2_F_END_STREAM) {
                stream_answer($c, $sid, $authority, $log);
            }
        } else {
            $c['hdr_sid'] = $sid;
            $c['hdr_flags'] = $flags;
        }
        break;
    case H2_CONTINUATION:
        srv_log($log, "CONTINUATION sid=$sid flags=$flags");
        if ($sid !== $c['hdr_sid']) {
            return false;
        }
        if ($flags & H2_F_END_HEADERS) {
            $c['hdr_sid'] = 0;
            if ($c['hdr_flags'] & H2_F_END_STREAM) {
                stream_answer($c, $sid, $authority, $log);
            }
        }
        break;
    case H2_DATA:
        srv_log($log, "DATA sid=$sid len=" . strlen($payload) . " flags=$flags");
        if (strlen($payload) > 0) {
            // give the flow control credit back, for the connection and
            // for the stream
            $inc = pack('N', strlen($payload));
            frame_send($c, H2_WINDOW_UPDATE, 0, 0, $inc);
            frame_send($c, H2_WINDOW_UPDATE, 0, $sid, $inc);
        }
        if ($flags & H2_F_END_STREAM) {
            stream_answer($c, $sid, $authority, $log);
        }
        break;
    default:
        // unknown frame types must be ignored
        break;
    }
    return true;
}

function stream_answer(array &$c, $sid, $authority, $log)
{
    if (isset($c['answered'][$sid]) || ($sid & 1) === 0) {
        return;
    }
    $c['answered'][$sid] = true;
    if ($c['push']) {
        $psid = $c['next_push'];
        $c['next_push'] += 2;
        $block = hpack_block([
            ':method' => 'GET',
            ':scheme' => 'http',
            ':authority' => $authority,
            ':path' => '/pushed',
        ]);
        frame_send($c, H2_PUSH_PROMISE, H2_F_END_HEADERS, $sid, pack('N', $psid) . $block);
        srv_log($log, "PUSH_PROMISE sid=$sid promised=$psid $authority/pushed");
        if ($GLOBALS['delay'] === null) {
            response_send($c, $psid, 'pushed body', ['x-pushed' => 'yes']);
        } else {
            $GLOBALS['pending'][] = [microtime(true) + $GLOBALS['delay'], $c['id'], $psid];
        }
    }
    response_send($c, $sid, 'main body', ['x-main' => 'yes']);
    srv_log($log, "answered sid=$sid");
}

function response_send(array &$c, $sid, $text, array $extra)
{
    $body = "$text\n";
    $block = hpack_block([
        ':status' => '200',
        'content-type' => 'text/plain',
        'content-length' => (string)strlen($body),
    ] + $extra);
    frame_send($c, H2_HEADERS, H2_F_END_HEADERS, $sid, $block);
    frame_send($c, H2_DATA, H2_F_END_STREAM, $sid, $body);
}

// Literal header fields without indexing, new name (0x00), no Huffman.
function hpack_block(array $headers)
{
    $out = '';
    foreach ($headers as $n => $v) {
        $out .= "\x00" . hpack_int(strlen($n), 7) . $n . hpack_int(strlen($v), 7) . $v;
    }
    return $out;
}

// HPACK integer with an N-bit prefix (RFC 7541, 5.1); the other bits of the
// first byte are 0 (H=0 for strings).
function hpack_int($i, $n)
{
    $max = (1 << $n) - 1;
    if ($i < $max) {
        return chr($i);
    }
    $out = chr($max);
    $i -= $max;
    while ($i >= 128) {
        $out .= chr(($i & 0x7f) | 0x80);
        $i >>= 7;
    }
    return $out . chr($i);
}

function frame_send(array &$c, $type, $flags, $sid, $payload)
{
    $len = strlen($payload);
    $frame = pack('CnCCN', $len >> 16, $len & 0xffff, $type, $flags, $sid) . $payload;
    for ($off = 0; $off < strlen($frame); $off += $n) {
        $n = @fwrite($c['sock'], (string)substr($frame, $off));
        if (!$n) {
            return;
        }
    }
}

function srv_log($log, $msg)
{
    if ($log !== null) {
        file_put_contents($log, "$msg\n", FILE_APPEND);
    }
}
