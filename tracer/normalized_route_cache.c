#include "normalized_route_cache.h"
#include "ddtrace.h"
#ifndef _WIN32
#include <unistd.h>
#endif

ZEND_EXTERN_MODULE_GLOBALS(datadog);

/* Process-local handle for the shared mapping. ddog_sgc_cache_new installs the
 * mapping once in the master; every child inherits it along with this pointer. */
static struct ddog_sgc_Cache *ddtrace_normalized_route_cache = NULL;

/* FNV-1a 64. Stable across architectures and across pool workers (unlike
 * zend_string_hash_val, which is zend_ulong-wide and so differs between 32
 * and 64 bit builds). Weakness does not matter here: shm_generational_cache
 * uses the hash to pick a bucket and compares keys on hit. */
static inline uint64_t ddtrace_normalized_route_cache_hash(const uint8_t *data, size_t len) {
    uint64_t h = 0xcbf29ce484222325ULL;
    for (size_t i = 0; i < len; i++) {
        h ^= data[i];
        h *= 0x100000001b3ULL;
    }
    return h;
}

static struct ddog_sgc_Participant *ddtrace_normalized_route_cache_participant(void) {
    if (!ddtrace_normalized_route_cache) {
        return NULL;
    }

    pid_t pid =
#ifdef _WIN32
        /* No fork; the participant is always valid for the life of the process. */
        1;
#else
        getpid();
#endif

    struct ddog_sgc_Participant **p = &DDTRACE_G(nrcache_participant);
    pid_t *recorded_pid = &DDTRACE_G(nrcache_participant_pid);

    if (*p) {
        if (*recorded_pid == pid) {
            return *p;
        }
        /* Inherited by pcntl_fork. The crate's unregister on an inherited
         * handle "only frees the handle" without touching the parent's slot.
         * Re-register below for the child's own slot. */
        ddog_sgc_participant_unregister(*p);
        *p = NULL;
    }

    struct ddog_sgc_Participant *registered = NULL;
    if (ddog_sgc_participant_register(ddtrace_normalized_route_cache, &registered) != DDOG_SGC_STATUS_OK) {
        return NULL;
    }
    *p = registered;
    *recorded_pid = pid;
    return registered;
}

bool ddtrace_normalized_route_cache_minit(void) {
    if (ddtrace_normalized_route_cache) {
        return true;
    }
    struct ddog_sgc_Config config = {
        /* One slot per thread or per pool worker that will touch the cache
         * from the master's descendants. 128 covers a typical FPM pool and
         * every ZTS thread the master is expected to spawn. */
        .participant_capacity = 128,
        /* Power of two and a multiple of 8. 1024 buckets with a 70% rotation
         * target gives ~716 entries per arena at the default max_occupancy;
         * we lower max_occupancy below to keep the reachable budget close to
         * the previous per-process cache's 500. */
        .bucket_count = 1024,
        .max_key_size = DDTRACE_NORMALIZED_ROUTE_CACHE_MAX_KEY_SIZE,
        .max_value_size = DDTRACE_NORMALIZED_ROUTE_CACHE_MAX_VALUE_SIZE,
        .record_area_size = 0,
        .max_occupancy = DDTRACE_NORMALIZED_ROUTE_CACHE_MAX_OCCUPANCY,
        .reservation_chunk_size = 0,
        .always_exact_occupancy = false,
    };
    struct ddog_sgc_Cache *cache = NULL;
    if (ddog_sgc_cache_new(&config, &cache) != DDOG_SGC_STATUS_OK) {
        return false;
    }
    ddtrace_normalized_route_cache = cache;
    return true;
}

void ddtrace_normalized_route_cache_mshutdown(void) {
    if (ddtrace_normalized_route_cache) {
        ddog_sgc_cache_free(ddtrace_normalized_route_cache);
        ddtrace_normalized_route_cache = NULL;
    }
}

void ddtrace_normalized_route_cache_ginit(struct ddog_sgc_Participant **participant) {
    *participant = NULL;
}

void ddtrace_normalized_route_cache_gshutdown(struct ddog_sgc_Participant **participant) {
    if (*participant && ddtrace_normalized_route_cache) {
        /* The cache is still mapped. In a pcntl_fork child the handle is
         * inherited and unregister only frees the handle (the parent's slot
         * is left alone); in the registering thread it also releases the
         * slot, which is what we want. */
        ddog_sgc_participant_unregister(*participant);
    }
    *participant = NULL;
}

/* DDTrace\normalized_route_cache_get(string $key): string|false */
PHP_FUNCTION(DDTrace_normalized_route_cache_get) {
    zend_string *key;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(key)
    ZEND_PARSE_PARAMETERS_END();

    struct ddog_sgc_Participant *participant = ddtrace_normalized_route_cache_participant();
    if (!participant) {
        RETURN_FALSE;
    }

    uint64_t hash = ddtrace_normalized_route_cache_hash((const uint8_t *)ZSTR_VAL(key), ZSTR_LEN(key));

    /* Stack buffer sized to the configured max value; a hit never exceeds it
     * (CORRUPT otherwise), and every non-OK status is treated as a miss. The
     * buffer is uint64_t to satisfy ddog_sgc_lookup's 8-byte alignment. */
    uint64_t out_words[DDTRACE_NORMALIZED_ROUTE_CACHE_MAX_VALUE_SIZE / sizeof(uint64_t)];
    size_t value_len = 0;
    enum ddog_sgc_Status status = ddog_sgc_lookup(
        participant,
        hash,
        (const uint8_t *)ZSTR_VAL(key),
        ZSTR_LEN(key),
        out_words,
        sizeof(out_words),
        &value_len
    );
    if (status != DDOG_SGC_STATUS_OK) {
        RETURN_FALSE;
    }
    RETURN_STRINGL((const char *)out_words, value_len);
}

/* DDTrace\normalized_route_cache_set(string $key, string $value): void */
PHP_FUNCTION(DDTrace_normalized_route_cache_set) {
    zend_string *key, *value;
    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_STR(key)
        Z_PARAM_STR(value)
    ZEND_PARSE_PARAMETERS_END();

    struct ddog_sgc_Participant *participant = ddtrace_normalized_route_cache_participant();
    if (!participant) {
        return;
    }

    uint64_t hash = ddtrace_normalized_route_cache_hash((const uint8_t *)ZSTR_VAL(key), ZSTR_LEN(key));

    /* Oversized keys/values are dropped; the cache is best-effort and a set
     * never raises an exception. */
    (void)ddog_sgc_insert(
        participant,
        hash,
        (const uint8_t *)ZSTR_VAL(key),
        ZSTR_LEN(key),
        (const uint8_t *)ZSTR_VAL(value),
        ZSTR_LEN(value)
    );
}
