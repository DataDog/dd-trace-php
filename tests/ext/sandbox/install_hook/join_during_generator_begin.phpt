--TEST--
A hook installed from inside a generator's begin callback joins the generator's record instead of orphaning one
--DESCRIPTION--
A generator's begin handlers run at creation, against a record that was built on the stack and published only afterwards -- keyed by the generator object.
A handler installing an end-only hook on the same generator function therefore found nothing to join, made its own record, and had it overwritten by the publish, dropping the hook reference and never running the end.
The record was also never zeroed, so zai_hook_continue() read `walking` out of stack garbage; a nonzero byte there marks the frame as permanently being walked and refuses every later join.
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

function gen($n) {
    yield $n;
    yield $n + 1;
}

$installed = 0;
$ended = 0;
DDTrace\install_hook('gen', function () use (&$installed, &$ended) {
    $capture = new Capture();
    $id = DDTrace\install_hook('gen', null, function (DDTrace\HookData $h) use ($capture, &$ended) {
        $ended++;
        DDTrace\remove_hook($h->id);
    });
    if ($id > 0) {
        $installed++;
    }
});

$sum = 0;
for ($i = 0; $i < 10; ++$i) {
    foreach (gen($i) as $v) {
        $sum += $v;
    }
}

var_dump($installed);
var_dump($ended);
var_dump(Capture::$destroyed);
var_dump($sum);
echo "Done.\n";
?>
--EXPECT--
int(10)
int(10)
int(10)
int(100)
Done.
