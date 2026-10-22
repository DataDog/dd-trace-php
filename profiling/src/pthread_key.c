#include <errno.h>
#include <limits.h>
#include <pthread.h>
#include <stdbool.h>
#include <stddef.h>

// Rust also registers a pthread key for std::rt::thread_cleanup, independently
// of its __cxa_thread_atexit_impl destructors. That key does not keep this DSO
// loaded. FrankenPHP unloads extensions on php-main before that thread exits,
// so leaving the key registered would call into the unloaded profiler.
//
// --wrap confines this bookkeeping to keys created by the profiler and its
// statically linked dependencies. Other shared libraries keep their own keys.
int __real_pthread_key_create(pthread_key_t *key, void (*destructor)(void *));
int __real_pthread_key_delete(pthread_key_t key);

typedef struct {
    pthread_key_t key;
    void (*destructor)(void *);
    bool used;
} ddog_profiler_pthread_key;

static ddog_profiler_pthread_key ddog_profiler_keys[PTHREAD_KEYS_MAX];
static pthread_mutex_t ddog_profiler_keys_mutex = PTHREAD_MUTEX_INITIALIZER;

static void ddog_profiler_keys_lock(void) {
    pthread_mutex_lock(&ddog_profiler_keys_mutex);
}

static void ddog_profiler_keys_unlock(void) {
    pthread_mutex_unlock(&ddog_profiler_keys_mutex);
}

static void ddog_profiler_pthread_keys_register_atfork(void) {
    // Register before the profiler's fork handlers: its prepare hook pauses
    // threads before we lock, and its parent/child hooks run after we unlock.
    pthread_atfork(ddog_profiler_keys_lock, ddog_profiler_keys_unlock, ddog_profiler_keys_unlock);
}

void ddog_profiler_pthread_keys_startup(void) {
    static pthread_once_t once = PTHREAD_ONCE_INIT;
    pthread_once(&once, ddog_profiler_pthread_keys_register_atfork);
}

int __wrap_pthread_key_create(pthread_key_t *key, void (*destructor)(void *)) {
    int result = EAGAIN;
    pthread_mutex_lock(&ddog_profiler_keys_mutex);
    for (size_t i = 0; i < PTHREAD_KEYS_MAX; ++i) {
        if (!ddog_profiler_keys[i].used) {
            result = __real_pthread_key_create(key, destructor);
            if (result == 0) {
                ddog_profiler_keys[i] = (ddog_profiler_pthread_key){*key, destructor, true};
            }
            break;
        }
    }
    pthread_mutex_unlock(&ddog_profiler_keys_mutex);
    return result;
}

int __wrap_pthread_key_delete(pthread_key_t key) {
    pthread_mutex_lock(&ddog_profiler_keys_mutex);
    int result = __real_pthread_key_delete(key);
    if (result == 0) {
        for (size_t i = 0; i < PTHREAD_KEYS_MAX; ++i) {
            if (ddog_profiler_keys[i].used && ddog_profiler_keys[i].key == key) {
                ddog_profiler_keys[i].used = false;
                break;
            }
        }
    }
    pthread_mutex_unlock(&ddog_profiler_keys_mutex);
    return result;
}

// PHP threads and profiler threads have finished before the extension can be
// unloaded. Clean up the unloading thread's remaining values, then remove the
// keys themselves. Do this at DSO unload, not GSHUTDOWN: FrankenPHP can destroy
// and recreate PHP globals while keeping the underlying native thread alive.
__attribute__((destructor)) static void ddog_profiler_pthread_keys_shutdown(void) {
    for (size_t pass = 0; pass < PTHREAD_DESTRUCTOR_ITERATIONS; ++pass) {
        bool destroyed = false;
        for (size_t i = 0; i < PTHREAD_KEYS_MAX; ++i) {
            pthread_mutex_lock(&ddog_profiler_keys_mutex);
            ddog_profiler_pthread_key entry = ddog_profiler_keys[i];
            pthread_mutex_unlock(&ddog_profiler_keys_mutex);
            if (!entry.used || !entry.destructor) {
                continue;
            }
            void *value = pthread_getspecific(entry.key);
            if (value) {
                // Match pthread exit: a destructor may set its value again or
                // create/delete keys, so never invoke it while holding our lock.
                pthread_setspecific(entry.key, NULL);
                entry.destructor(value);
                destroyed = true;
            }
        }
        if (!destroyed) {
            break;
        }
    }

    pthread_mutex_lock(&ddog_profiler_keys_mutex);
    for (size_t i = 0; i < PTHREAD_KEYS_MAX; ++i) {
        if (ddog_profiler_keys[i].used) {
            __real_pthread_key_delete(ddog_profiler_keys[i].key);
            ddog_profiler_keys[i].used = false;
        }
    }
    pthread_mutex_unlock(&ddog_profiler_keys_mutex);
}
