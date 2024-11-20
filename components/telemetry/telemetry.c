#include "telemetry.h"
#include "../components-rs/ddtrace.h"
#include <stdio.h>
#include <stdarg.h>
#include <stdlib.h>
#include <string.h>

static void ddog_telemetryf_va(const char* format, va_list va, void (*telemetry_handler)(ddog_CharSlice)) {
    char buf[0x100];
    va_list va2;
    va_copy(va2, va);
    int len = vsnprintf(buf, sizeof(buf), format, va);
    if (len > (int)sizeof(buf)) {
        char *msg = malloc(len + 1);
        len = vsnprintf(msg, len + 1, format, va2);
        // The function should not be able to crash
        telemetry_handler((ddog_CharSlice){ .ptr = msg, .len = (uintptr_t)len });
        free(msg);
    } else {
        telemetry_handler((ddog_CharSlice){ .ptr = buf, .len = (uintptr_t)len });
    }
    va_end(va2);
}

void ddog_integration_error_telemetryf(const char* format, ...) {
    va_list va;
    va_start(va, format);
    ddog_telemetryf_va(format, va, ddog_add_integration_error_log);
    va_end(va);
}

const char* ddog_telemetry_redact_file(const char* file) {
    const char* redacted_substring = strstr(file, "/DDTrace");
    if (redacted_substring != NULL) {
        return redacted_substring;
    } else {
        // Should not happen but will serve as a gate keepers
        const char * php_file_name = strrchr(file, '/');
        if (php_file_name) {
            return php_file_name;
        }
        return "";
    }
}