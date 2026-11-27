#ifndef DDTRACE_NORMALIZED_ROUTE_CACHE_H
#define DDTRACE_NORMALIZED_ROUTE_CACHE_H

#include <php.h>

#include "components-rs/shm_generational_cache.h"

/* A string -> string map for computed normalized routes (see
 * src/DDTrace/Util/RouteNormalizer.php, the output of normalizeElements and
 * its callers). It lives as long as the process and is shared through
 * MAP_SHARED|MAP_ANONYMOUS with every FPM pool worker and ZTS thread the
 * master forks after MINIT; Windows has no fork, so there the mapping is
 * private to the process. Entries are never invalidated by the tracer, only
 * evicted by the approximate-LRU generational policy of
 * shm_generational_cache when an arena reaches
 * DDTRACE_NORMALIZED_ROUTE_CACHE_MAX_OCCUPANCY.
 *
 * Only values that are fully determined by their key may be stored here (no
 * per-request derived data), and each caller prefixes its keys with an
 * integration tag so that two integrations cannot collide on the same key.
 * Integrations that cache intermediate route data (Symfony route generator
 * entries, WordPress rule-safety flags) use tracer/routing_cache instead:
 * that cache is process-local and the exposed types are simpler. */

/* Rotation target entries per arena. There are three arenas; lookups probe
 * the current and the previous one, so the reachable budget is at most
 * 2 * DDTRACE_NORMALIZED_ROUTE_CACHE_MAX_OCCUPANCY entries at any time. */
#define DDTRACE_NORMALIZED_ROUTE_CACHE_MAX_OCCUPANCY 500

/* Longest key accepted by normalized_route_cache_set/get. Keys above this
 * return KEY_TOO_LARGE, which normalized_route_cache_get maps to false and
 * normalized_route_cache_set swallows (the cache is best-effort; a set does
 * not raise). */
#define DDTRACE_NORMALIZED_ROUTE_CACHE_MAX_KEY_SIZE 2048

/* Longest value accepted by normalized_route_cache_set. Values above this are
 * silently not stored. Normalized routes well over 4 KiB are extreme. */
#define DDTRACE_NORMALIZED_ROUTE_CACHE_MAX_VALUE_SIZE 4096

/* Process-wide init (before fork) and teardown. */
bool ddtrace_normalized_route_cache_minit(void);
void ddtrace_normalized_route_cache_mshutdown(void);

/* Per-thread handle lifecycle. The participant is registered lazily on the
 * first normalized_route_cache_get/set call on this thread, so ginit only
 * zeroes the field; gshutdown releases the slot if one was taken. */
void ddtrace_normalized_route_cache_ginit(struct ddog_sgc_Participant **participant);
void ddtrace_normalized_route_cache_gshutdown(struct ddog_sgc_Participant **participant);

PHP_FUNCTION(DDTrace_normalized_route_cache_get);
PHP_FUNCTION(DDTrace_normalized_route_cache_set);

#endif /* DDTRACE_NORMALIZED_ROUTE_CACHE_H */
