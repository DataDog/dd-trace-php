#ifndef ZAI_OPCACHE_SYMBOLS_H
#define ZAI_OPCACHE_SYMBOLS_H

#include <stdbool.h>

void zai_jit_minit(void);

/* True when Zend OPcache is registered, including a built-in instance. */
bool zai_jit_opcache_loaded(void);

/* Resolve shared or built-in OPcache exports; return NULL for an absent symbol. */
void *zai_jit_fetch_opcache_symbol(const char *name);

#endif  // ZAI_OPCACHE_SYMBOLS_H
