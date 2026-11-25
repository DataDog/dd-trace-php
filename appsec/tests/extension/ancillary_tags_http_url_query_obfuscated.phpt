--TEST--
Ancillary http.url uses the tracer's URL builder (query string obfuscated)
--ENV--
REQUEST_URI=/foo?user=bob&password=hunter2
SCRIPT_NAME=/foo.php
REQUEST_METHOD=GET
HTTP_HOST=myhost:8888
--GET--
user=bob&password=hunter2
--FILE--
<?php

use function datadog\appsec\testing\add_all_ancillary_tags;

$all = array();
add_all_ancillary_tags($all);
echo $all['http.url'], "\n";
?>
--EXPECT--
http://myhost:8888/foo?user=bob&<redacted>
