--TEST--
PHP2Shell mitigation (CVE-2026-109618): the on toggle (via env) forces activation
--INI--
extension=ddtrace.so
memory_limit=128M
--ENV--
DD_APPSEC_PHP2SHELL_MITIGATION=on
--CGI--
--FILE--
<?php
// Runs under php-cgi (--CGI--): the mitigation is off under the cli SAPI.
// The DD_APPSEC_PHP2SHELL_MITIGATION env toggle is read lazily, after
// per-worker config is applied. "on" activates the mitigation on patched
// runtimes too, so this test does not skip there.
require __DIR__ . '/inc/php2shell_cgi.php';
$minfo = p2s_minfo();
echo "mode=", $minfo[P2S_ROW_MODE], "\n";
echo "state=", $minfo[P2S_ROW_STATE], "\n";
--EXPECT--
mode=on
state=Active
