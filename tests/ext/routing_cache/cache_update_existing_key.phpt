--TEST--
DDTrace\routing_cache_set updates value for existing key
--FILE--
<?php

DDTrace\routing_cache_set('mykey', 'first');
var_dump(DDTrace\routing_cache_get('mykey'));

DDTrace\routing_cache_set('mykey', 'updated');
var_dump(DDTrace\routing_cache_get('mykey'));

?>
--EXPECT--
string(5) "first"
string(7) "updated"
