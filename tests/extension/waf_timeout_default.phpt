--TEST--
ddappsec.waf_timeout default value
--FILE--
<?php
var_dump(ini_get('ddappsec.waf_timeout'));
--EXPECT--
string(2) "10"
