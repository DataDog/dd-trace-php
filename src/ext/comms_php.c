#include "comms_php.h"

#include "arrays.h"
#include "compat_string.h"
#include "compatibility.h"
#include "coms.h"
#include "ddtrace.h"
#include "logging.h"
#include "mpack.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

static void _comms_convert_append(zval *item, size_t offset, void *context) {
    struct curl_slist **list = context;
    zval converted;
    TSRMLS_FETCH();

    PHP5_UNUSED(offset);
    PHP7_UNUSED(offset);

    ddtrace_convert_to_string(&converted, item TSRMLS_CC);
    *list = curl_slist_append(*list, Z_STRVAL(converted));
    zval_dtor(&converted);
}

static struct curl_slist *_dd_convert_hashtable_to_curl_slist(HashTable *input) {
    if (zend_hash_num_elements(input) > 0) {
        struct curl_slist *list = NULL;
        ddtrace_array_walk(input, _comms_convert_append, &list);
        return list;
    }
    return NULL;
}

static bool _dd_memoize_http_headers(HashTable *input) {
    if (((struct curl_slist *)atomic_load(&memoized_agent_curl_headers)) == NULL && zend_hash_num_elements(input) > 0) {
        uintptr_t desired = (uintptr_t)_dd_convert_hashtable_to_curl_slist(input);
        uintptr_t expect = (uintptr_t)NULL;
        return atomic_compare_exchange_strong(&memoized_agent_curl_headers, &expect, desired);
    }
    return false;
}

bool ddtrace_send_traces_via_thread(size_t num_traces, zval *curl_headers, char *payload,
                                    size_t payload_len TSRMLS_DC) {
    if (num_traces != 1) {
        // The background sender is capable of sending exactly one trace atm
        return false;
    }
    bool sent_to_background_sender = false;

    if (_dd_memoize_http_headers(Z_ARRVAL_P(curl_headers))) {
        ddtrace_log_debug("Successfully memoized Agent HTTP headers");
    }

    /* Encoders encode X traces, but we need to do concatenation at the
     * transport layer too, so we strip away the msgpack array prefix.
     */
    mpack_reader_t reader;
    mpack_reader_init_data(&reader, payload, payload_len);
    do {
        // 1. Check that it's a msgpack array of size 1
        mpack_expect_array_match(&reader, 1);

        if (mpack_reader_error(&reader) != mpack_ok) {
            ddtrace_log_debug("Background sender expected a msgpack array of size 1");
            break;
        }

        // 2. Get the pointer to the bits after the the msgpack array prefix
        const char *data = payload;
        size_t data_len = mpack_reader_remaining(&reader, &data);

        if (ddtrace_coms_buffer_data(DDTRACE_G(traces_group_id), data, data_len)) {
            sent_to_background_sender = true;
        } else {
            ddtrace_log_debug("Unable to send payload to background sender's buffer");
        }
    } while (false);

    mpack_reader_destroy(&reader);
    return sent_to_background_sender;
}
