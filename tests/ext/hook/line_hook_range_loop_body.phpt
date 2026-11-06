--TEST--
A line hook range wholly inside a loop body opens and closes once per iteration
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

function loop() {
    $out = [];
    foreach ([1, 2, 3] as $v) {
        $a = $v;                // 6  begin
        $b = $a * 2;
        $out[] = $b;            // 8  end (closes at the foreach back edge, line 5)
    }
    return $out;
}

$log = [];
$b = function (DDTrace\LineHookData $h) use (&$log) { $log[] = 'B' . $h->line; };
$e = function (DDTrace\LineHookData $h) use (&$log) { $log[] = 'E' . $h->line; };
$id = DDTrace\install_line_hook(__FILE__, 6, $b, 8, $e);

var_dump(loop());
$s = implode(',', $log);
echo $s, "\n";
var_dump(substr_count($s, 'B'), substr_count($s, 'E'));
DDTrace\remove_hook($id);
echo "Done.\n";
?>
--EXPECT--
array(3) {
  [0]=>
  int(2)
  [1]=>
  int(4)
  [2]=>
  int(6)
}
B6,E5,B6,E5,B6,E5
int(3)
int(3)
Done.
