--TEST--
DDTrace\normalized_route_cache is shared across fork() children and parent
--SKIPIF--
<?php
if (!extension_loaded('pcntl')) die('skip: pcntl not available');
if (PHP_OS_FAMILY === 'Windows') die('skip: no fork() on Windows');
?>
--DESCRIPTION--
The shared mapping is installed in MINIT with MAP_SHARED|MAP_ANONYMOUS, so a
child created by pcntl_fork() inherits the exact same memory as the parent.
This test writes an entry in the parent, writes a different entry in a child,
then reads both from each side: the parent sees the child's write, and the
child sees the parent's.

Each process registers its own participant (an inherited one is unusable), which
happens lazily on the first normalized_route_cache_get/set call on that thread.
--FILE--
<?php

const CHILD_KEY  = 'shared:from-child';
const CHILD_VAL  = 'child-wrote-this';
const PARENT_KEY = 'shared:from-parent';
const PARENT_VAL = 'parent-wrote-this';

DDTrace\normalized_route_cache_set(PARENT_KEY, PARENT_VAL);

$pipe_pair = [];
if (socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pipe_pair) === false) {
    die("socket_create_pair failed");
}
list($parent_sock, $child_sock) = $pipe_pair;

$pid = pcntl_fork();
if ($pid === -1) {
    die("fork failed");
}

if ($pid === 0) {
    // Child: wait for the parent to signal it has stored its key, then write
    // its own and read back the parent's.
    socket_close($parent_sock);
    $signal = '';
    while (strlen($signal) < 1) {
        $chunk = socket_read($child_sock, 1);
        if ($chunk === false || $chunk === '') break;
        $signal .= $chunk;
    }

    DDTrace\normalized_route_cache_set(CHILD_KEY, CHILD_VAL);
    $seen_parent = DDTrace\normalized_route_cache_get(PARENT_KEY);
    socket_write($child_sock, $seen_parent === PARENT_VAL ? '1' : '0', 1);
    socket_close($child_sock);
    exit(0);
}

// Parent: signal the child, wait for its report, then read the child's entry.
socket_close($child_sock);
socket_write($parent_sock, '1', 1);

$child_report = '';
while (strlen($child_report) < 1) {
    $chunk = socket_read($parent_sock, 1);
    if ($chunk === false || $chunk === '') break;
    $child_report .= $chunk;
}
socket_close($parent_sock);
pcntl_waitpid($pid, $status);

echo "child saw parent's entry: ", $child_report === '1' ? "yes\n" : "no\n";
var_dump(DDTrace\normalized_route_cache_get(CHILD_KEY));

?>
--EXPECT--
child saw parent's entry: yes
string(16) "child-wrote-this"
