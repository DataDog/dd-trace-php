<?php
// Shared helpers for the PHP2Shell mitigation tests
// (php2shell_mitigation_*.phpt): skip conditions, the MINFO reader, the
// outcome classifier and the php-cgi child runner. Must stay valid on PHP 7.0.
//
// Most tests run php-cgi children: a request body sized at runtime (so the
// test can calibrate against the memory this build actually uses), several
// requests served by one process (php-cgi -T), or a test-only extension
// loaded after ddappsec (p2s_stub.so, built from tests/extension/p2s_stub/).
//
// A child is started with this test's own configuration: the harness passes
// the php-cgi binary in TEST_PHP_CGI_EXECUTABLE and the -n/-c/-d options of
// the current test (including its --INI-- section) in TEST_PHP_EXTRA_ARGS.
// Extra -d settings appended here override those.
//
// The mitigation is off under the cli, phpdbg and embed SAPIs, so no test
// exercises it in the CLI test process: a test whose scenario needs no child
// of its own runs under php-cgi (a --CGI-- section, or --POST--/--COOKIE--).

const P2S_CHILD_SCRIPT = __DIR__ . '/php2shell_child.php';
const P2S_MIB = 1048576;
const P2S_STUB = 'p2s_stub.so';
const P2S_ROW_STATE = 'PHP2Shell mitigation (CVE-2026-109618/109619)';
const P2S_ROW_MODE = 'PHP2Shell mitigation mode';
const P2S_ROW_PATCHED = 'PHP2Shell patched runtime';

// ---- skip conditions (call from --SKIPIF--) ---------------------------------

// On a runtime that carries the upstream fix, the mitigation is inactive in
// mode auto. Tests that force DD_APPSEC_PHP2SHELL_MITIGATION=on do not call
// this.
function p2s_skip_if_patched() {
    $minfo = p2s_minfo();
    if (!isset($minfo[P2S_ROW_PATCHED])) {
        die("skip: ddappsec MINFO has no '" . P2S_ROW_PATCHED . "' row");
    }
    if ($minfo[P2S_ROW_PATCHED] === 'Yes') {
        die("skip: patched runtime; the mitigation is inactive by design");
    }
}

function p2s_skip_unless_cgi() {
    if (!p2s_cgi_available()) {
        die("skip: php-cgi not available");
    }
}

// Under USE_ZEND_ALLOC=0 nothing is armed: memory_limit is neither accounted
// nor enforced by the malloc-backed heap.
function p2s_skip_if_no_zend_mm() {
    if (getenv('USE_ZEND_ALLOC') === '0') {
        die("skip: USE_ZEND_ALLOC=0: no zend_mm accounting, nothing is armed");
    }
}

function p2s_skip_unless($version_id) {
    if (PHP_VERSION_ID < $version_id) {
        die("skip: needs PHP_VERSION_ID >= $version_id");
    }
}

function p2s_skip_unless_ext($name) {
    if (!extension_loaded($name)) {
        die("skip: the $name extension is not loaded");
    }
}

// Skip unless the php-cgi children load extension $name (php-cgi -m).
function p2s_skip_unless_cgi_ext($name) {
    $r = p2s_cgi_run([], ['-m']);
    if (!preg_match('/^' . preg_quote($name, '/') . '$/mi', $r['out'])) {
        die("skip: the $name extension is not loaded in php-cgi");
    }
}

function p2s_skip_unless_stub() {
    if (!is_file(ini_get('extension_dir') . '/' . P2S_STUB)) {
        die("skip: " . P2S_STUB . " is not built (target p2s_stub)");
    }
}

// Below PHP 8.5, opcache is a shared zend_extension, which p2s_opcache_ini()
// loads in a child. From 8.5 on it is built in and always loaded.
function p2s_skip_unless_opcache() {
    if (PHP_VERSION_ID < 80500 && !is_file(ini_get('extension_dir') . '/opcache.so')) {
        die("skip: opcache.so is not available");
    }
}

// The INI settings that load opcache in a php-cgi child (none from PHP 8.5).
function p2s_opcache_ini() {
    return PHP_VERSION_ID < 80500 ? ['zend_extension' => 'opcache.so'] : [];
}

// ---- MINFO ------------------------------------------------------------------

// The ddappsec MINFO rows as [name => value]. ReflectionExtension::info()
// prints text on the CLI and HTML under php-cgi; both are parsed.
function p2s_minfo() {
    if (!extension_loaded('ddappsec')) {
        return [];
    }
    $ext = new ReflectionExtension('ddappsec');
    ob_start();
    $ext->info();
    return p2s_info_rows(ob_get_clean());
}

// The [name => value] rows of phpinfo() output: text (CLI) or HTML (php-cgi).
function p2s_info_rows($out) {
    $rows = [];
    if (strpos($out, '<tr') !== false) {
        preg_match_all('~<tr><td class="e">(.*?)</td><td class="v">(.*?)</td></tr>~s',
            $out, $m, PREG_SET_ORDER);
        foreach ($m as $row) {
            $rows[trim(html_entity_decode(strip_tags($row[1])))] =
                trim(html_entity_decode(strip_tags($row[2])));
        }
    } else {
        foreach (explode("\n", $out) as $line) {
            if (preg_match('/^(.*?) => (.*)$/', $line, $mm)) {
                $rows[trim($mm[1])] = trim($mm[2]);
            }
        }
    }
    return $rows;
}

// The value of the PHP2Shell hook row whose name contains $needle (e.g.
// 'treat_data', 'input_filter', 'rfc1867', 'deactivate', 'request_parse_body',
// 'read_post'), or null.
function p2s_hook_row(array $minfo, $needle) {
    foreach ($minfo as $name => $value) {
        if (strpos($name, 'PHP2Shell') === 0 && strpos($name, $needle) !== false
                && substr($name, -5) === ' hook') {
            return $value;
        }
    }
    return null;
}

function p2s_minfo_active() {
    $minfo = p2s_minfo();
    return isset($minfo[P2S_ROW_STATE]) && $minfo[P2S_ROW_STATE] === 'Active';
}

// The hard limit outside the windows, at the request level, for the soft limit
// $soft: max(L, S + 8 MiB), with L upstream's limit (2S below 1 GiB, S + 1 GiB
// from 1 GiB).
function p2s_request_hard_limit($soft) {
    $floor = $soft < 1024 * P2S_MIB ? 2 * $soft : $soft + 1024 * P2S_MIB;
    return max($floor, $soft + 8 * P2S_MIB);
}

// ---- outcomes ---------------------------------------------------------------

function p2s_deferred_msg($soft) {
    return "Allowed memory size of $soft bytes exhausted (Datadog AppSec "
        . "PHP2Shell mitigation deferred this to avoid heap corruption)";
}

// Classify a child run: 'hard' (exit status 137 or our stderr line), 'deferred'
// (the deferred fatal for memory_limit $soft), 'native' (the engine's own
// out-of-memory error), 'ran' (the child script printed SCRIPT RAN) or
// 'other'. With $soft null, any memory_limit value matches.
function p2s_outcome(array $r, $soft = null) {
    $all = $r['out'] . $r['err'];
    $size = $soft === null ? '\d+' : (string)$soft;
    if ($r['status'] === 137 || strpos($all, 'hard memory limit') !== false) {
        return 'hard';
    }
    if (preg_match('/Allowed memory size of ' . $size . ' bytes exhausted \(Datadog AppSec PHP2Shell mitigation deferred/', $all)) {
        return 'deferred';
    }
    if (preg_match('/Allowed memory size of ' . $size . ' bytes exhausted/', $all)) {
        return 'native';
    }
    if (strpos($all, 'SCRIPT RAN') !== false) {
        return 'ran';
    }
    return 'other';
}

// The dropped-variable count of the deferred-fatal log line, or null.
function p2s_dropped(array $r) {
    return preg_match('/dropped (\d+) request variables/', $r['out'] . $r['err'], $m)
        ? (int)$m[1] : null;
}

// Diagnostics for an unexpected result: the exit status and the start of the
// output.
function p2s_dump(array $r, $what = '') {
    echo "  $what status={$r['status']}\n", substr($r['out'] . $r['err'], 0, 2000), "\n";
}

// The SCRIPT RAN lines that php2shell_child.php printed, one per request, as
// [key => value] (all values are strings; last_error is JSON). $r is a child
// run result or an HTTP response.
function p2s_child_lines($r) {
    preg_match_all('/^SCRIPT RAN (.*?) last_error=(.*?)\r?$/m', is_array($r) ? $r['out'] : $r,
        $m, PREG_SET_ORDER);
    $lines = [];
    foreach ($m as $line) {
        $kv = [];
        foreach (explode(' ', $line[1]) as $pair) {
            $p = explode('=', $pair, 2);
            $kv[$p[0]] = isset($p[1]) ? $p[1] : '';
        }
        $kv['last_error'] = $line[2];
        $lines[] = $kv;
    }
    return $lines;
}

// The peak a child script reported (peak=N), or null.
function p2s_peak(array $r) {
    return preg_match('/^(?:SCRIPT RAN|PARSED).* peak=(\d+)/m', $r['out'], $m) ? (int)$m[1] : null;
}

// Run the child once with memory_limit=256M and return the peak it reports.
// Prints a diagnostic and exits the test when the run fails.
function p2s_calibrate(array $ini, array $env, $body = null, array $opts = []) {
    $r = p2s_cgi_run(['memory_limit' => '256M'] + $ini, [], $env, $body, $opts);
    $peak = p2s_peak($r);
    if ($peak === null) {
        echo "calibration failed\n";
        p2s_dump($r);
        exit;
    }
    return $peak;
}

// ---- php-cgi children -------------------------------------------------------

function p2s_cgi_available() {
    $cgi = getenv('TEST_PHP_CGI_EXECUTABLE');
    return $cgi !== false && $cgi !== '' && is_executable($cgi);
}

// The CGI environment of a request for $script. $extra overrides it.
function p2s_cgi_env($script = P2S_CHILD_SCRIPT, $method = 'GET', array $extra = []) {
    return $extra + [
        'REQUEST_METHOD' => $method,
        'SCRIPT_FILENAME' => $script,
        'REDIRECT_STATUS' => '1',
        'GATEWAY_INTERFACE' => 'CGI/1.1',
    ];
}

// A multipart/form-data body. $parts: list of [name, value] for a field or
// [name, value, filename] for a file part.
function p2s_multipart_body(array $parts, $boundary) {
    $buf = '';
    foreach ($parts as $p) {
        $buf .= "--$boundary\r\nContent-Disposition: form-data; name=\"{$p[0]}\"";
        if (isset($p[2])) {
            $buf .= "; filename=\"{$p[2]}\"\r\nContent-Type: application/octet-stream";
        }
        $buf .= "\r\n\r\n{$p[1]}\r\n";
    }
    return $buf . "--$boundary--\r\n";
}

function p2s_multipart_env($boundary) {
    return ['CONTENT_TYPE' => "multipart/form-data; boundary=$boundary"];
}

// Run php-cgi. $ini: extra INI settings; $args: extra command-line arguments;
// $env: extra environment; $body: if not null, sent as a urlencoded POST body
// (CGI mode, script taken from SCRIPT_FILENAME; $env may override the content
// type). $opts['ext_after']: load p2s_stub.so after every extension of
// TEST_PHP_EXTRA_ARGS (so after ddappsec); $opts['ext_before']: load it before
// them (so its RSHUTDOWN runs after ddappsec's). $opts['stdout']: a file to
// send the child's stdout to instead (e.g. /dev/full; 'out' is then empty).
// $opts['binary']: run this executable instead of php-cgi (e.g.
// TEST_PHP_EXECUTABLE, the CLI; the script then goes in $args). Returns
// ['out' => stdout, 'err' => stderr, 'status' => exit status].
function p2s_cgi_run(array $ini = [], array $args = [], array $env = [], $body = null, array $opts = []) {
    $binary = isset($opts['binary']) ? $opts['binary'] : getenv('TEST_PHP_CGI_EXECUTABLE');
    $cmd = escapeshellarg($binary) . ' ';
    if (!empty($opts['ext_before'])) {
        $cmd .= '-d ' . escapeshellarg('extension=' . P2S_STUB) . ' ';
    }
    $cmd .= getenv('TEST_PHP_EXTRA_ARGS');
    if (!empty($opts['ext_after'])) {
        $cmd .= ' -d ' . escapeshellarg('extension=' . P2S_STUB);
    }
    $cmd .= ' -d display_errors=1 -d html_errors=0 -d log_errors=0';
    foreach ($ini as $k => $v) {
        $cmd .= ' -d ' . escapeshellarg("$k=$v");
    }
    $cmd .= ' -q';
    foreach ($args as $a) {
        $cmd .= ' ' . escapeshellarg($a);
    }

    $child_env = p2s_base_env();
    if ($body !== null) {
        $child_env['REQUEST_METHOD'] = 'POST';
        $child_env['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        $child_env['CONTENT_LENGTH'] = (string)strlen($body);
        $child_env['SCRIPT_FILENAME'] = P2S_CHILD_SCRIPT;
        $child_env['REDIRECT_STATUS'] = '1';
        $child_env['GATEWAY_INTERFACE'] = 'CGI/1.1';
    }
    foreach ($env as $k => $v) {
        $child_env[$k] = $v;
    }

    // stdout/stderr go to files, so a large stdin write can never dead-lock
    // against a child blocked on a full output pipe.
    $out_file = tempnam(sys_get_temp_dir(), 'p2s_out');
    $err_file = tempnam(sys_get_temp_dir(), 'p2s_err');
    $proc = proc_open($cmd, [
        0 => ['pipe', 'r'],
        1 => ['file', isset($opts['stdout']) ? $opts['stdout'] : $out_file, 'w'],
        2 => ['file', $err_file, 'w'],
    ], $pipes, null, $child_env);
    if (!is_resource($proc)) {
        return ['out' => '', 'err' => "proc_open failed: $cmd", 'status' => -1];
    }
    if ($body !== null) {
        for ($off = 0, $len = strlen($body); $off < $len; $off += $n) {
            // A child that ends in request startup stops reading its stdin.
            $n = @fwrite($pipes[0], substr($body, $off, 65536));
            if ($n === false || $n === 0) {
                break;
            }
        }
    }
    fclose($pipes[0]);
    $status = proc_close($proc);
    $res = [
        'out' => (string)file_get_contents($out_file),
        'err' => (string)file_get_contents($err_file),
        'status' => $status,
    ];
    @unlink($out_file);
    @unlink($err_file);
    return $res;
}

// This process's environment without the CGI request variables (possibly
// empty) that run-tests leaves in it, so they do not leak into a child's
// request. getenv() without arguments needs PHP 7.1; $_ENV is populated under
// the default variables_order (EGPCS) these tests run with.
function p2s_base_env() {
    $env = PHP_VERSION_ID >= 70100 ? getenv() : $_ENV;
    foreach (['REQUEST_METHOD', 'CONTENT_TYPE', 'CONTENT_LENGTH',
            'SCRIPT_FILENAME', 'QUERY_STRING', 'PATH_TRANSLATED',
            'REDIRECT_STATUS', 'GATEWAY_INTERFACE', 'HTTP_CONTENT_ENCODING',
            'PATH_INFO', 'SCRIPT_NAME', 'REQUEST_URI', 'HTTP_COOKIE'] as $k) {
        unset($env[$k]);
    }
    return $env;
}

// ---- built-in web server (php -S) -------------------------------------------
// The only SAPI the tests can drive that keeps serving after a request whose
// startup failed (php-cgi and FPM workers exit), so one process can serve a
// deferred request followed by normal ones. The document root is inc/.

// Start php -S with this test's configuration plus $ini, and $env on top of
// p2s_base_env(). Returns ['proc', 'port', 'log'] or ends the test.
function p2s_server_start(array $ini, array $env = []) {
    for ($attempt = 0; $attempt < 20; $attempt++) {
        $port = mt_rand(20000, 60000);
        $cmd = escapeshellarg(getenv('TEST_PHP_EXECUTABLE')) . ' '
            . getenv('TEST_PHP_EXTRA_ARGS')
            . ' -d display_errors=1 -d html_errors=0 -d log_errors=0';
        foreach ($ini as $k => $v) {
            $cmd .= ' -d ' . escapeshellarg("$k=$v");
        }
        $cmd .= ' -S ' . escapeshellarg("127.0.0.1:$port") . ' -t ' . escapeshellarg(__DIR__);
        $log = tempnam(sys_get_temp_dir(), 'p2s_srv');
        $proc = proc_open('exec ' . $cmd, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $log, 'w'],
            2 => ['file', $log, 'a'],
        ], $pipes, null, $env + p2s_base_env());
        for ($i = 0; $i < 100; $i++) {
            $st = proc_get_status($proc);
            if (!$st['running']) {
                break;
            }
            $s = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($s) {
                fclose($s);
                return ['proc' => $proc, 'port' => $port, 'log' => $log];
            }
            usleep(100000);
        }
        proc_terminate($proc);
        proc_close($proc);
        @unlink($log);
    }
    die("could not start the built-in web server\n");
}

// Stop the server. Returns ['was_running' => bool, 'log' => its output].
function p2s_server_stop(array $srv) {
    $st = proc_get_status($srv['proc']);
    $running = $st['running'];
    if ($running) {
        proc_terminate($srv['proc']);
    }
    proc_close($srv['proc']);
    $log = (string)file_get_contents($srv['log']);
    @unlink($srv['log']);
    return ['was_running' => $running, 'log' => $log];
}

// Send one HTTP/1.0 request for $script, a file of inc/ (with query string
// $query, if not empty) and return the raw response. A non-null $body is sent
// with $content_type.
function p2s_http(array $srv, $body = null, $content_type = 'application/x-www-form-urlencoded',
                  array $headers = [], $query = '', $script = 'php2shell_child.php') {
    $s = fsockopen('127.0.0.1', $srv['port'], $errno, $errstr, 5);
    if (!$s) {
        return "CONNECT FAILED: $errstr";
    }
    stream_set_timeout($s, 60);
    $req = ($body === null ? 'GET' : 'POST') . " /$script"
        . ($query !== '' ? "?$query" : '') . " HTTP/1.0\r\nHost: 127.0.0.1\r\n";
    foreach ($headers as $h) {
        $req .= "$h\r\n";
    }
    if ($body !== null) {
        $req .= "Content-Type: $content_type\r\nContent-Length: " . strlen($body) . "\r\n";
    }
    fwrite($s, "$req\r\n");
    for ($off = 0, $len = strlen((string)$body); $off < $len; $off += $n) {
        $n = @fwrite($s, substr($body, $off, 65536));
        if ($n === false || $n === 0) {
            break;
        }
    }
    $resp = (string)stream_get_contents($s);
    fclose($s);
    return $resp;
}
