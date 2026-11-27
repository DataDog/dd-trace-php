--TEST--
DDTrace\normalized_route_cache_set stores and DDTrace\normalized_route_cache_get retrieves values
--FILE--
<?php

DDTrace\normalized_route_cache_set('laminas:/api/users/{id}', '/api/users/{id}');
var_dump(DDTrace\normalized_route_cache_get('laminas:/api/users/{id}'));

DDTrace\normalized_route_cache_set('laravel:/blog/{slug}', '/blog/{slug}');
var_dump(DDTrace\normalized_route_cache_get('laravel:/blog/{slug}'));

// keys are isolated
var_dump(DDTrace\normalized_route_cache_get('laminas:/api/users/{id}'));
var_dump(DDTrace\normalized_route_cache_get('other:key'));

?>
--EXPECT--
string(15) "/api/users/{id}"
string(12) "/blog/{slug}"
string(15) "/api/users/{id}"
bool(false)
