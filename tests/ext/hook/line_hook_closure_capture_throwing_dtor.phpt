--TEST--
Exceptions from a removed line hook's captured values propagate before the hooked opcode runs
--DESCRIPTION--
A callback that removes its own hook defers teardown until dispatch ends.
Captured values are destroyed outside the callback sandbox, just as for function hooks.
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

function cleanup_target() {
    echo "unexpected body\n";                // 4
    return 1;
}

class DropThrows {
    public function __destruct() {
        echo "  destructor\n";
        throw new RuntimeException('closure cleanup escaped');
    }
}

$victim = new DropThrows();
$id = DDTrace\install_line_hook(__FILE__, 4, function (DDTrace\LineHookData $h) use ($victim) {
    echo "  callback\n";
    DDTrace\remove_hook($h->id);
});
var_dump($id < 0);
unset($victim);                             // the closure is now the only holder

try {
    var_dump(cleanup_target());
} catch (Throwable $e) {
    echo "escaped: ", $e->getMessage(), "\n";
}

echo "Done.\n";
?>
--EXPECT--
bool(true)
  callback
  destructor
escaped: closure cleanup escaped
Done.
