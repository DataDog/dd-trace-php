<?php
// Helpers for the curl_downstream_*.phpt tests: a local HTTP server
// (curl_server.php in a child process) and a stable rendering of
// datadog\appsec\testing\curl_downstream_dump().

use function datadog\appsec\testing\curl_downstream_dump;

// Observe every transfer and keep its bodies, for curl_downstream_dump(),
// even those no WAF call needs (which production does not keep).
datadog\appsec\testing\curl_downstream_collect_all(true);

// With $log, the server appends the requests it receives to that file.
function cd_server_start($log = null)
{
    global $cd_server_proc, $cd_server_pipes, $cd_server_base;
    $php = PHP_BINARY;
    if (PHP_SAPI !== 'cli' && is_executable(dirname($php) . '/php')) {
        // tests with --EXPECTHEADERS-- run under php-cgi
        $php = dirname($php) . '/php';
    }
    $cmd = [$php, '-n', __DIR__ . '/curl_server.php'];
    if ($log !== null) {
        $cmd[] = $log;
    }
    if (PHP_VERSION_ID < 70400) {
        // a string goes through sh -c: exec, so that proc_terminate() reaches
        // the server and not just the shell (an orphaned server keeps the
        // test's stderr open, and run-tests waits for it to exit)
        $cmd = 'exec ' . implode(' ', array_map('escapeshellarg', $cmd));
    }
    $cd_server_proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w']],
        $cd_server_pipes, null, []);
    if (!$cd_server_proc) {
        die("could not start the test server\n");
    }
    $line = fgets($cd_server_pipes[1]);
    if (!preg_match('/^PORT (\d+)$/', trim((string)$line), $m)) {
        die("test server did not start: " . var_export($line, true) . "\n");
    }
    $cd_server_base = "http://127.0.0.1:{$m[1]}";
    register_shutdown_function('cd_server_stop');
    return $cd_server_base;
}

function cd_server_stop()
{
    global $cd_server_proc, $cd_server_pipes;
    if (!$cd_server_proc) {
        return;
    }
    fclose($cd_server_pipes[0]);
    fclose($cd_server_pipes[1]);
    proc_terminate($cd_server_proc);
    proc_close($cd_server_proc);
    $cd_server_proc = null;
}

// curl_close() with its deprecation checked, not hidden: PHP 8.5 deprecates
// the function (it has no effect since 8.0), and no other version does. The
// deprecation is raised exactly there, or this prints what happened. The
// close itself is the call under test, so it is still made.
function cd_close($ch)
{
    $seen = [];
    set_error_handler(function ($no, $msg) use (&$seen) {
        $seen[] = [$no, $msg];
        return true;
    });
    curl_close($ch);
    restore_error_handler();
    $want = PHP_VERSION_ID >= 80500
        ? [[E_DEPRECATED, 'Function curl_close() is deprecated since 8.5, as it has no effect since PHP 8.0']]
        : [];
    if ($seen !== $want) {
        echo "unexpected diagnostics from curl_close(): ", json_encode($seen), "\n";
    }
}

// headers whose values change between runs, or that the tracer adds
function cd_unstable_header($name)
{
    return in_array($name, ['host', 'user-agent', 'traceparent', 'tracestate'], true)
        || strpos($name, 'x-datadog-') === 0;
}

function cd_headers(array $headers)
{
    $out = [];
    ksort($headers);
    foreach ($headers as $name => $values) {
        if (!cd_unstable_header($name)) {
            $out[] = $name . '=' . implode('|', $values);
        }
    }
    return '[' . implode(', ', $out) . ']';
}

function cd_body($body)
{
    if ($body === null) {
        return 'null';
    }
    if (strlen($body) > 60) {
        return sprintf('%d bytes md5=%s', strlen($body), md5($body));
    }
    return var_export($body, true);
}

function cd_yn($b)
{
    return $b ? 'yes' : 'no';
}

// The collector of the last transfer of $ch: the PREREQ calls seen (hops),
// the head of the last hop and the last hop's body copy ("null" when bodies
// are not copied). The request as sent is not observed. A body is complete
// when libcurl reported the transfer done without error: curl_exec(), or a
// DONE message read with curl_multi_info_read() (not known for a transfer
// only removed from its multi handle).
function cd_dump($ch, $label = '')
{
    $d = curl_downstream_dump($ch);
    if ($label !== '') {
        echo "== $label\n";
    }
    if ($d === null) {
        echo "not tracked\n";
        return;
    }
    echo 'observed=', cd_yn($d['observed']), ' reason=', var_export($d['reason'], true),
        ' installed=', cd_yn($d['installed']), ' hops=', $d['hops'],
        $d['hops_truncated'] ? ' (truncated)' : '', $d['oom'] ? ' (oom)' : '', "\n";
    $rs = $d['response'];
    if ($rs === null) {
        return;
    }
    echo '  resp: ', var_export($rs['status'], true), ' interim=', $rs['interim_responses'],
        ' head_complete=', cd_yn($rs['head_complete']), "\n";
    echo '  resp headers: ', cd_headers($rs['headers']), "\n";
    echo '  resp body: ', cd_body($rs['body']), ' complete=', cd_yn($rs['body_complete']),
        ' truncated=', cd_yn($rs['body_truncated']), "\n";
}

function cd_options($ch)
{
    $d = curl_downstream_dump($ch);
    if ($d === null) {
        echo "not tracked\n";
        return;
    }
    $o = $d['options'];
    if (isset($o['url'])) {
        $o['url'] = preg_replace('/:\d+/', ':PORT', $o['url']);
    }
    foreach ($o as $k => $v) {
        if (is_array($v)) {
            $parts = [];
            foreach ($v as $vk => $vv) {
                $parts[] = var_export($vk, true) . '=>' . var_export($vv, true);
            }
            $v = '[' . implode(', ', $parts) . ']';
        } else {
            $v = var_export($v, true);
        }
        echo "  $k: $v\n";
    }
}
