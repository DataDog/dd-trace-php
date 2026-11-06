<?php
// Child script for php2shell_mitigation_deferred_restore_cached_chunks.phpt:
// p2s_stub_hold_chunks(P2S_CHUNKS), past memory_limit, then a safe point.
function p2s_safe_point() {}

p2s_stub_hold_chunks((int)getenv('P2S_CHUNKS'));
p2s_safe_point();
for ($i = 0; $i < 2; $i++) {}
echo "SCRIPT RAN\n";
