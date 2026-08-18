--TEST--
DDTrace\routing_cache evicts least-recently-used entry when capacity (500) is exceeded
--FILE--
<?php

// Fill the cache to capacity (500 entries)
for ($i = 0; $i < 500; $i++) {
    DDTrace\routing_cache_set("key$i", "value$i");
}

// key0 is the LRU — access key1..key499 to keep them hot, leaving key0 as LRU
// Actually they were inserted oldest-first, so key0 is at tail (LRU).
// Verify key0 is still present before eviction
var_dump(DDTrace\routing_cache_get('key0'));

// Access key0 to move it to MRU position so key1 becomes LRU
DDTrace\routing_cache_get('key0');

// Insert one more entry; key1 should be evicted (it's now LRU)
DDTrace\routing_cache_set('key500', 'value500');

var_dump(DDTrace\routing_cache_get('key1'));   // evicted
var_dump(DDTrace\routing_cache_get('key0'));   // still present (was accessed)
var_dump(DDTrace\routing_cache_get('key500')); // newly inserted

?>
--EXPECT--
string(6) "value0"
bool(false)
string(6) "value0"
string(8) "value500"
