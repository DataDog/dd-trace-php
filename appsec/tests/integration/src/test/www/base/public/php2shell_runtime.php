<?php
// Runtime driver for Php2ShellProfilerTests. After request startup, applies
// ?ml= with ini_set() (the mitigation's allocation handlers adopt it as the
// soft limit), then parses ?len= bytes of request data in user code: with
// parse_str() (one large key), or with ?rpb=1 the request body with
// request_parse_body() (PHP 8.4+; send it with PUT so that request startup
// leaves it alone). A crossing of the soft limit inside the parse is only
// marked pending; the deferred fatal is raised at the next VM interrupt, so a
// user function call and a loop follow the parse (PHP 7.1-8.3 do not check
// for an interrupt after an internal call). PARSED is printed only if no error
// was raised by then; base and peak calibrate the limit for the next run.
$ml = isset($_GET['ml']) ? $_GET['ml'] : '';
if ($ml !== '') {
    ini_set('memory_limit', $ml);
}
function p2s_safe_point() {}
if (!empty($_GET['rpb'])) {
    $base = memory_get_usage(true);
    list($vars, $files) = request_parse_body();
} else {
    $s = str_repeat('Z', (int)(isset($_GET['len']) ? $_GET['len'] : 1000));
    $s .= '[k]=1';
    $base = memory_get_usage(true);
    parse_str($s, $vars);
}
p2s_safe_point();
for ($i = 0; $i < 2; $i++) {}
echo "PARSED pid=", getmypid(), " fields=", count($vars), " base=", $base,
    " peak=", memory_get_peak_usage(true), "\n";
