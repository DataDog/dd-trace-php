#include <synchapi.h>

typedef RTL_RUN_ONCE pthread_once_t;
#define PTHREAD_ONCE_INIT {0}

static BOOL _pthread_once_cb(PINIT_ONCE once, PVOID cb, PVOID *context) {
    (void)context, (void)once;
    ((void(*)())cb)();
    return TRUE;
}

static int pthread_once(pthread_once_t *once, void (*cb)()) {
    InitOnceExecuteOnce(once, _pthread_once_cb, (PVOID)cb, NULL);
    return 0;
}
