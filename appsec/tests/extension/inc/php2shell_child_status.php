<?php
// Child script for php2shell_mitigation_hard_limit_formula.phpt and the
// .user.ini test: prints the soft limit (memory_limit in force, in bytes) and
// the hard limit status() reports for a startup POST parse run with the INI
// settings the test passes (post_max_size cannot be changed with ini_set()),
// plus the count of $_POST entries, which shows that a request body was parsed.
function p2s_bytes($v) {
    $v = trim($v);
    $n = (int)$v;
    switch (strtolower(substr($v, -1))) {
        case 'g': $n *= 1024;
        // fall through
        case 'm': $n *= 1024;
        // fall through
        case 'k': $n *= 1024;
    }
    return $n;
}
$s = datadog\appsec\testing\php2shell_mitigation_status();
echo "STATUS soft=", p2s_bytes(ini_get('memory_limit')), " hard=", $s['hard_limit'],
    " post_vars=", count($_POST), "\n";
