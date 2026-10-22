<?php
// A minimal FastCGI client for the php2shell_mitigation_* tests: it runs
// php-cgi as a FastCGI responder, so that a request can carry environment
// variables (FastCGI params) that differ from the process environment seen
// by MINIT, as with php-fpm pool env[] directives. Must stay valid on PHP 7.0.
// Requires php2shell_cgi.php.

const P2S_FCGI_BEGIN_REQUEST = 1;
const P2S_FCGI_END_REQUEST = 3;
const P2S_FCGI_PARAMS = 4;
const P2S_FCGI_STDIN = 5;
const P2S_FCGI_STDOUT = 6;
const P2S_FCGI_STDERR = 7;

// Start php-cgi as a FastCGI responder that serves one request on a listening
// socket passed as its stdin, then send it one request: $params on top of the
// CGI environment of a request for php2shell_child.php, and $body as a
// urlencoded POST body if not null. $ini: extra INI settings; $env: extra
// process environment of php-cgi. Returns ['out' => stdout, 'err' => stderr,
// 'status' => exit status], like p2s_cgi_run().
function p2s_fcgi_run(array $ini, array $env, array $params, $body = null) {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if (!$server) {
        return ['out' => '', 'err' => "stream_socket_server failed: $errstr", 'status' => -1];
    }
    $cmd = escapeshellarg(getenv('TEST_PHP_CGI_EXECUTABLE')) . ' '
        . getenv('TEST_PHP_EXTRA_ARGS')
        . ' -d display_errors=1 -d html_errors=0 -d log_errors=0';
    foreach ($ini as $k => $v) {
        $cmd .= ' -d ' . escapeshellarg("$k=$v");
    }
    // PHP_FCGI_CHILDREN unset: php-cgi serves the requests itself.
    $child_env = $env + p2s_base_env();
    unset($child_env['PHP_FCGI_CHILDREN'], $child_env['FCGI_WEB_SERVER_ADDRS']);
    $err_file = tempnam(sys_get_temp_dir(), 'p2s_err');
    $proc = proc_open('exec ' . $cmd, [
        0 => $server,
        1 => ['file', '/dev/null', 'w'],
        2 => ['file', $err_file, 'w'],
    ], $pipes, null, $child_env);
    if (!is_resource($proc)) {
        return ['out' => '', 'err' => "proc_open failed: $cmd", 'status' => -1];
    }

    $params = $params + p2s_cgi_env(P2S_CHILD_SCRIPT, $body === null ? 'GET' : 'POST');
    if ($body !== null) {
        $params += [
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            'CONTENT_LENGTH' => (string)strlen($body),
        ];
    }
    $out = '';
    $conn = stream_socket_client(stream_socket_get_name($server, false), $errno, $errstr, 10);
    if ($conn) {
        stream_set_timeout($conn, 60);
        // Role responder, flags 0: php-cgi closes the connection at the end.
        fwrite($conn, p2s_fcgi_record(P2S_FCGI_BEGIN_REQUEST, pack('nCx5', 1, 0)));
        $p = '';
        foreach ($params as $k => $v) {
            $p .= p2s_fcgi_length(strlen($k)) . p2s_fcgi_length(strlen($v)) . $k . $v;
        }
        p2s_fcgi_write_stream($conn, P2S_FCGI_PARAMS, $p);
        p2s_fcgi_write_stream($conn, P2S_FCGI_STDIN, (string)$body);
        $out = p2s_fcgi_read_stdout($conn);
        fclose($conn);
    } else {
        $out = "CONNECT FAILED: $errstr";
    }
    fclose($server);
    // A php-cgi whose request startup failed exits by itself; one that
    // served the request waits for the next one, so it is killed.
    $st = proc_get_status($proc);
    if ($st['running']) {
        proc_terminate($proc, 9);
    }
    $status = proc_close($proc);
    $err = (string)file_get_contents($err_file);
    @unlink($err_file);
    return ['out' => $out, 'err' => $err, 'status' => $status];
}

function p2s_fcgi_record($type, $content) {
    return pack('CCnnCx', 1, $type, 1, strlen($content), 0) . $content;
}

function p2s_fcgi_length($len) {
    return $len < 128 ? chr($len) : pack('N', $len | 0x80000000);
}

// Write $data as a stream of records of $type, ended by an empty record.
function p2s_fcgi_write_stream($conn, $type, $data) {
    for ($off = 0, $len = strlen($data); $off < $len; $off += 65535) {
        p2s_fcgi_write_all($conn, p2s_fcgi_record($type, substr($data, $off, 65535)));
    }
    p2s_fcgi_write_all($conn, p2s_fcgi_record($type, ''));
}

function p2s_fcgi_write_all($conn, $buf) {
    for ($off = 0, $len = strlen($buf); $off < $len; $off += $n) {
        $n = @fwrite($conn, substr($buf, $off));
        if ($n === false || $n === 0) {
            return;
        }
    }
}

// The STDOUT and STDERR records up to END_REQUEST or the end of the
// connection, concatenated.
function p2s_fcgi_read_stdout($conn) {
    $out = '';
    while (strlen($hdr = p2s_fcgi_read_exact($conn, 8)) === 8) {
        $h = unpack('Cversion/Ctype/nid/nlen/Cpad', $hdr);
        $content = p2s_fcgi_read_exact($conn, $h['len'] + $h['pad']);
        if ($h['type'] === P2S_FCGI_STDOUT || $h['type'] === P2S_FCGI_STDERR) {
            $out .= substr($content, 0, $h['len']);
        } elseif ($h['type'] === P2S_FCGI_END_REQUEST) {
            break;
        }
    }
    return $out;
}

function p2s_fcgi_read_exact($conn, $len) {
    $buf = '';
    while (strlen($buf) < $len) {
        $chunk = fread($conn, $len - strlen($buf));
        if ($chunk === false || $chunk === '') {
            break;
        }
        $buf .= $chunk;
    }
    return $buf;
}
