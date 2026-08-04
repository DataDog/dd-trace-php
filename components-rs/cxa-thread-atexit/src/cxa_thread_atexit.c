/*
 * Our replacement for glibc's __cxa_thread_atexit_impl. See src/lib.rs for why we want one.
 *
 * This lives in C rather than in Rust because the override only works if the definition is
 * *hidden*: Rust's std references the symbol weakly through the GOT, and a default visibility
 * definition stays preemptible, so it would be resolved at load time against the global scope,
 * where glibc always wins (it is loaded long before us). Stable Rust cannot mark a single symbol
 * hidden, and a version script is not an option either -- rustc already passes its own, anonymous,
 * one and GNU ld refuses to combine that with a second script. -fvisibility=hidden on this file
 * gives us the property by construction, which is also how ddtrace.so has been doing it.
 */

#include <pthread.h>
#include <stdbool.h>
#include <stddef.h>
#include <stdlib.h>

typedef void (*dd_dtor)(void *);

/*
 * One registered destructor. Allocated with malloc rather than through Rust's global allocator:
 * the profiler replaces that allocator, and an allocator that itself touches a thread-local would
 * re-enter this function from inside a registration.
 */
struct dd_destructor {
    dd_dtor dtor;
    void *obj;
    struct dd_destructor *next;
};

/* Holds the head of the calling thread's list, so that glibc runs dd_run_destructors on exit. */
static pthread_key_t dd_key;
static bool dd_key_valid = false;
static pthread_once_t dd_key_once = PTHREAD_ONCE_INIT;
/* Set once this library is going away: from then on we must not promise to run anything later. */
static bool dd_disarmed = false;

/* Mirrors glibc's PTHREAD_DESTRUCTOR_ITERATIONS: destructors may register new destructors. */
#define DD_MAX_ROUNDS 4

static void dd_drain(struct dd_destructor *entry);
static void dd_run_destructors(void *head);
static void dd_flush_and_disarm(void);

static void dd_create_key(void) {
    if (pthread_key_create(&dd_key, dd_run_destructors) != 0) {
        return;
    }
    dd_key_valid = true;
    /*
     * In a shared object this is __cxa_atexit(__dso_handle), so it covers both process exit --
     * where the main thread's pthread_key destructors are never invoked -- and dlclose() of this
     * library.
     */
    atexit(dd_flush_and_disarm);
}

void dd_cxa_thread_atexit_init(void) { pthread_once(&dd_key_once, dd_create_key); }

/* Not public; the whole point is that it stays local to this shared object. */
int __cxa_thread_atexit_impl(dd_dtor dtor, void *obj, void *dso_symbol) {
    (void)dso_symbol;

    /*
     * We are being unloaded and nobody will be around to run this later. Leaking the value beats
     * both running the destructor when its data may already be gone, and promising to run it from
     * an image that is about to be unmapped.
     */
    if (dd_disarmed) {
        return 0;
    }

    dd_cxa_thread_atexit_init();
    if (!dd_key_valid) {
        return 0; /* Same reasoning: no key means no way to ever run it. */
    }

    struct dd_destructor *entry = malloc(sizeof(*entry));
    if (entry == NULL) {
        return 0;
    }
    entry->dtor = dtor;
    entry->obj = obj;
    entry->next = pthread_getspecific(dd_key);
    if (pthread_setspecific(dd_key, entry) != 0) {
        free(entry);
    }
    return 0;
}

/*
 * glibc hands us the thread's list on thread exit, having already reset the value to NULL, so
 * destructors registered by a destructor build a fresh list that glibc picks up in one of its
 * remaining iterations.
 */
static void dd_run_destructors(void *head) { dd_drain(head); }

/* Runs and frees the list, which is in reverse registration order because we push to the front. */
static void dd_drain(struct dd_destructor *entry) {
    while (entry != NULL) {
        struct dd_destructor *current = entry;
        entry = entry->next;
        current->dtor(current->obj);
        free(current);
    }
}

void dd_cxa_thread_atexit_flush(void) {
    if (!dd_key_valid) {
        return;
    }

    for (int round = 0; round < DD_MAX_ROUNDS; ++round) {
        struct dd_destructor *head = pthread_getspecific(dd_key);
        if (head == NULL) {
            return;
        }
        /* Take the list out first, so destructors that register more don't see a stale one. */
        pthread_setspecific(dd_key, NULL);
        dd_drain(head);
    }
}

/* Runs at process exit and when this library is unloaded, whichever comes first. */
static void dd_flush_and_disarm(void) {
    dd_disarmed = true;
    dd_cxa_thread_atexit_flush();

    /*
     * Stop glibc from ever calling dd_run_destructors -- which lives in this image -- for the
     * values other threads may still hold. Those leak; a call through a dangling pointer would
     * take the process down instead.
     */
    if (dd_key_valid) {
        dd_key_valid = false;
        pthread_key_delete(dd_key);
    }
}
