--TEST--
SpanData::$meta / $metrics views under the tracing JIT
--SKIPIF--
<?php if (!file_exists(ini_get("extension_dir") . "/opcache.so")) die('skip: opcache.so does not exist in extension_dir'); ?>
<?php if (PHP_VERSION_ID < 80000) die('skip: JIT is only on PHP 8'); ?>
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
--INI--
opcache.enable=1
opcache.enable_cli=1
opcache.jit_buffer_size=32M
opcache.jit=tracing
opcache.jit_hot_loop=1
opcache.jit_hot_func=1
zend_extension=opcache.so
--FILE--
<?php
function tag(\DDTrace\SpanData $span, int $i) {
    $span->meta["k$i"] = $i;
    $span->metrics["m$i"] = $i;
    if (isset($span->meta["k" . ($i - 1)])) {
        unset($span->meta["k" . ($i - 1)]);
    }
    return count($span->meta) + count($span->metrics);
}

\DDTrace\start_span();
$span = \DDTrace\start_span();
$total = 0;
for ($i = 0; $i < 200; $i++) {
    $total += tag($span, $i);
}
$meta = $span->meta;
var_dump($total > 0, array_values(preg_grep('/^k/', array_keys($meta))), $span->attributes['k199']);
var_dump(count(preg_grep('/^m\d+$/', array_keys($span->metrics))), $span->metrics['m199']);
\DDTrace\close_span();
\DDTrace\close_span();
?>
--EXPECT--
bool(true)
array(1) {
  [0]=>
  string(4) "k199"
}
string(3) "199"
int(200)
float(199)
