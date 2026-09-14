#include "threads.h"
#define zend_signal_globals_id zend_signal_globals_id_dummy
#define zend_signal_globals_offset zend_signal_globals_offset_dummy
#define zend_signal_handler_unblock zend_signal_handler_unblock_dummy
#include <Zend/zend.h>
#undef zend_signal_globals_id
#undef zend_signal_globals_offset
#undef zend_signal_handler_unblock
#include "datadog.h"

#if ZTS

#ifdef ZEND_SIGNALS
#ifdef __APPLE__
extern __attribute__((weak, weak_import)) int zend_signal_globals_id;
extern __attribute__((weak, weak_import)) size_t zend_signal_globals_offset;
#elif defined(_WIN32)
#error "Found zend_signals under windows!?"
#else
__attribute__((weak)) int zend_signal_globals_id;
__attribute__((weak)) size_t zend_signal_globals_offset;
#endif

__attribute__((weak)) void zend_signal_handler_unblock(void);
#endif

HashTable datadog_tls_bases; // map thread id to TSRMLS_CACHE
MUTEX_T datadog_threads_mutex = NULL;

void datadog_thread_ginit() {
    if (!datadog_threads_mutex) {
        datadog_threads_mutex = tsrm_mutex_alloc();
        zend_hash_init(&datadog_tls_bases, 8, NULL, NULL, 1);
    }

#ifdef ZEND_SIGNALS
    // avoid deadlocks due to signal handlers accessing this
    if (zend_signal_globals_id) {
        HANDLE_BLOCK_INTERRUPTIONS();
    }
#endif
    tsrm_mutex_lock(datadog_threads_mutex);

    zend_hash_index_add_new_ptr(&datadog_tls_bases, (zend_ulong)(uintptr_t)tsrm_thread_id(), TSRMLS_CACHE);

    tsrm_mutex_unlock(datadog_threads_mutex);
#ifdef ZEND_SIGNALS
    if (zend_signal_globals_id) {
        HANDLE_UNBLOCK_INTERRUPTIONS();
    }
#endif
}

void datadog_thread_gshutdown() {
    if (datadog_threads_mutex) {
#ifdef ZEND_SIGNALS
        // avoid deadlocks due to signal handlers accessing this
        if (zend_signal_globals_id) {
            HANDLE_BLOCK_INTERRUPTIONS();
        }
#endif
        tsrm_mutex_lock(datadog_threads_mutex);

        zend_hash_index_del(&datadog_tls_bases, (zend_ulong)(uintptr_t)tsrm_thread_id());

        tsrm_mutex_unlock(datadog_threads_mutex);
#ifdef ZEND_SIGNALS
        if (zend_signal_globals_id) {
            HANDLE_UNBLOCK_INTERRUPTIONS();
        }
#endif

        if (zend_hash_num_elements(&datadog_tls_bases) == 0) {
            tsrm_mutex_free(datadog_threads_mutex);
            datadog_threads_mutex = NULL;
            zend_hash_destroy(&datadog_tls_bases);
        }
    }
}

#else

MUTEX_T tsrm_mutex_alloc(void)
{/*{{{*/
    MUTEX_T mutexp;
#ifdef TSRM_WIN32
    mutexp = malloc(sizeof(CRITICAL_SECTION));
    InitializeCriticalSection(mutexp);
#else
    mutexp = (pthread_mutex_t *)malloc(sizeof(pthread_mutex_t));
    pthread_mutex_init(mutexp,NULL);
#endif
    return( mutexp );
}/*}}}*/


/* Free a mutex */
void tsrm_mutex_free(MUTEX_T mutexp)
{/*{{{*/
    if (mutexp) {
#ifdef TSRM_WIN32
        DeleteCriticalSection(mutexp);
        free(mutexp);
#else
        pthread_mutex_destroy(mutexp);
        free(mutexp);
#endif
    }
}/*}}}*/


/*
  Lock a mutex.
  A return value of 0 indicates success
*/
int tsrm_mutex_lock(MUTEX_T mutexp)
{/*{{{*/
#ifdef TSRM_WIN32
    EnterCriticalSection(mutexp);
    return 0;
#else
    return pthread_mutex_lock(mutexp);
#endif
}/*}}}*/


/*
  Unlock a mutex.
  A return value of 0 indicates success
*/
int tsrm_mutex_unlock(MUTEX_T mutexp)
{/*{{{*/
#ifdef TSRM_WIN32
    LeaveCriticalSection(mutexp);
	return 0;
#else
    return pthread_mutex_unlock(mutexp);
#endif
}/*}}}*/


#endif

#ifdef __linux__

/* datadog_clone_thread(): clone(2), issued directly rather than through libc.
 *
 * We cannot use the libc wrapper. musl refuses CLONE_THREAD (as well as CLONE_SETTLS and CLONE_CHILD_CLEARTID) with EINVAL and never issues the syscall at all.
 * Hence we have to do the gruntwork ourselves, to work around that musl limitation.
 */

#if defined(__x86_64__)
__asm__(
    ".text\n"
    ".globl datadog_clone_thread\n"
    ".hidden datadog_clone_thread\n"
    ".type datadog_clone_thread,@function\n"
    "datadog_clone_thread:\n"
    /* in: rdi = fn, rsi = stack_top, edx = flags, rcx = arg */
    "   andq  $-16, %rsi\n"           /* align the child stack */
    "   subq  $16, %rsi\n"            /* hand fn and arg over on it */
    "   movq  %rdi, 0(%rsi)\n"
    "   movq  %rcx, 8(%rsi)\n"
    /* syscall: rdi = flags, rsi = newsp, rdx = parent_tid, r10 = child_tid, r8 = tls */
    "   movl  %edx, %edi\n"
    "   xorl  %edx, %edx\n"
    "   xorl  %r10d, %r10d\n"
    "   xorl  %r8d, %r8d\n"
    "   movl  $56, %eax\n"            /* SYS_clone */
    "   syscall\n"
    "   testq %rax, %rax\n"           /* parent: tid or -errno; child: 0 */
    "   jnz   1f\n"
    "   xorl  %ebp, %ebp\n"           /* end the frame pointer chain */
    "   popq  %rax\n"                 /* fn */
    "   popq  %rdi\n"                 /* arg */
    "   callq *%rax\n"
    "   movl  %eax, %edi\n"           /* fn's return value is the thread's exit status */
    "   movl  $60, %eax\n"            /* SYS_exit -- this thread only, not exit_group */
    "   syscall\n"
    "   hlt\n"                        /* unreachable */
    "1: ret\n"
    ".size datadog_clone_thread,.-datadog_clone_thread\n");
#elif defined(__aarch64__)
__asm__(
    ".text\n"
    ".globl datadog_clone_thread\n"
    ".hidden datadog_clone_thread\n"
    ".type datadog_clone_thread,%function\n"
    "datadog_clone_thread:\n"
    /* in: x0 = fn, x1 = stack_top, w2 = flags, x3 = arg */
    "   and   x1, x1, #-16\n"         /* align the child stack */
    "   stp   x0, x3, [x1, #-16]!\n"  /* hand fn and arg over on it; x1 becomes newsp */
    /* syscall: x0 = flags, x1 = newsp, x2 = parent_tid, x3 = tls, x4 = child_tid */
    "   mov   w0, w2\n"
    "   mov   x2, #0\n"
    "   mov   x3, #0\n"
    "   mov   x4, #0\n"
    "   mov   x8, #220\n"             /* SYS_clone */
    "   svc   #0\n"
    "   cbz   x0, 1f\n"               /* parent: tid or -errno; child: 0 */
    "   ret\n"
    "1: ldp   x1, x0, [sp], #16\n"    /* x1 = fn, x0 = arg */
    "   mov   x29, #0\n"              /* end the frame pointer chain */
    "   blr   x1\n"                   /* fn's return value is left in w0 */
    "   mov   w8, #93\n"              /* SYS_exit -- this thread only, not exit_group */
    "   svc   #0\n"
    "   brk   #0\n"                   /* unreachable */
    ".size datadog_clone_thread,.-datadog_clone_thread\n");
#else
#include <sched.h>
int datadog_clone_thread(int (*fn)(void *), void *stack_top, int flags, void *arg) {
    return clone(fn, stack_top, flags, arg);
}
#endif

#endif
