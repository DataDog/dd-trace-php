--TEST--
Exceptions from line hook captures released at frame exit propagate to the caller
--DESCRIPTION--
A range that closes at frame exit drops its removed definition immediately, outside the callback sandbox.
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

function cleanup_target() {
    $a = 1;                                 // 4  begin
    return $a;                              // 5  end line: closes at frame exit
}

class DropThrows {
    public function __destruct() {
        echo "  destructor\n";
        throw new RuntimeException('range closure cleanup escaped');
    }
}

$victim = new DropThrows();
$id = DDTrace\install_line_hook(__FILE__, 4, function () use ($victim) {
    echo "  begin\n";
}, 5, function (DDTrace\LineHookData $h) {
    echo "  end\n";
    DDTrace\remove_hook($h->id);
});
var_dump($id < 0);
unset($victim);                             // the begin closure is now the only holder

try {
    var_dump(cleanup_target());
} catch (Throwable $e) {
    echo "escaped: ", $e->getMessage(), "\n";
}

echo "Done.\n";
?>
--EXPECT--
bool(true)
  begin
  end
  destructor
escaped: range closure cleanup escaped
Done.
