#include "profiling.h"

#include <Zend/zend_extensions.h>
#include <php_config.h>

#include <cstdio>

ZEND_TLS datadog_php_stack_sample last_stack_sample;

ZEND_API datadog_php_stack_sample tea_get_last_stack_sample(void) { return last_stack_sample; }

ZEND_API void datadog_profiling_interrupt_function(zend_execute_data *execute_data) {
    datadog_php_stack_sample_ctor(&last_stack_sample);

    /* Don't try to re-implement everything. Remember, the tracer is being
     * tested here, not the profiler!
     */
    while (execute_data) {
        datadog_php_stack_sample_frame frame = {DATADOG_PHP_STRING_VIEW_INIT, DATADOG_PHP_STRING_VIEW_INIT, 0};

        if (execute_data->func && execute_data->func->common.function_name) {
            frame.function.ptr = ZSTR_VAL(execute_data->func->common.function_name);
            frame.function.len = ZSTR_LEN(execute_data->func->common.function_name);
        } else {
            frame.function.ptr = "<php>";
            frame.function.len = sizeof("<php>") - 1;
        }

        if (!datadog_php_stack_sample_try_add(&last_stack_sample, frame)) {
            break;
        }

        execute_data = execute_data->prev_execute_data;
    }
}

ZEND_API zend_extension_version_info extension_version_info = {
    ZEND_EXTENSION_API_NO,
    ZEND_EXTENSION_BUILD_ID,
};

static void profiling_activate(void) { datadog_php_stack_sample_ctor(&last_stack_sample); }

static void profiling_deactivate(void) { datadog_php_stack_sample_dtor(&last_stack_sample); }

static void profiling_message_handler(int code, void *ext) {
    zend_extension *extension = static_cast<zend_extension *>(ext);
    fprintf(stderr, "Tea profiling message_handler(%d, %p) (name: %s)\n", code, ext, extension->name);
}

ZEND_API zend_extension zend_extension_entry = {
    "datadog-profiling",
    NULL, /* is version allowed to be null? */
    "Datadog",
    "https://github.com/Datadog/dd-trace-php",
    "Datadog",
    NULL,
    NULL,
    profiling_activate,
    profiling_deactivate,
    profiling_message_handler,
    NULL,
    NULL,
    NULL,
    NULL,
    NULL,
    NULL,
    STANDARD_ZEND_EXTENSION_PROPERTIES,
};
