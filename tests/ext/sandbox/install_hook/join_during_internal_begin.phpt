--TEST--
A hook installed from inside an internal function's begin callback joins that frame instead of orphaning a record
--DESCRIPTION--
Internal calls built their frame record on the stack, ran the begin handlers, and only then published it -- so a handler installing an end-only hook on the same internal function found no record and created a second one, which the publish then overwrote.
The same stack copy was also what zai_hook_finish() ran from, so even a join that did land on the stored record was invisible to the end pass: it grows the stored record's dynamic block and hook_count, which the copy taken before the call does not have.
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--INI--
datadog.trace.hook_limit=0
--FILE--
<?php

class Capture {
    public static $destroyed = 0;
    public $payload;
    public function __construct() { $this->payload = str_repeat('x', 4096); }
    public function __destruct() { self::$destroyed++; }
}

function mapper($x) { return $x * 2; }

$installed = 0;
$ended = 0;
DDTrace\install_hook('array_map', function () use (&$installed, &$ended) {
    $capture = new Capture();
    $id = DDTrace\install_hook('array_map', null, function (DDTrace\HookData $h) use ($capture, &$ended) {
        $ended++;
        DDTrace\remove_hook($h->id);
    });
    if ($id > 0) {
        $installed++;
    }
});

for ($i = 0; $i < 10; ++$i) {
    array_map('mapper', [$i]);
}

var_dump($installed);
var_dump($ended);
var_dump(Capture::$destroyed);
echo "Done.\n";
?>
--EXPECT--
int(10)
int(10)
int(10)
Done.
