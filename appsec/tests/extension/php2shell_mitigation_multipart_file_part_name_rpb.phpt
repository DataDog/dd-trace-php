--TEST--
PHP2Shell mitigation (CVE-2026-109618): a soft-limit crossing while a file part with a large name is registered by request_parse_body() (PHP 8.4+), with opcache, stays within the multipart hard limit (k = 11) and ends in the deferred fatal
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_unless(80400);
p2s_skip_if_patched();
p2s_skip_unless_cgi();
p2s_skip_if_no_zend_mm();
p2s_skip_unless_opcache();
--INI--
extension=ddtrace.so
--FILE--
<?php
// See inc/php2shell_file_part_name.php. The body is sent with PUT, so request
// startup leaves it alone, and the child parses it with request_parse_body()
// inside that function's window.
require __DIR__ . '/inc/php2shell_file_part_name.php';
p2s_fpn_case(p2s_cgi_env(__DIR__ . '/inc/php2shell_child_rpb.php', 'PUT'),
    '/^SCRIPT RAN post_fields=\d+ files=1 /m');
--EXPECT--
precondition: peak_above_h6=true peak_below_h11=true
outcome=deferred status=255 continued=false
