#include "sampler.h"

#include <php.h>
#include <pthread.h>

// TODO Remove: Just for var_dump()'ing
#include <ext/standard/php_var.h>

#include "../logging.h"

/* Heavily inspired by Nikita Popov's sampling profiler
   https://github.com/nikic/sample_prof */

#define DD_SAMPLE_DEFAULT_INTERVAL 1000  // 1 millisecond

/* On 64-bit this will give a 16 * 1MB allocation */
#define DD_SAMPLE_DEFAULT_ALLOC (1 << 20)

ZEND_TLS pthread_t thread_id;
ZEND_TLS ddtrace_sample_entry *entries;
ZEND_TLS size_t entries_num;

static void *dd_sample_handler(void *data) {
#ifdef ZTS
    volatile zend_executor_globals *eg = TSRMG_BULK(executor_globals_id, zend_executor_globals *);
#else
    volatile zend_executor_globals *eg = &executor_globals;
#endif

retry_now:
    while (1) {
        volatile zend_execute_data *ex = eg->current_execute_data, *start_ex = ex;
        zend_function *func;
        const zend_op *opline;

        while (1) {
            zend_execute_data *prev;

            /* We're not executing code right now, try again later */
            if (!ex) {
                goto retry_later;
            }

            func = ex->func;
            opline = ex->opline;
            prev = ex->prev_execute_data;

            /* current_execute_data changed in the meantime, reload it */
            if (eg->current_execute_data != start_ex) {
                goto retry_now;
            }

            if (func && ZEND_USER_CODE(func->type)) {
                break;
            }

            ex = prev;
        }

        if (!opline) {
            goto retry_later;
        }

        entries[entries_num].filename = func->op_array.filename;
        entries[entries_num].lineno = opline->lineno;

        if (++entries_num == DD_SAMPLE_DEFAULT_ALLOC) {
            /* Doing a realloc within a signal handler is unsafe, end profiling */
            break;
        }

retry_later:
        usleep(DD_SAMPLE_DEFAULT_INTERVAL);
    }
    pthread_exit(NULL);
}

void ddtrace_sampler_rinit(void) {
    entries_num = 0;
    entries = safe_emalloc(DD_SAMPLE_DEFAULT_ALLOC, sizeof(ddtrace_sample_entry), 0);

    /* Register signal handler */
    if (pthread_create(&thread_id, NULL, dd_sample_handler, NULL)) {
        ddtrace_log_debugf("Could not register signal handler");
        return;
    }
}

void ddtrace_serialize_samples(HashTable *serialized) {
    size_t entry_num;

    for (entry_num = 0; entry_num < entries_num; ++entry_num) {
        ddtrace_sample_entry *entry = &entries[entry_num];
        zend_string *filename = entry->filename;
        uint32_t lineno = entry->lineno;
        zval *lines, *num;

        lines = zend_hash_find(serialized, filename);
        if (lines == NULL) {
            zval lines_zv;
            array_init(&lines_zv);
            lines = zend_hash_update(serialized, filename, &lines_zv);
        }

        num = zend_hash_index_find(Z_ARR_P(lines), lineno);
        if (num == NULL) {
            zval num_zv;
            ZVAL_LONG(&num_zv, 0);
            num = zend_hash_index_update(Z_ARR_P(lines), lineno, &num_zv);
        }

        increment_function(num);
    }
}

void ddtrace_sampler_rshutdown(void) {
    /*
    zval serialized;
    array_init(&serialized);
    ddtrace_serialize_samples(Z_ARR(serialized));

    // For now we'll just dump them to STDOUT
    php_printf("Took %zu samples:", entries_num);
    php_var_dump(&serialized, 1);

    zend_array_destroy(Z_ARR(serialized));
    */

    pthread_cancel(thread_id);
    efree(entries);
}
