--TEST--
A LineHookData kept alive past its callback reports no vars instead of reading a dead frame
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

function work($secret) {
    $local = $secret . '!';         // 4
    return $local;
}

$kept = null;
$p = function (DDTrace\LineHookData $h) use (&$kept) {
    $kept = $h;
    var_dump($h->var('secret'));
    $h->data = 'carried';
};
$id = DDTrace\install_line_hook(__FILE__, 4, $p);
var_dump(work('s'));
DDTrace\remove_hook($id);

// The frame is long gone by now.
var_dump($kept instanceof DDTrace\LineHookData);
var_dump($kept->line);
var_dump($kept->data);
var_dump($kept->var('secret'));
var_dump($kept->vars());
echo "Done.\n";
?>
--EXPECT--
string(1) "s"
string(2) "s!"
bool(true)
int(4)
string(7) "carried"
NULL
array(0) {
}
Done.
