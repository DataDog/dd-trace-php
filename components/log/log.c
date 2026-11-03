#include "log.h"
#include <stdio.h>
#include <stdarg.h>
#include <stdlib.h>

#ifndef _WIN32
__thread ddog_Log _ddog_log_source_value;
#else
__declspec(thread) ddog_Log _ddog_log_source_value;
#endif

static void ddog_logf_va(ddog_Log source, bool once, const char *format, va_list va) {
    char buf[0x100];
    va_list va2;
    va_copy(va2, va);
    int len = vsnprintf(buf, sizeof(buf), format, va);
    if (len < 0) {
        va_end(va2);
        return;
    }
    if (len >= (int)sizeof(buf)) {
        char *msg = malloc(len + 1);
        if (!msg) {
            va_end(va2);
            return;
        }
        len = vsnprintf(msg, len + 1, format, va2);
        if (len >= 0) {
            ddog_log(source, once || (source & ddog_LOG_ONCE), (ddog_CharSlice){ .ptr = msg, .len = (uintptr_t)len });
        }
        free(msg);
    } else {
        ddog_log(source, once || (source & ddog_LOG_ONCE), (ddog_CharSlice){ .ptr = buf, .len = (uintptr_t)len });
    }
    va_end(va2);
}

void ddog_logf(ddog_Log source, bool once, const char *format, ...) {
    va_list va;
    va_start(va, format);
    ddog_logf_va(source, once, format, va);
    va_end(va);
}

void _ddog_log_source(const char *format, ...) {
    va_list va;
    va_start(va, format);
    ddog_logf_va(_ddog_log_source_value, false, format, va);
    va_end(va);
}
