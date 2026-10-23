--TEST--
FFE diagnostic checks scan large logs without missing markers between chunks
--INI--
memory_limit=16M
--FILE--
<?php
require __DIR__ . '/stubs/ffe_process.inc';
$stream = tmpfile();
if ($stream === false) throw new RuntimeException('cannot create diagnostic log');
try {
    // Larger than the test's memory limit; do not load the whole log at once.
    $chunk = str_repeat('x', 8192);
    for ($i = 0; $i < 4096; ++$i) fwrite($stream, $chunk);
    fwrite($stream, str_repeat('x', 8190) . 'pii-canary@example.test');
    fwrite($stream, '\nFfeFlagEvaluationEvent\n');
    var_dump(ffeTestStreamContains($stream, 'pii-canary@example.test'));
    var_dump(ffeTestStreamContains($stream, 'FfeFlagEvaluationEvent'));
    var_dump(ffeTestStreamContains($stream, 'absent-canary@example.test'));
} finally {
    fclose($stream);
}
?>
--EXPECT--
bool(true)
bool(true)
bool(false)
