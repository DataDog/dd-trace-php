--TEST--
A hook installed from inside a begin callback joins the frame's existing record instead of orphaning a second one
--DESCRIPTION--
The frame's record used to be published only after every begin handler had returned.
A begin callback installing an end-only hook on its own target therefore found no record, created one of its own, and had it overwritten by the insert that followed -- orphaning that record's dynamic allocation and the hook reference it took, so the captured values were never released.
Publishing the record first makes the join find the real one.
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

function target() { return 1; }

$installed = 0;
DDTrace\install_hook('target', function () use (&$installed) {
    $capture = new Capture();
    $id = DDTrace\install_hook('target', null, function (DDTrace\HookData $h) use ($capture) {
        DDTrace\remove_hook($h->id);
    });
    if ($id > 0) {
        $installed++;
    }
});

for ($i = 0; $i < 25; ++$i) {
    target();
}

var_dump($installed);
var_dump(Capture::$destroyed);
echo "Done.\n";
?>
--EXPECT--
int(25)
int(25)
Done.
