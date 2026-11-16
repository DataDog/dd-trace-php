--TEST--
datadog.appsec.waf_timeout default value
--FILE--
<?php
var_dump(ini_get('datadog.appsec.waf_timeout'));
--EXPECT--
string(5) "10000"
