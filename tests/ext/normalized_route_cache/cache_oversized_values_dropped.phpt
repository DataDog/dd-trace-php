--TEST--
DDTrace\normalized_route_cache silently drops keys or values above the configured maximum
--DESCRIPTION--
shm_generational_cache returns KEY_TOO_LARGE or VALUE_TOO_LARGE for inserts that
exceed the compile-time maximums, and KEY_TOO_LARGE for a lookup with a too-long
key. normalized_route_cache_set swallows those (best-effort cache: a set never
raises), and normalized_route_cache_get returns false (the entry is not there).
Sibling entries stored around them are unaffected.

The maximums match DDTRACE_NORMALIZED_ROUTE_CACHE_MAX_KEY_SIZE and
DDTRACE_NORMALIZED_ROUTE_CACHE_MAX_VALUE_SIZE in tracer/normalized_route_cache.h.
--FILE--
<?php

const MAX_KEY   = 2048;
const MAX_VALUE = 4096;

$oversized_key   = str_repeat('k', MAX_KEY + 1);
$oversized_value = str_repeat('v', MAX_VALUE + 1);

// Insert and read a sentinel on either side of the oversized attempts, to
// pin that nothing adjacent is affected.
DDTrace\normalized_route_cache_set('oversize:before', 'ok-before');

DDTrace\normalized_route_cache_set($oversized_key, 'small-value');
DDTrace\normalized_route_cache_set('oversize:regular', $oversized_value);

DDTrace\normalized_route_cache_set('oversize:after', 'ok-after');

var_dump(DDTrace\normalized_route_cache_get('oversize:before'));
var_dump(DDTrace\normalized_route_cache_get($oversized_key));
var_dump(DDTrace\normalized_route_cache_get('oversize:regular'));
var_dump(DDTrace\normalized_route_cache_get('oversize:after'));

// A value of exactly MAX_VALUE bytes is on the accepting side of the boundary
// and must round-trip.
$boundary_value = str_repeat('m', MAX_VALUE);
DDTrace\normalized_route_cache_set('oversize:boundary', $boundary_value);
var_dump(DDTrace\normalized_route_cache_get('oversize:boundary') === $boundary_value);

?>
--EXPECT--
string(9) "ok-before"
bool(false)
bool(false)
string(8) "ok-after"
bool(true)
