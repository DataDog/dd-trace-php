--TEST--
DDTrace\normalized_route_cache keys and values are binary-safe (NULs and non-UTF-8 bytes)
--DESCRIPTION--
The integrations prefix their keys with a short tag and a NUL byte ("laminas\0",
"laravel\0") so that keys from different integrations cannot collide. That
encoding only works if the whole byte sequence -- NULs included -- is kept as
the lookup key. Values can be any byte sequence too.
--FILE--
<?php

// NUL-separated prefix (integrations rely on this working)
$key_a = "laminas\0/api/users/{id}";
$key_b = "laravel\0/api/users/{id}";

DDTrace\normalized_route_cache_set($key_a, 'value-for-laminas');
DDTrace\normalized_route_cache_set($key_b, 'value-for-laravel');

var_dump(DDTrace\normalized_route_cache_get($key_a));
var_dump(DDTrace\normalized_route_cache_get($key_b));
// No NUL prefix: a distinct key, still not present.
var_dump(DDTrace\normalized_route_cache_get("laminas/api/users/{id}"));

// Arbitrary binary value: every byte survives the round-trip.
$value = "";
for ($i = 0; $i < 256; $i++) {
    $value .= chr($i);
}
DDTrace\normalized_route_cache_set('binary:all-bytes', $value);
$round_trip = DDTrace\normalized_route_cache_get('binary:all-bytes');
var_dump($round_trip === $value);
var_dump(strlen($round_trip));

// Empty value is a valid value, not a miss.
DDTrace\normalized_route_cache_set('binary:empty-value', '');
var_dump(DDTrace\normalized_route_cache_get('binary:empty-value'));

?>
--EXPECT--
string(17) "value-for-laminas"
string(17) "value-for-laravel"
bool(false)
bool(true)
int(256)
string(0) ""
