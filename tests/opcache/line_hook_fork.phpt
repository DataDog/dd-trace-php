--TEST--
A forked child neither restores nor double-counts the parent's shared arm
--DESCRIPTION--
An opline persisted into opcache shared memory is armed process-wide, so the arm is reference counted in a shared region and the last releaser restores the original handler.
The per-process record saying "this process holds a reference" is ordinary heap memory, so fork copies it without a second reference being taken -- and the child then restores the parent's live arm on its way out.
Any child exit does that, not just an explicit remove_hook().
--SKIPIF--
<?php
if (!extension_loaded('pcntl')) die('skip: pcntl extension required');
if (!extension_loaded('Zend OPcache')) die('skip: Zend OPcache is required');
?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--INI--
opcache.enable=1
opcache.enable_cli=1
--FILE--
<?php

function target() {
    $x = 1;                                 // 4
    return $x;                              // 5
}

$hits = 0;
$id = DDTrace\install_line_hook(__FILE__, 4, function () use (&$hits) { ++$hits; });
var_dump($id < 0);

target();
var_dump($hits);            // 1

// Child exits normally: its RSHUTDOWN must not restore the handler the parent still uses.
$pid = pcntl_fork();
if ($pid === 0) {
    exit(0);
}
pcntl_waitpid($pid, $status);
target();
var_dump($hits);            // 2

// Child explicitly removes the inherited hook, then exits.
$pid = pcntl_fork();
if ($pid === 0) {
    DDTrace\remove_hook($id);
    exit(0);
}
pcntl_waitpid($pid, $status);
target();
var_dump($hits);            // 3

// The inverse direction: the parent removing must not disarm the child either.
// The child needs a claim of its own, not merely to abstain from releasing the parent's.
$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
$pid = pcntl_fork();
if ($pid === 0) {
    fclose($pair[0]);
    fwrite($pair[1], "ready\n");
    fgets($pair[1]);                    // parent has removed its hook by now
    target();
    exit($hits === 4 ? 0 : 1);          // 3 inherited + 1 after the parent's removal
}
fclose($pair[1]);
fgets($pair[0]);
DDTrace\remove_hook($id);
fwrite($pair[0], "removed\n");
pcntl_waitpid($pid, $status);
var_dump(pcntl_wexitstatus($status) === 0);

// And the narrow window before the child has even run its fork handler: the parent removing there must not leave the child holding a record that says armed while the trampoline has already been restored.
$id2 = DDTrace\install_line_hook(__FILE__, 4, function () use (&$hits) { ++$hits; });
$pid = pcntl_fork();
if ($pid === 0) {
    target();
    exit($hits > 3 ? 0 : 1);
}
DDTrace\remove_hook($id2);      // immediately, with no handshake
pcntl_waitpid($pid, $status);
var_dump(pcntl_wexitstatus($status) === 0);

target();
var_dump($hits);            // 3: both of the parent's hooks are gone; the child's own count reached 4
echo "Done.\n";
?>
--EXPECT--
bool(true)
int(1)
int(2)
int(3)
bool(true)
bool(true)
int(3)
Done.
