--TEST--
PHP2Shell mitigation (CVE-2026-109618): no user code runs inside an armed request_parse_body() (PHP 8.4+): error handlers and session upload progress are held back
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_unless(80400);
p2s_skip_if_patched();
p2s_skip_unless_cgi();
p2s_skip_if_no_zend_mm();
p2s_skip_unless_ext('session');
--INI--
extension=ddtrace.so
memory_limit=128M
session.name=PHPSESSID
session.use_cookies=1
session.use_strict_mode=0
session.cache_limiter=
session.upload_progress.enabled=1
session.upload_progress.cleanup=0
session.upload_progress.prefix=upload_progress_
session.upload_progress.name=PHP_SESSION_UPLOAD_PROGRESS
session.upload_progress.freq=1
session.upload_progress.min_freq=0
file_uploads=1
--FILE--
<?php
// While request_parse_body() is armed, user code could change memory_limit
// or allocate without bound. So the window hides the user error handler and
// turns display_errors and session upload progress off; it restores all
// three afterwards. Parse warnings still reach the log (log_errors=1 sends
// them to php-cgi's stderr). Each case has a control with the mode off, where
// the user code does run, to show the oracle can fail.
//
// ini_get('session.upload_progress.enabled') is not an oracle: the toggle
// only calls the entry's on_modify, so the INI value always reads 1.
require __DIR__ . '/inc/php2shell_cgi.php';
const BOUNDARY = 'p2sBOUNDARYrpb';
const CHILD = __DIR__ . '/inc/php2shell_child_rpb_no_user_code.php';

function show($label, array $r) {
    echo "$label: ", preg_match('/^SCRIPT RAN (.*)$/m', $r['out'], $m) ? $m[1] : 'no output', "\n";
    if (!$m) {
        p2s_dump($r, $label);
    }
}

// 1. Warnings: an invalid quantity and max_input_vars=2 exceeded. The child
// calls trigger_error() after request_parse_body() returns, so handler_calls
// counts that call too: 1 with the mode on shows the handler was restored.
//
// post_fields=3, not 2, in both modes: stock PHP's add_post_vars() stores a
// variable and only then increments the count and compares it with
// max_input_vars, so the variable that exceeds the limit (c) is kept and the
// parse stops before d. Stock php-cgi without the extension gives the same 3.
$env = p2s_cgi_env(CHILD, 'PUT', ['P2S_MODE' => 'warnings']);
$body = 'a=1&b=2&c=3&d=4';
foreach (['auto', 'off'] as $mode) {
    $r = p2s_cgi_run(['log_errors' => '1'], [], $env + ['DD_APPSEC_PHP2SHELL_MITIGATION' => $mode], $body);
    show("warnings mode=$mode", $r);
    if ($mode === 'auto') {
        echo "warnings logged: invalid_quantity=", var_export(strpos($r['err'], 'Invalid quantity') !== false, true),
            " max_input_vars=", var_export(strpos($r['err'], 'Input variables exceeded 2') !== false, true),
            " displayed=", var_export(strpos($r['out'], 'Input variables exceeded') !== false, true), "\n";
        preg_match_all('/^HANDLER (.*)$/m', $r['out'], $m);
        echo "warnings handler saw: ", implode(', ', $m[1]), "\n";
    }
}

// 2. Session upload progress: the progress field, then a file part, with a
// session cookie. The upload-progress callback would open the session through
// the child's user save handler.
$body = p2s_multipart_body([
    ['PHP_SESSION_UPLOAD_PROGRESS', 'mykey'],
    ['file1', 'HELLODATA', 'file1.txt'],
], BOUNDARY);
$env = p2s_cgi_env(CHILD, 'PUT', ['P2S_MODE' => 'session', 'HTTP_COOKIE' => 'PHPSESSID=p2srpbsid']
    + p2s_multipart_env(BOUNDARY));
foreach (['auto', 'off'] as $mode) {
    show("session mode=$mode", p2s_cgi_run([], [], $env + ['DD_APPSEC_PHP2SHELL_MITIGATION' => $mode], $body));
}
--EXPECT--
warnings mode=auto: post_fields=3 files=0 handler_calls=1 session_opened=false progress_written=false
warnings logged: invalid_quantity=true max_input_vars=true displayed=false
warnings handler saw: after
warnings mode=off: post_fields=3 files=0 handler_calls=3 session_opened=false progress_written=false
session mode=auto: post_fields=1 files=1 handler_calls=0 session_opened=false progress_written=false
session mode=off: post_fields=1 files=1 handler_calls=0 session_opened=true progress_written=true
