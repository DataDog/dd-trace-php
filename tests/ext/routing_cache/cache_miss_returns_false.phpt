--TEST--
DDTrace\routing_cache_get returns false on cache miss
--FILE--
<?php

var_dump(DDTrace\routing_cache_get('nonexistent'));
var_dump(DDTrace\routing_cache_get(''));
var_dump(DDTrace\routing_cache_get('laminas:/api/users/{id}'));

?>
--EXPECT--
bool(false)
bool(false)
bool(false)
