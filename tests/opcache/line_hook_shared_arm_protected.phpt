--TEST--
Refcounted arm/disarm across processes while opcache.protect_memory keeps the segment read-only
--SKIPIF--
<?php
if (!extension_loaded('Zend OPcache')) die('skip: opcache is required');
if (!extension_loaded('pcntl')) die('skip: pcntl extension required');
?>
--INI--
opcache.enable_cli=1
opcache.protect_memory=1
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

// Three mechanisms only meet here.
// The opline must be recognised as living in opcache's segment -- via the segment bounds on 8.4+, or via the persisted op_array's NULL refcount before that, since smm_shared_globals is not exported there; the store must be made writable despite the segment being PROT_READ; and the restore must wait for the last reference, which belongs to the child.
// Each process mprotects only its own mapping, so neither exposes the segment to the other.
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
    $id = DDTrace\install_line_hook($target, 4, function () use (&$hits) { $hits++; });
    tell($s, 'armed');
    hear($s);                        // the parent has released its reference by now
    line_hook_target();
    tell($s, 'hits=' . $hits);
    // Releasing the last reference is what actually restores the handler, through the protected page.
    DDTrace\remove_hook($id);
    line_hook_target();
    tell($s, 'after=' . $hits);
    exit(0);
}

fclose($sock[1]);
$s = $sock[0];
$hits = 0;
$id = DDTrace\install_line_hook($target, 4, function () use (&$hits) { $hits++; });
hear($s);                            // both processes armed on the same opline
DDTrace\remove_hook($id);            // not the last reference: must not restore
tell($s, 'released');

echo "child ", hear($s), "\n";
echo "child ", hear($s), "\n";
pcntl_waitpid($pid, $status);
var_dump(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0);

// The line is fully disarmed now, so a fresh install must still be able to arm and fire through the protected page.
$hits = 0;
$id = DDTrace\install_line_hook($target, 4, function () use (&$hits) { $hits++; });
line_hook_target();
DDTrace\remove_hook($id);
line_hook_target();
var_dump($hits);

echo "Done.\n";
?>
--EXPECT--
bool(true)
child hits=1
child after=1
bool(true)
int(1)
Done.
