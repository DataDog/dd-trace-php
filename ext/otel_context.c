#include "otel_context.h"

#include <limits.h>
#include <unistd.h>

#include "datadog.h"
#include "ffi_utils.h"
#include "process_tags.h"
#include <components-rs/datadog.h>

void datadog_otel_process_context_publish(void) {
    char detected_hostname[HOST_NAME_MAX + 1] = {0};
    ddog_CharSlice hostname = DDOG_CHARSLICE_C("");
    if (gethostname(detected_hostname, sizeof detected_hostname) == 0) {
        hostname.ptr = detected_hostname;
        hostname.len = strnlen(detected_hostname, sizeof detected_hostname);
    }

    zend_string *process_tags = datadog_process_tags_get_serialized();
    datadog_publish_otel_process_context(hostname, dd_zend_string_to_CharSlice(process_tags));
}
