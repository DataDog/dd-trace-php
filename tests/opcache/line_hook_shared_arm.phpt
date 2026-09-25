--TEST--
remove_hook() in one worker must not disarm an opline a sibling sharing the opcache segment still needs
--SKIPIF--
<?php
if (!extension_loaded('Zend OPcache')) die('skip: opcache is required');
if (!extension_loaded('pcntl')) die('skip: pcntl extension required');
?>
--INI--
opcache.enable_cli=1
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

// opline->handler lives in the opcache segment, which both processes see at the same address once the include is cached and the fork has copied the mapping.
// Each process arms independently, so the arm has to be refcounted: whoever calls remove_hook() first must leave the trampoline in place for the other.
$target = __DIR__ . '/line_hook_target.php';
require $target;
var_dump(opcache_is_script_cached($target));

$sock = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

function tell($s, $m) { fwrite($s, $m . "\n"); }
function hear($s) { return rtrim((string)fgets($s)); }

$pid = pcntl_fork();
if ($pid === -1) die("fork failed\n");

if ($pid === 0) {
    fclose($sock[0]);
    $s = $sock[1];
    $hits = 0;
    DDTrace\install_line_hook($target, 4, function () use (&$hits) { $hits++; });
    tell($s, 'armed');
    hear($s);                       // parent has removed its own hook by now
    line_hook_target();
    tell($s, 'hits=' . $hits);
    exit(0);
}

fclose($sock[1]);
$s = $sock[0];
$hits = 0;
$id = DDTrace\install_line_hook($target, 4, function () use (&$hits) { $hits++; });
hear($s);                           // both processes are armed on the same opline
DDTrace\remove_hook($id);
tell($s, 'removed');

echo "child ", hear($s), "\n";
pcntl_waitpid($pid, $status);

line_hook_target();
echo "parent hits=$hits\n";

// The last reference is gone with the child, so a fresh install must still be able to arm and fire.
$id = DDTrace\install_line_hook($target, 4, function () use (&$hits) { $hits++; });
line_hook_target();
DDTrace\remove_hook($id);
line_hook_target();
echo "parent hits=$hits\n";

echo "Done.\n";
?>
--EXPECT--
bool(true)
child hits=1
parent hits=0
parent hits=1
Done.
