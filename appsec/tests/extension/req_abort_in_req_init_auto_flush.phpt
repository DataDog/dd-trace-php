--TEST--
Request aborted in request init still sends its trace with auto-flush enabled
--DESCRIPTION--
When a request is blocked during request startup, the tracer closes all spans
and force-flushes them before the request is aborted. Closing the root span
used to trigger the regular auto-flush, which drops traces while PHP is still
in request startup, so the forced flush that followed found nothing to send.
This test runs a php-cgi child that is blocked in request init, with
auto-flush disabled and enabled, and checks that a mock agent receives the
trace of the blocked request in both cases.
--SKIPIF--
<?php
require __DIR__ . "/inc/no_valgrind.php";
$cgi = getenv('TEST_PHP_CGI_EXECUTABLE');
if (!$cgi || !is_executable($cgi)) {
    die('skip php-cgi is not available');
}
?>
--ENV--
DD_TRACE_ENABLED=0
--FILE--
<?php

const SERVICE = 'appsec_startup_block_svc';

function read_http_request($conn) {
    stream_set_timeout($conn, 5);
    $head = '';
    while (strpos($head, "\r\n\r\n") === false) {
        $chunk = fread($conn, 8192);
        if ($chunk === '' || $chunk === false) {
            return null;
        }
        $head .= $chunk;
    }
    list($headers, $body) = explode("\r\n\r\n", $head, 2);
    if (stripos($headers, "Transfer-Encoding: chunked") !== false) {
        // We only need to know that the body is complete; the raw chunked
        // encoding is good enough to look for the service name.
        while (substr($body, -5) !== "0\r\n\r\n") {
            $chunk = fread($conn, 8192);
            if ($chunk === '' || $chunk === false) {
                break;
            }
            $body .= $chunk;
        }
    } elseif (preg_match('/Content-Length:\s*(\d+)/i', $headers, $m)) {
        while (strlen($body) < (int)$m[1]) {
            $chunk = fread($conn, 8192);
            if ($chunk === '' || $chunk === false) {
                break;
            }
            $body .= $chunk;
        }
    }
    $line = strtok($headers, "\r\n");
    return [$line, $body];
}

function run_blocked_request($auto_flush) {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if (!$server) {
        die("could not start mock agent: $errstr\n");
    }
    $port = parse_url('tcp://' . stream_socket_get_name($server, false), PHP_URL_PORT);

    $script = __DIR__ . '/req_abort_in_req_init_auto_flush.inc';
    $env = array_merge(getenv(), [
        'DD_TRACE_ENABLED' => '1',
        'DD_TRACE_GENERATE_ROOT_SPAN' => '1',
        'DD_TRACE_AUTO_FLUSH_ENABLED' => $auto_flush ? '1' : '0',
        'DD_TRACE_AGENT_URL' => "http://127.0.0.1:$port",
        'DD_SERVICE' => SERVICE,
        'REDIRECT_STATUS' => '1',
        'GATEWAY_INTERFACE' => 'CGI/1.1',
        'REQUEST_METHOD' => 'GET',
        'SCRIPT_FILENAME' => $script,
        'PATH_TRANSLATED' => $script,
        'REQUEST_URI' => '/blocked',
        'QUERY_STRING' => '',
    ]);

    $cmd = [
        getenv('TEST_PHP_CGI_EXECUTABLE'),
        '-n',
        '-c', php_ini_loaded_file(),
        '-d', 'extension_dir=' . ini_get('extension_dir'),
        '-d', 'extension=ddtrace.so',
        '-d', 'datadog.appsec.enabled=1',
        '-d', 'datadog.appsec.testing_abort_rinit=1',
        '-d', 'display_errors=0',
        '-d', 'log_errors=0',
    ];
    $cmdline = implode(' ', array_map('escapeshellarg', $cmd));

    $proc = proc_open($cmdline, [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, null, $env);
    if (!is_resource($proc)) {
        die("could not start php-cgi\n");
    }

    $requests = [];
    $exited_at = null;
    while (true) {
        $read = [$server];
        $w = $e = null;
        if (stream_select($read, $w, $e, 0, 100000) > 0) {
            $conn = stream_socket_accept($server, 1);
            if ($conn) {
                $req = read_http_request($conn);
                if ($req !== null) {
                    $requests[] = $req;
                }
                fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n"
                    . "Content-Length: 2\r\nConnection: close\r\n\r\n{}");
                fclose($conn);
            }
            continue;
        }
        $status = proc_get_status($proc);
        if (!$status['running']) {
            // keep serving a little longer in case a send is still in flight
            if ($exited_at === null) {
                $exited_at = microtime(true);
            } elseif (microtime(true) - $exited_at > 0.5) {
                break;
            }
        }
    }

    $stdout = stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    fclose($server);

    $blocked = strpos($stdout, '403 Forbidden') !== false
        && strpos($stdout, 'THIS SHOULD NOT BE REACHED') === false;

    $trace_sent = false;
    foreach ($requests as list($line, $body)) {
        if (strpos($line, '/traces') !== false && strpos($body, SERVICE) !== false) {
            $trace_sent = true;
        }
    }

    echo 'auto-flush ', $auto_flush ? 'on' : 'off', ': ',
        'blocked: ', $blocked ? 'yes' : 'no', ', ',
        'trace sent: ', $trace_sent ? 'yes' : 'no', "\n";
}

run_blocked_request(false);
run_blocked_request(true);

?>
--EXPECT--
auto-flush off: blocked: yes, trace sent: yes
auto-flush on: blocked: yes, trace sent: yes
