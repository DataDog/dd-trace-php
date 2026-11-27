--TEST--
DDTrace\normalized_route_cache_set updates value for existing key
--FILE--
<?php

DDTrace\normalized_route_cache_set('mykey', 'first');
var_dump(DDTrace\normalized_route_cache_get('mykey'));

DDTrace\normalized_route_cache_set('mykey', 'updated');
var_dump(DDTrace\normalized_route_cache_get('mykey'));

?>
--EXPECT--
string(5) "first"
string(7) "updated"
