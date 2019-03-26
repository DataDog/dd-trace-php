--TEST--
Basic functionality of dd_trace_serialize_trace()
--FILE--
<?php
putenv('APP_ENV=dd_testing');
include __DIR__ . '/../../bridge/dd_wrap_autoloader.php';

function dd_trace_unserialize_trace_human($message) {
    $unserialized = [];
    $length = strlen($message);
    for ($i = 0; $i < $length; $i++) {
        $code = ord($message[$i]);
        $word = '';
        while ($code >= 32 && $code <= 126) {
            $word .= $message[$i];
            $i++;
            $code = ord($message[$i]);
        }
        if ($word) {
            $unserialized[] = $word;
        }
        if ($i < $length) {
            $unserialized[] = '0x' . strtoupper(bin2hex($message[$i]));
        }
    }
    return implode(' ', $unserialized);
}

$tracer = \DDTrace\GlobalTracer::get();

$encoded = dd_trace_serialize_trace($tracer);
echo dd_trace_unserialize_trace_human($encoded) . "\n";
?>
--EXPECT--
0x82 0xA7 compact 0xC3 0xA6 schema 0x00
