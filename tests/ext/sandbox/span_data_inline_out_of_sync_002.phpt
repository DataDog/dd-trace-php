--TEST--
Inline API out-of-sync error
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die('skip PHP 5.4 not supported'); ?>
--ENV--
DD_TRACE_DEBUG=1
--FILE--
<?php
use DDTrace\SpanData;

function bar($message) {
    $span = new SpanData();
    $span->name = 'userland';
    echo "bar($message)\n";
    // Closing inline span twice to make span stack out of sync
    $span->close();
    $span->close();
}

bar('hello');

include 'dd_dumper.inc';
dd_dump_spans();
?>
--EXPECTF--
bar(hello)
Cannot close DDTrace\SpanData #%d; spans out of sync
spans(\DDTrace\SpanData) (1) {
  userland (userland)
}
