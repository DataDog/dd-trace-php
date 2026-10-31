--TEST--
PHP2Shell mitigation (CVE-2026-109619 C): with the mode off the session upload-progress handler still runs during the startup POST parse
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_if_patched();
p2s_skip_unless_ext('session');
--INI--
extension=ddtrace.so
memory_limit=128M
session.save_path={PWD}
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
--ENV--
DD_APPSEC_PHP2SHELL_MITIGATION=off
--COOKIE--
PHPSESSID=p2soffsid
--POST_RAW--
Content-Type: multipart/form-data; boundary=----p2soff
------p2soff
Content-Disposition: form-data; name="PHP_SESSION_UPLOAD_PROGRESS"

mykey
------p2soff
Content-Disposition: form-data; name="file1"; filename="file1.txt"
Content-Type: text/plain

HELLODATA
------p2soff--
--FILE--
<?php
// Control for php2shell_mitigation_upload_progress_startup_off: with the
// mitigation explicitly off, upload progress stays on during the startup POST
// parse. The session handler runs in the pre-RINIT window exactly as on a
// stock unpatched engine, so $_SESSION is registered and the session file is
// written. This shows that the absence of these side effects in the companion
// test is a real behavioural change.
require __DIR__ . '/inc/php2shell_cgi.php';
$minfo = p2s_minfo();
echo "state=", $minfo[P2S_ROW_STATE], "\n";
echo "mode=", $minfo[P2S_ROW_MODE], "\n";
echo "file_uploaded=", var_export(isset($_FILES['file1']) && $_FILES['file1']['error'] === UPLOAD_ERR_OK, true), "\n";
echo "SESSION_registered=", var_export(array_key_exists('_SESSION', $GLOBALS), true), "\n";
echo "session_file_written=", var_export(file_exists(__DIR__ . '/sess_p2soffsid'), true), "\n";
--CLEAN--
<?php
@unlink(__DIR__ . '/sess_p2soffsid');
--EXPECT--
state=Inactive
mode=off
file_uploaded=true
SESSION_registered=true
session_file_written=true
