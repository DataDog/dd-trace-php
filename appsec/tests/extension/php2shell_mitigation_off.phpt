--TEST--
PHP2Shell mitigation (CVE-2026-109618): the off toggle disables it even when unpatched
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_if_patched();
--INI--
extension=ddtrace.so
memory_limit=128M
--ENV--
DD_APPSEC_PHP2SHELL_MITIGATION=off
--CGI--
--FILE--
<?php
// Runs under php-cgi (--CGI--): the mitigation is off under the cli SAPI.
require __DIR__ . '/inc/php2shell_cgi.php';
$minfo = p2s_minfo();
echo "patched=", $minfo[P2S_ROW_PATCHED], "\n";
echo "mode=", $minfo[P2S_ROW_MODE], "\n";
echo "state=", $minfo[P2S_ROW_STATE], "\n";
--EXPECT--
patched=No
mode=off
state=Inactive
