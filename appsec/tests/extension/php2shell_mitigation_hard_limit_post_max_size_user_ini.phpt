--TEST--
PHP2Shell mitigation (CVE-2026-109618): the input bound of the POST body parse covers the bytes already read, when a .user.ini lowers post_max_size after the urlencoded body was read
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_if_patched();
p2s_skip_unless_cgi();
p2s_skip_if_no_zend_mm();
--INI--
extension=ddtrace.so
memory_limit=256M
--FILE--
<?php
// sapi_activate() reads a urlencoded body under the php.ini post_max_size
// (64M here); php-cgi applies .user.ini (post_max_size=1M, see
// inc/php2shell_user_ini/) only afterwards, before the body is parsed. The
// input bound of that parse is then the bytes read, not the post_max_size in
// force at the parse. With the lowered value alone, the hard limit would
// collapse to the floor (2 x memory_limit) and a field larger than that would
// exit with status 137 instead of ending in the deferred fatal.
require __DIR__ . '/inc/php2shell_cgi.php';
$dir = __DIR__ . '/inc/php2shell_user_ini';

function run_case($body, $ml, $user_ini, $dir) {
    return p2s_cgi_run(
        ['memory_limit' => $ml, 'post_max_size' => '64M', 'user_ini.filename' => $user_ini],
        [], ['SCRIPT_FILENAME' => "$dir/child.php", 'DOCUMENT_ROOT' => $dir], $body);
}

// A: .user.ini is in force while the script runs, and the body read under
// the php.ini value was parsed: 16 fields of 512 KiB, 8 MiB in all.
$body = '';
for ($i = 0; $i < 16; $i++) {
    $body .= ($i ? '&' : '') . "f$i=" . str_repeat('Z', 512 * 1024);
}
$r = run_case($body, '32M', '.user.ini', $dir);
echo "A: ", preg_match('/post_vars=\d+/', $r['out'], $m) ? $m[0] : 'no status',
    " ", preg_match('/POST_MAX_SIZE \S+/', $r['out'], $p) ? $p[0] : 'no post_max_size', "\n";
unset($body);

// B: one 20 MiB field and memory_limit=16M: the deferred fatal, not the
// hard-limit exit. C: the same without .user.ini.
$body = 'f=' . str_repeat('Z', 20 * P2S_MIB) . '&g=1';
foreach (['B user_ini' => '.user.ini', 'C no user_ini' => ''] as $label => $uif) {
    $r = run_case($body, '16M', $uif, $dir);
    $outcome = p2s_outcome($r, 16 * P2S_MIB);
    echo "$label: outcome=$outcome\n";
    if ($outcome !== 'deferred') {
        p2s_dump($r);
    }
}
--EXPECT--
A: post_vars=16 POST_MAX_SIZE 1M
B user_ini: outcome=deferred
C no user_ini: outcome=deferred
