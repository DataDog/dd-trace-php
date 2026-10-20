#ifndef DDTRACE_ROUTING_CACHE_H
#define DDTRACE_ROUTING_CACHE_H

#include <php.h>

/* A string -> string map that outlives requests: it lives as long as the
 * process (the thread under ZTS) and is never invalidated. Beyond
 * DDTRACE_ROUTING_CACHE_CAPACITY entries, inserting a new key evicts the
 * oldest insertion (FIFO; lookups do not refresh an entry).
 *
 * Because nothing is ever invalidated, a key must determine its value: it has
 * to encode everything the cached value depends on, for any application the
 * process may serve. Several integrations, and several applications, can share
 * one process, so each integration starts its keys with its own prefix
 * ("laminas\0", "laravel\0", "symfony:v2\0"). */
#define DDTRACE_ROUTING_CACHE_CAPACITY 500

void ddtrace_routing_cache_ginit(HashTable *rcache);
void ddtrace_routing_cache_gshutdown(HashTable *rcache);

PHP_FUNCTION(DDTrace_routing_cache_get);
PHP_FUNCTION(DDTrace_routing_cache_set);

#endif /* DDTRACE_ROUTING_CACHE_H */
