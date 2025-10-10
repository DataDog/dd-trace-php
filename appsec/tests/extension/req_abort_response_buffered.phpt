--TEST--
Request abort with buffered response
--FILE--
<?php
ob_start();
header('X-foo: bar');
echo 'foobar', "\n";
\datadog\appsec\testing\abort_static_page();
?>
THIS SHOULD NOT BE REACHED
--EXPECTF--
%s
Warning: datadog\appsec\testing\abort_static_page(): Datadog blocked the request and presented a static error page - block_id:  in %s on line %d
