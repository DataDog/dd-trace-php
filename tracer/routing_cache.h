#ifndef DDTRACE_ROUTING_CACHE_H
#define DDTRACE_ROUTING_CACHE_H

#include <php.h>

#define DDTRACE_ROUTING_CACHE_CAPACITY 500

void ddtrace_routing_cache_ginit(void);
void ddtrace_routing_cache_gshutdown(void);

PHP_FUNCTION(DDTrace_routing_cache_get);
PHP_FUNCTION(DDTrace_routing_cache_set);

#endif /* DDTRACE_ROUTING_CACHE_H */
