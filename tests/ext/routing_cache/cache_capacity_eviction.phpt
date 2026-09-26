--TEST--
DDTrace\routing_cache evicts the oldest inserted entry when capacity (500) is exceeded
--FILE--
<?php

// Fill the cache to capacity (500 entries); key0 is the oldest inserted
for ($i = 0; $i < 500; $i++) {
    DDTrace\routing_cache_set("key$i", "value$i");
}

// key0 is present before any eviction
var_dump(DDTrace\routing_cache_get('key0'));

// Insert one more entry; key0 (oldest) should be evicted
DDTrace\routing_cache_set('key500', 'value500');

var_dump(DDTrace\routing_cache_get('key0'));   // evicted
var_dump(DDTrace\routing_cache_get('key1'));   // still present
var_dump(DDTrace\routing_cache_get('key500')); // newly inserted

?>
--EXPECT--
string(6) "value0"
bool(false)
string(6) "value1"
string(8) "value500"
