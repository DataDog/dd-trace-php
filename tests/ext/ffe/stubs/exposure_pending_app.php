<?php
function recordAssignment($flag, $subject, $allocation, $variant, $serial, $i) {
    DDTrace\Internal\record_ffe_exposure($flag, $subject, array('index' => $i, 'pad' => str_repeat('x', 5000)),
        $allocation, $variant, $serial, array('index' => (string)$i));
}

recordAssignment('flag', 'user', 'A', 'on', null, 0);
recordAssignment('other', 'user', 'A', 'on', null, 0);
recordAssignment('flag', 'other', 'A', 'on', null, 0);
$before = memory_get_usage();
for ($i = 1; $i <= 1000; ++$i) {
    recordAssignment('flag', 'user', 'A', 'on', null, $i);
    recordAssignment('other', 'user', 'A', 'on', null, $i);
    recordAssignment('flag', 'other', 'A', 'on', null, $i);
}
echo 'bounded=', json_encode(memory_get_usage() - $before < 1024 * 1024), "\n";
recordAssignment('flag', 'user', 'B', 'on', null, 1);
recordAssignment('flag', 'user', 'A', 'on', null, 2);
recordAssignment('flag', 'user', 'A', 'off', null, 3);
recordAssignment('flag', 'user', 'A', 'off', 0, 4);
recordAssignment('flag', 'user', 'A', 'off', 2, 5);
echo 'handoff=', json_encode(DDTrace\Testing\flush_ffe_exposures()), "\n";
if (trim((string)fgets(STDIN)) !== 'next') throw new RuntimeException('missing delivery barrier');
