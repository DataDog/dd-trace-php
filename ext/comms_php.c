// Note: Not included on Windows
#include "comms_php.h"

#include "coms.h"
#include "ddtrace.h"
#include "configuration.h"
#include <components/log/log.h>
#include "mpack/mpack.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

bool ddtrace_send_traces_via_thread(size_t num_traces, char *payload, size_t payload_len) {
    if (!get_DD_TRACE_ENABLED() && !get_DD_EXPERIMENTAL_APPSEC_STANDALONE_ENABLED()) {
        // If the tracer is set to drop all the spans, we do not signal an error.
        LOG(WARN, "Traces are dropped by PID %ld because tracing is disabled.", getpid());
        return true;
    }

    if (num_traces != 1) {
        // The background sender is capable of sending exactly one trace atm
        return false;
    }
    bool sent_to_background_sender = false;

    /* Encoders encode X traces, but we need to do concatenation at the
     * transport layer too, so we strip away the msgpack array prefix.
     */
    mpack_reader_t reader;
    mpack_reader_init_data(&reader, payload, payload_len);
    do {
        // 1. Check that it's a msgpack array of size 1
        mpack_expect_array_match(&reader, 1);

        if (mpack_reader_error(&reader) != mpack_ok) {
            LOG(WARN, "Background sender expected a msgpack array of size 1");
            break;
        }

        // 2. Get the pointer to the bits after the the msgpack array prefix
        const char *data = payload;
        size_t data_len = mpack_reader_remaining(&reader, &data);

        if (ddtrace_coms_buffer_data(DDTRACE_G(traces_group_id), data, data_len)) {
            sent_to_background_sender = true;
        } else {
            LOG(WARN, "Unable to send payload to background sender's buffer");
        }
    } while (false);

    mpack_reader_destroy(&reader);
    return sent_to_background_sender;
}
