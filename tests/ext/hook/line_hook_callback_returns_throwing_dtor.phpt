--TEST--
A line-hook callback's return value is destroyed inside the sandbox, so a throwing destructor cannot escape
--DESCRIPTION--
The callback's return value is destroyed before its sandbox closes, so exceptions from that destruction stay contained.
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

class CleanupThrows {
    public function __destruct() {
        throw new RuntimeException('cleanup escaped');
    }
}

function target() {
    $a = 1;                                 // 10
    return $a;                              // 11
}

$id = DDTrace\install_line_hook(__FILE__, 10, function () {
    return new CleanupThrows();
});
var_dump($id < 0);

try {
    var_dump(target());
    var_dump(target());
} catch (Throwable $e) {
    echo 'escaped: ', get_class($e), ': ', $e->getMessage(), "\n";
}

DDTrace\remove_hook($id);
var_dump(target());
echo "Done.\n";
?>
--EXPECT--
bool(true)
int(1)
int(1)
int(1)
Done.
