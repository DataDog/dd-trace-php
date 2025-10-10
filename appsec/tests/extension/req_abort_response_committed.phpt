--TEST--
Request abort with the response already committed
--FILE--
<?php
header('X-foo: bar');
echo 'foobar', "\n";
flush();
\datadog\appsec\testing\abort_static_page();
?>
THIS SHOULD NOT BE REACHED
--EXPECTHEADERS--
X-foo: bar
--EXPECTF--
foobar

Warning: datadog\appsec\testing\abort_static_page(): Datadog blocked the request, but the response has already been partially committed - block_id:  in %s on line %d
