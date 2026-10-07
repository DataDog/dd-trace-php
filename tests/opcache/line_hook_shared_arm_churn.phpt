--TEST--
Two workers churning install/remove on the same shared opline must never lose a hit to each other
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

// Both processes write opline->handler in the same opcache segment and no process-local lock spans them, so both sides churn here: each is constantly dropping the *last* reference -- and therefore restoring the original -- at the moment the other is installing the trampoline.
// Each side checks per iteration that the hook it just installed actually fired: exactly one hit per call, however the two interleave.
//
// This found a real one: capturing the displaced handler used to be two separate reads of opline->handler -- one to test for the trampoline, one for the value -- and a sibling arming between them recorded *the trampoline* as the original.
// The trampoline then returned itself and the VM re-entered it until the stack overflowed, faulting inside zend_call_stack_size_error() rather than raising a catchable fatal.
// It reproduced as a SIGSEGV in the child on roughly one run in ten before the displaced value was made to come from the swap alone.
//
// It does *not* discriminate on the arm/restore pair being atomic against each other, and is not meant to: those are already serialised by the refcount, since the last releaser parks the slot in ZAI_LINE_ARM_RESTORING and a sibling's acquire yields until the restore finishes.
// Checked by re-running this against a deliberately split read-then-write with a sched_yield() wedged into the gap, which it still passes.
// The swap and compare-and-swap are there for the writers that bypass the refcount entirely -- an opcache restart, and arms holding no reference; see zai_line_handler_cas.
$target = __DIR__ . '/line_hook_target.php';
require $target;
var_dump(opcache_is_script_cached($target));

$sock = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
function tell($s, $m) { fwrite($s, $m . "\n"); }
function hear($s) { return rtrim((string)fgets($s)); }

const ROUNDS = 2000;

function churn($rounds) {
    $misses = 0;
    for ($i = 0; $i < $rounds; $i++) {
        $hits = 0;
        $id = DDTrace\install_line_hook(__DIR__ . '/line_hook_target.php', 4, function () use (&$hits) { $hits++; });
        line_hook_target();
        if ($hits !== 1) {
            $misses++;
        }
        DDTrace\remove_hook($id);
    }
    return $misses;
}

$pid = pcntl_fork();
if ($pid === -1) die("fork failed\n");

if ($pid === 0) {
    fclose($sock[0]);
    $s = $sock[1];
    tell($s, 'ready');
    hear($s);                       // start together, so the two overlap for the whole run
    tell($s, 'misses=' . churn(ROUNDS));
    exit(0);
}

fclose($sock[1]);
$s = $sock[0];
hear($s);                           // child is ready
tell($s, 'go');
$misses = churn(ROUNDS);
echo 'child ', hear($s), "\n";
echo "parent misses=$misses\n";
pcntl_waitpid($pid, $status);
echo 'child exit ', pcntl_wexitstatus($status), "\n";

// Every reference is gone, so the trampoline must have been restored rather than left installed: a fresh install has to be able to arm and fire again.
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
child misses=0
parent misses=0
child exit 0
int(1)
Done.
