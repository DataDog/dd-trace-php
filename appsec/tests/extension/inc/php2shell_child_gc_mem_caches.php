<?php
// Child script for php2shell_mitigation_gc_mem_caches.phpt: allocates many
// small strings and frees them, which leaves their zend_mm pages on the free
// lists of the small bins (only zend_mm_gc() returns such pages), then prints
// GC freed=N with what gc_mem_caches() returned. P2S_ALLOC, if set, is then
// allocated and kept, followed by an explicit VM safe point (see
// php2shell_child_runtime_parse_str.php for why both a user function call and
// a loop); SCRIPT RAN is printed only if no error was raised by then.
function p2s_safe_point() {}

$a = [];
for ($i = 0; $i < 200000; $i++) {
    $a[] = str_repeat('a', 40) . $i;
}
$a = null;
echo "GC freed=", gc_mem_caches(), "\n";
$alloc = getenv('P2S_ALLOC');
$s = $alloc !== false && $alloc !== '' ? str_repeat('x', (int)$alloc) : '';
p2s_safe_point();
for ($i = 0; $i < 2; $i++) {}
echo "SCRIPT RAN len=", strlen($s), "\n";
