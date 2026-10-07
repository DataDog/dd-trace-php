--TEST--
DDTrace\LineHookData exposes the instrumented frame's variables and carries state from begin to end
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

function target($arg) {
    $before = $arg * 2;
    $mid = $before + 1;     // 5  begin
    $after = $mid . '!';    // 6
    return $after;          // 7  end
}

$seen = [];
$id = DDTrace\install_line_hook(
    __FILE__,
    5,
    function (DDTrace\LineHookData $h) use (&$seen) {
        $seen['id'] = $h->id < 0;
        $seen['line'] = $h->line;
        $seen['file'] = basename($h->file);
        $seen['arg'] = $h->var('arg');
        $seen['before'] = $h->var('before');
        // $mid is only assigned by the line we are about to run, so it is not defined yet.
        $seen['mid_at_begin'] = $h->var('mid');
        $seen['missing'] = $h->var('nope');
        $seen['vars_at_begin'] = array_keys($h->vars());
        $h->data = 'carried';
    },
    6,
    function (DDTrace\LineHookData $h) use (&$seen) {
        $seen['data_at_end'] = $h->data;
        $seen['mid_at_end'] = $h->var('mid');
        $seen['after_at_end'] = $h->var('after');
        $seen['line_at_end'] = $h->line;
    }
);
var_dump($id < 0);

var_dump(target(4));

var_dump($seen['id'], $seen['line'], $seen['file']);
var_dump($seen['arg'], $seen['before'], $seen['mid_at_begin'], $seen['missing']);
var_dump($seen['vars_at_begin']);
var_dump($seen['data_at_end'], $seen['mid_at_end'], $seen['after_at_end'], $seen['line_at_end']);

DDTrace\remove_hook($id);
echo "Done.\n";
?>
--EXPECT--
bool(true)
string(2) "9!"
bool(true)
int(5)
string(18) "line_hook_data.php"
int(4)
int(8)
NULL
NULL
array(2) {
  [0]=>
  string(3) "arg"
  [1]=>
  string(6) "before"
}
string(7) "carried"
int(9)
string(2) "9!"
int(7)
Done.
