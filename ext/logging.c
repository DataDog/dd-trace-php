#include "logging.h"

#include <stdatomic.h>
#include <stdio.h>
#include <string.h>
#include <time.h>

#include "configuration.h"
#include <main/SAPI.h>

static inline ddog_CharSlice dd_zend_string_to_CharSlice(zend_string *str) {
    return (ddog_CharSlice){ .len = str->len, .ptr = str->val };
}

atomic_uintptr_t php_ini_error_log;

void ddtrace_bgs_log_minit(void) { atomic_store(&php_ini_error_log, (uintptr_t)NULL); }

void ddtrace_bgs_log_rinit(char *error_log) {
    if (!error_log || strcasecmp(error_log, "syslog") == 0 || strlen(error_log) == 0) {
        return;
    }

    uintptr_t desired = (uintptr_t)zend_strndup(error_log, strlen(error_log));
    uintptr_t expected = (uintptr_t)NULL;
    if (!atomic_compare_exchange_strong(&php_ini_error_log, &expected, desired)) {
        // if it didn't exchange, then we need to free our duplicated string
        free((char *)desired);
    }
}

void ddtrace_bgs_log_mshutdown(void) {
    char *error_log = (char *)atomic_load(&php_ini_error_log);
    atomic_store(&php_ini_error_log, (uintptr_t)NULL);
    free(error_log);
}

#undef ddtrace_bgs_logf
int ddtrace_bgs_logf(const char *fmt, ...) {
    int ret = 0;
    char *error_log = (char *)atomic_load(&php_ini_error_log);
    if (error_log) {
        FILE *fh = fopen(error_log, "a");

        if (fh) {
            va_list args, args_copy;
            va_start(args, fmt);

            va_copy(args_copy, args);
            int needed_len = vsnprintf(NULL, 0, fmt, args_copy);
            va_end(args_copy);

            char *msgbuf = malloc(needed_len);
            vsnprintf(msgbuf, needed_len, fmt, args);
            va_end(args);

            time_t now;
            time(&now);
            struct tm *now_local = localtime(&now);
            // todo: we only need 20-ish for the main part, but how much for the timezone?
            // Wish PHP printed -hhmm or +hhmm instead of the name
            char timebuf[64];
            int time_len = strftime(timebuf, sizeof timebuf, "%d-%b-%Y %H:%M:%S %Z", now_local);
            if (time_len > 0) {
                ret = fprintf(fh, "[%s] %s\n", timebuf, msgbuf);
            }

            free(msgbuf);
            fclose(fh);
        }
    }

    return ret;
}

static void ddtrace_log_callback(ddog_Log log, ddog_CharSlice msg) {
    const char *name = "debug";
    uint32_t bits = log.bits & ~ddog_Log_Once.bits;
    if (bits == (ddog_Log_Deprecated.bits & ~ddog_Log_Once.bits)) {
        name = "deprecated";
    } else if (bits == ddog_Log_Warn.bits) {
        name = "warning";
    } else if (bits == ddog_Log_Info.bits) {
        name = "info";
    } else if (bits == ddog_Log_Startup.bits) {
        name = "startup";
    } else {
        name = "error";
    }

    char *message;
    asprintf(&message, "[ddtrace] [%s] %.*s", name, (int)msg.len, msg.ptr);
    php_log_err(message);
    free(message);
}


void ddtrace_log_init(void) {
    ddog_log_callback = ddtrace_log_callback;
}

bool ddtrace_alter_dd_trace_debug(zval *old_value, zval *new_value) {
    UNUSED(old_value);

    /* Multiple log categories, maybe do it some day?
    ALLOCA_FLAG(use_heap);
    size_t num_categories = zend_hash_num_elements(Z_ARR_P(new_value));
    ddog_CharSlice *categories = do_alloca(num_categories * sizeof(ddog_CharSlice), use_heap);

    zend_string *log_category;
    int i = 0;
    ZEND_HASH_FOREACH_STR_KEY(Z_ARR_P(new_value), log_category) {
        categories[i++] = dd_zend_string_to_CharSlice(log_category);
    } ZEND_HASH_FOREACH_END();

    ddog_parse_log_categories(categories, num_categories, strcmp("cli", sapi_module.name) != 0 && get_global_DD_TRACE_STARTUP_LOGS());
    free_alloca(categories, use_heap);
    */

    ddog_Log log = ddog_Log_Error;
    if (Z_TYPE_P(new_value) == IS_TRUE) {
        log.bits = ddog_Log_Error.bits | ddog_Log_Warn.bits | ddog_Log_Deprecated.bits | ddog_Log_Info.bits;
        if (strcmp("cli", sapi_module.name) != 0 && (runtime_config_first_init ? get_DD_TRACE_STARTUP_LOGS() : get_global_DD_TRACE_STARTUP_LOGS())) {
            log.bits |= ddog_Log_Startup.bits;
        }
    }
    if (!(runtime_config_first_init ? get_DD_TRACE_ONCE_LOGS() : get_global_DD_TRACE_ONCE_LOGS())) {
        log.bits |= ddog_Log_Once.bits;
    }
    ddog_set_log_category(log);

    return true;
}
