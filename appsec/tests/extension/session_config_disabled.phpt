--TEST--
AppSec has no session config when it is fully disabled
--INI--
datadog.appsec.enabled=0
--FILE--
<?php
var_dump(datadog\appsec\testing\appsec_session_config());
?>
--EXPECT--
NULL
