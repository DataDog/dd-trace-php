<?php
// Helpers for the curl_downstream_identity_*.phpt tests: what the app's
// CURLOPT_HEADERFUNCTION / CURLOPT_WRITEFUNCTION get, recorded in a file and
// printed in a stable form.

require_once __DIR__ . '/curl_eval.php';

function ch_log_file()
{
    global $ch_log;
    if (!$ch_log) {
        $ch_log = tempnam(sys_get_temp_dir(), 'chlog');
        register_shutdown_function(function () {
            global $ch_log;
            @unlink($ch_log);
        });
    }
    return $ch_log;
}

function ch_log($line)
{
    file_put_contents(ch_log_file(), "$line\n", FILE_APPEND);
}

// Takes (and clears) the recorded calls
function ch_take()
{
    $log = ch_log_file();
    $lines = file($log, FILE_IGNORE_NEW_LINES);
    file_put_contents($log, '');
    return $lines ?: [];
}

// Records the calls of the app's callbacks of $ch, labelled $label. $opts:
//   'header' => false: no HEADERFUNCTION; 'write' => false: no WRITEFUNCTION
//   'stack' => true: each call also records the functions calling it
//   'abort' => 'h' or 'w': that callback returns 0 (aborts the transfer)
function ch_record($ch, $label, array $opts = [])
{
    $opts += ['header' => true, 'write' => true, 'stack' => false, 'abort' => null];
    $rec = function ($kind, $c, $data) use ($ch, $label, $opts) {
        $line = "$label $kind " . base64_encode($data);
        if ($c !== $ch) {
            $line .= ' (other handle!)';
        }
        if ($opts['stack']) {
            // [0] is this closure, [1] the HEADERFUNCTION/WRITEFUNCTION
            $names = [];
            foreach (array_slice(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), 1, 3) as $f) {
                // PHP 8.4+: {closure:file:line}
                $names[] = preg_replace('/^\{closure.*\}$/', '{closure}', $f['function']);
            }
            $line .= ' stack=' . implode('<', $names);
        }
        ch_log($line);
        return $opts['abort'] === $kind ? 0 : strlen($data);
    };
    if ($opts['header']) {
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($c, $l) use ($rec) {
            return $rec('h', $c, $l);
        });
    }
    if ($opts['write']) {
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($c, $d) use ($rec) {
            return $rec('w', $c, $d);
        });
    }
}

// What the app gets, summarised so that it can be compared between runs with
// and without the layer: header lines in order, body length and md5, the
// stacks the callbacks were called with. With $boundaries, also the size of
// each call of the WRITEFUNCTION (only for small or paced bodies: above
// libcurl's 16 KiB buffer they depend on the kernel's segmentation, G10.14).
function ch_summary($label, array $lines, $boundaries = true)
{
    $headers = [];
    $body = '';
    $sizes = [];
    $stacks = [];
    foreach ($lines as $l) {
        if (!preg_match('/^(\S+) ([hw]) (\S*)(?:.* stack=(\S+))?/', $l, $m)) {
            continue;
        }
        if ($m[2] === 'h') {
            $headers[] = trim(base64_decode($m[3]));
        } else {
            $body .= base64_decode($m[3]);
            $sizes[] = strlen(base64_decode($m[3]));
        }
        if (isset($m[4])) {
            $stacks[$m[4]] = true;
        }
    }
    echo "$label: headers: ", implode(' | ', array_filter($headers, 'strlen')), "\n";
    echo "$label: blank header lines: ", count($headers) - count(array_filter($headers, 'strlen')), "\n";
    echo "$label: body: ", strlen($body), ' ', md5($body), "\n";
    if ($boundaries) {
        echo "$label: write calls: ", implode(',', $sizes), "\n";
    }
    if ($stacks) {
        echo "$label: called from: ", implode(', ', array_keys($stacks)), "\n";
    }
}

// What the app's HEADERFUNCTION/WRITEFUNCTION get for various responses;
// printed the same way whether or not the layer is on
// (curl_downstream_identity_{on,off}.phpt).

// A curl_error() message without what varies between runs: ports and times;
// and between libcurl versions: for a refused connection 7.79.1 ends it with
// strerror(ECONNREFUSED), 7.88.1 with "Couldn't connect to server" (the
// errno, CURLE_COULDNT_CONNECT, is printed apart and is the same)
function ch_error($ch)
{
    return preg_replace(['/port \d+/', '/\d+ ms/', '/:\d+/', '/: Connection refused$/'],
        ['port PORT', 'N ms', ':PORT', ": Couldn't connect to server"], curl_error($ch));
}

// The HTTP/2 cleartext server of inc/h2c_push_server.php (it pushes nothing
// unless the multi handle has a CURLMOPT_PUSHFUNCTION); its base URL
function ch_h2c_server_start()
{
    global $ch_h2_proc, $ch_h2_pipes;
    $cmd = [PHP_BINARY, '-n', __DIR__ . '/h2c_push_server.php'];
    if (PHP_VERSION_ID < 70400) {
        $cmd = 'exec ' . implode(' ', array_map('escapeshellarg', $cmd));
    }
    $ch_h2_proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $ch_h2_pipes, null, []);
    $line = fgets($ch_h2_pipes[1]);
    if (!preg_match('/^PORT (\d+)$/', trim((string)$line), $m)) {
        die("h2c server did not start: " . var_export($line, true) . "\n");
    }
    register_shutdown_function(function () {
        global $ch_h2_proc, $ch_h2_pipes;
        fclose($ch_h2_pipes[0]);
        fclose($ch_h2_pipes[1]);
        proc_terminate($ch_h2_proc);
        proc_close($ch_h2_proc);
    });
    return "http://127.0.0.1:{$m[1]}";
}

// The options of the HTTPS case: through the test server acting as a proxy,
// which answers over TLS (a self-signed certificate) inside the tunnel
function ch_https_opts($base)
{
    return [CURLOPT_PROXY => $base, CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0];
}

// Runs the transfer of $ch the way $via says: curl_exec() (null), or through
// a curl_multi handle ('multi'); returns what curl_exec() would have
function ch_run($ch, $via)
{
    if ($via !== 'multi') {
        return curl_exec($ch);
    }
    $mh = curl_multi_init();
    curl_multi_add_handle($mh, $ch);
    $result = null;
    do {
        $st = curl_multi_exec($mh, $running);
        while ($info = curl_multi_info_read($mh)) {
            $result = $info['result'];
        }
        if ($running) {
            curl_multi_select($mh, 0.2);
        }
    } while ($running && $st === CURLM_OK);
    $r = curl_multi_getcontent($ch);
    curl_multi_remove_handle($mh, $ch);
    curl_multi_close($mh);
    echo "  multi result=", var_export($result, true), "\n";
    return $r === null ? ($result === 0) : $r;
}

// What the app's HEADERFUNCTION/WRITEFUNCTION get for various responses;
// printed the same way whether or not the layer is on
// (curl_downstream_identity_{on,off}.phpt). Each case: [path or URL, options,
// ch_record() options, extra]; extra: 'via' => 'copy' | 'clone' | 'multi'
// (the handle run is a copy or a clone of the one configured, or the
// transfer runs in a multi handle), 'big' => true (no write boundaries).

// $after: called after each transfer
function ch_identity_cases($base, $after = null)
{
    $h2c = ch_h2c_server_start();
    $cases = [
        'json' => ['/json', []],
        'chunked' => ['/chunked', []],
        'gzip-raw' => ['/gzip', []],
        'gzip-decoded' => ['/gzip', [CURLOPT_ENCODING => '']],
        'redirect' => ['/redirect', [CURLOPT_FOLLOWLOCATION => true]],
        'redirect-nofollow' => ['/redirect', []],
        'continue' => ['/len', [CURLOPT_POSTFIELDS => 'a=b',
            CURLOPT_HTTPHEADER => ['Expect: 100-continue']]],
        'close' => ['/close', []],
        'big' => ['/big?n=200000', [], [], ['big' => true]],
        'xml' => ['/xml', []],
        'header-in-body' => ['/json', [CURLOPT_HEADER => true]],
        'header-only' => ['/chunked', [CURLOPT_RETURNTRANSFER => true], ['write' => false]],
        'write-only' => ['/chunked', [], ['header' => false]],
        'status-204' => ['/status/204', []],
        'head' => ['/json', [CURLOPT_NOBODY => true]],
        'paced' => ['/slow', []],
        'abort-write' => ['/chunked', [], ['abort' => 'w']],
        'abort-header' => ['/json', [], ['abort' => 'h']],
        'connection-error' => ['http://127.0.0.1:1/json', []],
        'copy' => ['/chunked', [], [], ['via' => 'copy']],
        'clone' => ['/redirect', [CURLOPT_FOLLOWLOCATION => true], [], ['via' => 'clone']],
        'multi' => ['/chunked', [], [], ['via' => 'multi']],
        'multi-redirect' => ['/redirect', [CURLOPT_FOLLOWLOCATION => true], [], ['via' => 'multi']],
        'https' => ['https://example.test/json', ch_https_opts($base)],
        'h2c' => ["$h2c/main", [CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE]],
    ];
    $handles = [];
    foreach ($cases as $label => $case) {
        $extra = (isset($case[3]) ? $case[3] : []) + ['via' => null, 'big' => false];
        $url = strpos($case[0], '/') === 0 ? $base . $case[0] : $case[0];
        $ch = curl_init($url);
        curl_setopt_array($ch, $case[1]);
        if ($extra['via'] === 'copy') {
            $ch = curl_copy_handle($ch);
        } elseif ($extra['via'] === 'clone') {
            // PHP 7: handles are resources, which cannot be cloned
            $ch = PHP_VERSION_ID >= 80000 ? clone $ch : curl_copy_handle($ch);
        }
        ch_record($ch, $label, (isset($case[2]) ? $case[2] : []) + ['stack' => true]);
        $r = ch_run($ch, $extra['via']);
        echo "$label: curl_exec: ", is_string($r) ? 'string ' . md5($r) : var_export($r, true),
            ' errno=', curl_errno($ch), ' error="', ch_error($ch), "\"\n";
        ch_summary($label, ch_take(), !$extra['big']);
        $handles[$label] = $ch;
        if ($after) {
            $after();
        }
    }
    return $handles;
}
