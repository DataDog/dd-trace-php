--TEST--
PHP2Shell mitigation (CVE-2026-109619 C): session upload progress is off during the startup POST parse and restored afterwards, also after a deferred fatal
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_if_patched();
p2s_skip_if_no_zend_mm();
p2s_skip_unless_ext('session');
if (!function_exists('proc_open') || !function_exists('fsockopen'))
    die("skip: proc_open/fsockopen not available");
--INI--
extension=ddtrace.so
post_max_size=32M
session.name=PHPSESSID
session.use_cookies=1
session.use_strict_mode=0
session.cache_limiter=
session.serialize_handler=php
session.upload_progress.enabled=1
session.upload_progress.cleanup=0
session.upload_progress.prefix=upload_progress_
session.upload_progress.name=PHP_SESSION_UPLOAD_PROGRESS
session.upload_progress.freq=1
session.upload_progress.min_freq=0
file_uploads=1
upload_max_filesize=1024
--FILE--
<?php
// On an unpatched engine the session upload-progress handler runs during the
// startup request-body parse (before module RINIT): it initializes the
// session named by the cookie and writes an upload_progress_* key into it.
// Sub-mitigation C turns session upload progress off for that parse, then
// restores it.
//
// php -S serves every request in one process. The "mitigation allowed" flag
// starts true, so a parse is gated open until a request to
// php2shell_child.php?p2s_set_allowed=0 clears it through the testing probe
// (the same writer RINIT and remote config use; php -S has no per-request
// setting that could do it, and one visible at startup would install no hook
// at all). A gate-closed parse records the progress key only if the previous
// gated-open parse restored upload progress.
//
// That request is a GET, but sends a Content-Type: php -S before PHP 8.1 sets
// SG(request_info).content_type only from a request that has one, and else
// leaves it pointing at the previous request's freed header (read by
// php2shell_mitigation_status() in the child).
//
// The INI value is not an oracle: the toggle only calls the entry's
// on_modify, so ini_get() always reads 1.
require __DIR__ . '/inc/php2shell_cgi.php';
const BOUNDARY = 'p2sBOUNDARYprogress';
const SID = 'p2sprogresssid';
$dir = sys_get_temp_dir() . '/p2s_progress_' . getmypid();
@mkdir($dir);
$sess = "$dir/sess_" . SID;
$ini = ['session.save_path' => $dir, 'datadog.appsec.testing' => '1'];
$env = [];
$cookie = ['Cookie: PHPSESSID=' . SID];

function upload_body($extra_field = null) {
    $parts = [['PHP_SESSION_UPLOAD_PROGRESS', 'mykey']];
    if ($extra_field !== null) {
        $parts[] = ['f', $extra_field];
    }
    $parts[] = ['file1', 'HELLODATA', 'file1.txt'];
    return p2s_multipart_body($parts, BOUNDARY);
}

function upload($srv, $body) {
    global $sess, $cookie;
    @unlink($sess);
    $resp = p2s_http($srv, $body, 'multipart/form-data; boundary=' . BOUNDARY, $cookie);
    clearstatcache();
    $lines = p2s_child_lines($resp);
    return [
        'line' => $lines ? "post_fields={$lines[0]['post_fields']} files={$lines[0]['files']}" : 'no output',
        'key' => is_file($sess) && strpos((string)file_get_contents($sess), 'upload_progress_mykey') !== false,
        'resp' => $resp,
    ];
}

// normal: request 1 is gated open, then the gate is closed, so the next
// upload is gated closed.
$srv = p2s_server_start($ini + ['memory_limit' => '128M'], $env);
$u1 = upload($srv, upload_body());
p2s_http($srv, null, '', ['Content-Type: text/plain'], 'p2s_set_allowed=0');
$u2 = upload($srv, upload_body());
p2s_server_stop($srv);
echo "normal 1: {$u1['line']} progress_key=", var_export($u1['key'], true), "\n";
echo "normal 2: {$u2['line']} progress_key=", var_export($u2['key'], true), "\n";

// after_fatal: request 1 crosses memory_limit and ends in the deferred fatal,
// so its startup fails; request 2 (GET) closes the gate; request 3 must record
// the key.
$big = str_repeat('Z', 2000000);
$srv = p2s_server_start($ini + ['memory_limit' => '256M'], $env);
$cal = upload($srv, upload_body($big));
p2s_server_stop($srv);
$lines = p2s_child_lines($cal['resp']);
if (!$lines) {
    echo "calibration failed: {$cal['resp']}\n";
    exit;
}
$soft_mib = max(4, (int)round((int)$lines[0]['peak'] * 0.7 / P2S_MIB));
$srv = p2s_server_start($ini + ['memory_limit' => "{$soft_mib}M"], $env);
$a1 = upload($srv, upload_body($big));
$a2 = p2s_child_lines(p2s_http($srv, null, '', ['Content-Type: text/plain'], 'p2s_set_allowed=0'));
$a3 = upload($srv, upload_body());
$fin = p2s_server_stop($srv);
echo "after_fatal 1: outcome=", p2s_outcome(['out' => $a1['resp'], 'err' => $fin['log'], 'status' => 0], $soft_mib * P2S_MIB),
    " progress_key=", var_export($a1['key'], true), "\n";
echo "after_fatal 2: active=", $a2 ? $a2[0]['active'] : 'no output', "\n";
echo "after_fatal 3: {$a3['line']} progress_key=", var_export($a3['key'], true), "\n";
echo "server_survived=", var_export($fin['was_running'], true), "\n";
@unlink($sess);
@rmdir($dir);
--EXPECT--
normal 1: post_fields=1 files=1 progress_key=false
normal 2: post_fields=1 files=1 progress_key=true
after_fatal 1: outcome=deferred progress_key=false
after_fatal 2: active=false
after_fatal 3: post_fields=1 files=1 progress_key=true
server_survived=true
