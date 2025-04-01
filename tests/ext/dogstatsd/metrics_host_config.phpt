--TEST--
Test DogStatsD configuration with DD_DOGSTATSD_HOST and DD_DOGSTATSD_PORT
--ENV--
DD_DOGSTATSD_HOST=192.168.1.1
DD_DOGSTATSD_PORT=9876
--FILE--
<?php
// Get the configured URL
$url = ddtrace_dogstatsd_url();
var_dump($url);
?>
--EXPECT--
string(25) "udp://192.168.1.1:9876"