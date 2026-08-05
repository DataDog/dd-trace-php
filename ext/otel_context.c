#include "otel_context.h"

#include <limits.h>
#include <unistd.h>

#include "datadog.h"
#include "ffi_utils.h"
#include "process_tags.h"
#include <components-rs/datadog.h>

void datadog_otel_process_context_publish(void) {
    char detected_hostname[HOST_NAME_MAX + 1] = {0};
    ddog_CharSlice hostname = {0};
    if (gethostname(detected_hostname, HOST_NAME_MAX) == 0) {
        hostname = (ddog_CharSlice){
            .ptr = detected_hostname,
            .len = strnlen(detected_hostname, HOST_NAME_MAX),
        };
    }

    zend_string *process_tags = datadog_process_tags_get_serialized();
    datadog_publish_otel_process_context(hostname, dd_zend_string_to_CharSlice(process_tags));
}
