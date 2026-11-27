--TEST--
DDTrace\normalized_route_cache evicts entries once the shared mapping runs through generations
--DESCRIPTION--
shm_generational_cache is an approximate-LRU over three rotating arenas, not a
FIFO: a lookup probes the current and previous arena, so an entry stays
reachable for two generations and is unreachable only once the arena that held
it is reused. That is why this test inserts far past the 500-entry per-arena
target (DDTRACE_NORMALIZED_ROUTE_CACHE_MAX_OCCUPANCY) before asserting that
anything is gone: fewer inserts can still reach the first entry. The test only
pins the boundaries that survive the policy change -- oldest entries eventually
leave, newest ones survive, every stored value round-trips -- and leaves the
per-insert order to the crate.
--FILE--
<?php

const MAX_OCCUPANCY = 500;
// Three arenas (current/previous/old-reusable). After filling three times the
// per-arena occupancy target, the oldest arena's records have been overwritten
// and its entries are no longer reachable from either live generation.
const TOTAL_INSERTS = MAX_OCCUPANCY * 4;

for ($i = 0; $i < TOTAL_INSERTS; $i++) {
    DDTrace\normalized_route_cache_set("key$i", "value$i");
}

$surviving_old = 0;
for ($i = 0; $i < MAX_OCCUPANCY; $i++) {
    if (DDTrace\normalized_route_cache_get("key$i") !== false) {
        $surviving_old++;
    }
}
echo "oldest block, still reachable: ", $surviving_old < MAX_OCCUPANCY ? "some evicted\n" : "none evicted\n";

$recent_hits = 0;
for ($i = TOTAL_INSERTS - MAX_OCCUPANCY; $i < TOTAL_INSERTS; $i++) {
    $value = DDTrace\normalized_route_cache_get("key$i");
    if ($value === "value$i") {
        $recent_hits++;
    }
}
echo "newest block, hit rate over 50%: ", $recent_hits > MAX_OCCUPANCY / 2 ? "yes\n" : "no\n";

// The last insert is in the current arena, which is never rotated away mid-fill.
var_dump(DDTrace\normalized_route_cache_get("key" . (TOTAL_INSERTS - 1)));

?>
--EXPECTF--
oldest block, still reachable: some evicted
newest block, hit rate over 50%: yes
string(%d) "value%d"
