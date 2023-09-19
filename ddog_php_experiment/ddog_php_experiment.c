/* ddog_php_experiment extension for PHP (c) 2023 Levi Morrison <levi.morrison@datadoghq.com> */

#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include <signal.h>
#include <stdatomic.h>
#include <sys/syscall.h>
#include <sys/time.h>

// clang-format off
#include <php.h>
#include <ext/standard/info.h>
#include "php_ddog_php_experiment.h"
#include "ddog_php_experiment_arginfo.h"
// clang-format on

/* For compatibility with older PHP versions */
#ifndef ZEND_PARSE_PARAMETERS_NONE
#define ZEND_PARSE_PARAMETERS_NONE()                                                               \
    ZEND_PARSE_PARAMETERS_START(0, 0)                                                              \
    ZEND_PARSE_PARAMETERS_END()
#endif

/* {{{ void test1() */
PHP_FUNCTION(test1) {
    ZEND_PARSE_PARAMETERS_NONE();

    php_printf("The extension %s is loaded and working!\r\n", "ddog_php_experiment");
}
/* }}} */

/* {{{ string test2( [ string $var ] ) */
PHP_FUNCTION(test2) {
    char *var = "World";
    size_t var_len = sizeof("World") - 1;
    zend_string *retval;

    ZEND_PARSE_PARAMETERS_START(0, 1)
    Z_PARAM_OPTIONAL
    Z_PARAM_STRING(var, var_len)
    ZEND_PARSE_PARAMETERS_END();

    retval = strpprintf(0, "Hello %s", var);

    RETURN_STR(retval);
}
/* }}}*/

pid_t ddog_php_experiment_gettid(void) { return syscall(SYS_gettid); }

struct ddog_php_experiment_wall_time_context {
    zend_atomic_bool *vm_interrupt_addr;
    _Atomic uint32_t *n_interrupts;
};

ZEND_TLS struct ddog_php_experiment_wall_time_context WALL_TIME_CONTEXT;
ZEND_TLS timer_t WALL_TIME_TIMER_ID;

#define WALL_TIME_SIGNO (SIGRTMIN + 0)

void ddog_php_experiment_wall_time_action(int sig, siginfo_t *info, void *ucontext) {
    struct ddog_php_experiment_wall_time_context *wall_time_context =
        (struct ddog_php_experiment_wall_time_context *)info->si_value.sival_ptr;
    atomic_fetch_add(&wall_time_context->n_interrupts, 1);
    zend_atomic_bool_store(wall_time_context->vm_interrupt_addr, true);
}

/* {{{ PHP_RINIT_FUNCTION */
PHP_RINIT_FUNCTION(ddog_php_experiment) {
#if defined(ZTS) && defined(COMPILE_DL_DDOG_PHP_EXPERIMENT)
    ZEND_TSRMLS_CACHE_UPDATE();
#endif

    WALL_TIME_CONTEXT.vm_interrupt_addr = &EG(vm_interrupt);
    WALL_TIME_CONTEXT.n_interrupts = 0;

    struct sigaction action = {
        .sa_flags = SA_RESTART | SA_SIGINFO,
        .sa_sigaction = ddog_php_experiment_wall_time_action,
    };
    if (sigemptyset(&action.sa_mask) == -1) {
        return FAILURE;
    }

    if (sigaction(WALL_TIME_SIGNO, &action, NULL) == -1) {
        return FAILURE;
    }

    struct sigevent sev = {.sigev_signo = WALL_TIME_SIGNO,
                           .sigev_notify = SIGEV_THREAD_ID,
                           .sigev_value = {.sival_ptr = &WALL_TIME_CONTEXT},
                           ._sigev_un = {
                               ._tid = ddog_php_experiment_gettid(),
                           }};

    if (timer_create(CLOCK_REALTIME, &sev, &WALL_TIME_TIMER_ID) == -1) {
        return FAILURE;
    }

    struct itimerspec its = {.it_value =
                                 {
                                     .tv_sec = 0,
                                     .tv_nsec = 10L * 1000L * 1000L,
                                 },
                             .it_interval = {
                                 .tv_sec = 0,
                                 .tv_nsec = 10L * 1000L * 1000L,
                             }};
    if (timer_settime(WALL_TIME_TIMER_ID, 0, &its, NULL) == -1) {
        return FAILURE;
    }

    return SUCCESS;
}
/* }}} */

/* {{{ PHP_RSHUTDOWN_FUNCTION */
PHP_RSHUTDOWN_FUNCTION(ddog_php_experiment) {
    // Disarm the timer in shutdown, delete it in prshutdown. This gives the
    // OS time to disarm the signal and allow any pending signals to be
    // delivered before the timer is deleted.
    struct itimerspec its = {.it_value = {.tv_sec = 0, .tv_nsec = 0},
                             .it_interval = {.tv_sec = 0, .tv_nsec = 0}};
    if (timer_settime(WALL_TIME_TIMER_ID, 0, &its, NULL) == -1) {
        return FAILURE;
    }
    return SUCCESS;
}
/* }}} */

zend_result ddog_php_experiment_prshutdown(void) {
    if (timer_delete(WALL_TIME_TIMER_ID) == -1) {
        return FAILURE;
    }
    return SUCCESS;
}

/* {{{ PHP_MINFO_FUNCTION */
PHP_MINFO_FUNCTION(ddog_php_experiment) {
    php_info_print_table_start();
    php_info_print_table_header(2, "ddog_php_experiment support", "enabled");
    php_info_print_table_end();
}
/* }}} */

ZEND_API void (*og_interrupt_function)(zend_execute_data *execute_data);

void ddog_php_experiment_interrupt_function(zend_execute_data *execute_data) {
    fprintf(stderr, "interrupt\n");
}

void ddog_php_experiment_interrupt_function_helper(zend_execute_data *execute_data) {
    ddog_php_experiment_interrupt_function(execute_data);

    if (og_interrupt_function != NULL) {
        og_interrupt_function(execute_data);
    }
}

PHP_MINIT_FUNCTION(ddog_php_experiment) {
    og_interrupt_function = zend_interrupt_function;
    zend_interrupt_function = og_interrupt_function ? ddog_php_experiment_interrupt_function_helper
                                                    : ddog_php_experiment_interrupt_function;
    return SUCCESS;
}

/* {{{ ddog_php_experiment_module_entry */
zend_module_entry ddog_php_experiment_module_entry = {
    sizeof(zend_module_entry),
    ZEND_MODULE_API_NO,
    ZEND_DEBUG,
    USING_ZTS,
    NULL,
    NULL,
    "ddog_php_experiment",              /* Extension name */
    ext_functions,                      /* zend_function_entry */
    PHP_MINIT(ddog_php_experiment),     /* PHP_MINIT - Module initialization */
    NULL,                               /* PHP_MSHUTDOWN - Module shutdown */
    PHP_RINIT(ddog_php_experiment),     /* PHP_RINIT - Request initialization */
    PHP_RSHUTDOWN(ddog_php_experiment), /* PHP_RSHUTDOWN - Request shutdown */
    PHP_MINFO(ddog_php_experiment),     /* PHP_MINFO - Module info */
    PHP_DDOG_PHP_EXPERIMENT_VERSION,    /* Version */
    0,
    NULL,
    NULL,
    NULL,
    ddog_php_experiment_prshutdown,
    0,
    0,
    NULL,
    0,
    ZEND_MODULE_BUILD_ID};
/* }}} */

#ifdef COMPILE_DL_DDOG_PHP_EXPERIMENT
#ifdef ZTS
ZEND_TSRMLS_CACHE_DEFINE()
#endif
ZEND_GET_MODULE(ddog_php_experiment)
#endif
