--TEST--
DDTrace\routing_cache_set stores and DDTrace\routing_cache_get retrieves values
--FILE--
<?php

DDTrace\routing_cache_set('laminas:/api/users/{id}', '/api/users/{id}');
var_dump(DDTrace\routing_cache_get('laminas:/api/users/{id}'));

DDTrace\routing_cache_set('symfony:/blog/{slug}', '/blog/{slug}');
var_dump(DDTrace\routing_cache_get('symfony:/blog/{slug}'));

// keys are isolated
var_dump(DDTrace\routing_cache_get('laminas:/api/users/{id}'));
var_dump(DDTrace\routing_cache_get('other:key'));

?>
--EXPECT--
string(15) "/api/users/{id}"
string(12) "/blog/{slug}"
string(15) "/api/users/{id}"
bool(false)
