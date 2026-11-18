// Note: Not included on Windows
#include "comms_php.h"

#include "coms.h"
#include "ddtrace.h"
#include "configuration.h"
#include <components/log/log.h>
#include <string.h>

ZEND_EXTERN_MODULE_GLOBALS(datadog);

// The length of the header of a msgpack array of size 1, in any of the
// encodings dd_write_array_header (coms.c) may produce, or 0.
static size_t dd_array_of_one_header_len(const char *payload, size_t payload_len) {
    if (payload_len >= 1 && memcmp(payload, "\x91", 1) == 0) {
        return 1;
    }
    if (payload_len >= 3 && memcmp(payload, "\xdc\x00\x01", 3) == 0) {
        return 3;
    }
    if (payload_len >= 5 && memcmp(payload, "\xdd\x00\x00\x00\x01", 5) == 0) {
        return 5;
    }
    return 0;
}

bool ddtrace_send_traces_via_thread(size_t num_traces, const char *payload, size_t payload_len) {
    if (!get_DD_TRACE_ENABLED()) {
        // If the tracer is set to drop all the spans, we do not signal an error.
        LOG(WARN, "Traces are dropped by PID %ld because tracing is disabled.", getpid());
        return true;
    }

    if (num_traces != 1) {
        // The background sender is capable of sending exactly one trace atm
        return false;
    }

    /* Encoders encode X traces, but we need to do concatenation at the
     * transport layer too, so we strip away the msgpack array prefix.
     */
    size_t header_len = dd_array_of_one_header_len(payload, payload_len);
    if (!header_len) {
        LOG(WARN, "Background sender expected a msgpack array of size 1");
        return false;
    }

    if (!ddtrace_coms_buffer_data(DDTRACE_G(traces_group_id), payload + header_len, payload_len - header_len)) {
        LOG(WARN, "Unable to send payload to background sender's buffer");
        return false;
    }
    return true;
}
