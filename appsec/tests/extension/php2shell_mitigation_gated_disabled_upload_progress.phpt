--TEST--
PHP2Shell mitigation (CVE-2026-109619 C): with appsec explicitly disabled, session upload progress stays on during the startup POST parse
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_if_patched();
p2s_skip_unless_ext('session');
--INI--
extension=ddtrace.so
datadog.appsec.enabled=0
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
--COOKIE--
PHPSESSID=p2sgatedsid
--POST_RAW--
Content-Type: multipart/form-data; boundary=----p2sgated
------p2sgated
Content-Disposition: form-data; name="PHP_SESSION_UPLOAD_PROGRESS"

mykey
------p2sgated
Content-Disposition: form-data; name="file1"; filename="file1.txt"
Content-Type: text/plain

HELLODATA
------p2sgated--
--FILE--
<?php
// Same multipart request as php2shell_mitigation_rfc1867_off, with
// datadog.appsec.enabled=0 (explicitly disabled) and the mode left at 'auto'.
// The appsec gate closes the mitigation, so upload progress stays on during
// the startup POST parse: the session handler runs before RINIT exactly as on
// a stock engine, registering $_SESSION and writing the session file.
require __DIR__ . '/inc/php2shell_cgi.php';
$minfo = p2s_minfo();
echo "state=", $minfo[P2S_ROW_STATE], "\n";
echo "mode=", $minfo[P2S_ROW_MODE], "\n";
echo "file_uploaded=", var_export(isset($_FILES['file1']) && $_FILES['file1']['error'] === UPLOAD_ERR_OK, true), "\n";
echo "SESSION_registered=", var_export(array_key_exists('_SESSION', $GLOBALS), true), "\n";
echo "session_file_written=", var_export(file_exists(__DIR__ . '/sess_p2sgatedsid'), true), "\n";
--CLEAN--
<?php
@unlink(__DIR__ . '/sess_p2sgatedsid');
--EXPECT--
state=Inactive
mode=auto
file_uploaded=true
SESSION_registered=true
session_file_written=true
