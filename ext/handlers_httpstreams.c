#include "configuration.h"
#include "handlers_http.h"
#include "serializer.h"

static php_stream_wrapper_ops traced_http_wrapper_wops;
static php_stream_wrapper_ops traced_https_wrapper_wops;
static php_stream_wrapper dd_original_http_wrapper;
static php_stream_wrapper dd_original_https_wrapper;
static php_stream_wrapper dd_traced_http_wrapper;
static php_stream_wrapper dd_traced_https_wrapper;

#define DD_STREAM_OPENER_ARGS \
    php_stream_wrapper *wrapper, \
    const char *filename, \
    const char *mode, \
    int options, \
    zend_string **opened_path, \
    php_stream_context *context \
    STREAMS_DC

#define DD_STREAM_OPENER_CALL_ARGS \
    wrapper, filename, mode, options, opened_path, context STREAMS_REL_CC

static bool dd_load_http_stream_integration() {
    return get_DD_DISTRIBUTED_TRACING() && get_DD_TRACE_ENABLED();
}

static php_stream *dd_stream_opener(
    php_stream_wrapper *original_wrapper,
    DD_STREAM_OPENER_ARGS
) {
    if (!context) {
        context = php_stream_context_alloc();
    }

    zval *options_zv = &context->options;

    if (dd_load_http_stream_integration()) {
        SEPARATE_ARRAY(options_zv);

        // Retrieve or create the "http" subarray
        zval *http_context_zv = zend_hash_str_find_deref(Z_ARRVAL_P(options_zv), "http", sizeof("http") - 1);
        if (!http_context_zv) {
            zval tmp;
            array_init(&tmp);
            zend_hash_str_update(Z_ARRVAL_P(options_zv), "http", sizeof("http") - 1, &tmp);
            http_context_zv = zend_hash_str_find_deref(Z_ARRVAL_P(options_zv), "http", sizeof("http") - 1);
        }

        if (Z_TYPE_P(http_context_zv) == IS_ARRAY) {
            SEPARATE_ARRAY(http_context_zv);
            HashTable *http_context = Z_ARRVAL_P(http_context_zv);

            zval *header_zv = zend_hash_str_find(http_context, ZEND_STRL("header"));
            if (header_zv && Z_TYPE_P(header_zv) == IS_ARRAY) {
                SEPARATE_ARRAY(header_zv);
                ddtrace_inject_distributed_headers(Z_ARRVAL_P(header_zv), HEADER_MODE_ARRAY);
            } else {
                ddtrace_inject_distributed_headers(http_context, HEADER_MODE_CONTEXT);
            }
        }
    }

    // Open internal span
    ddtrace_span_data *span = NULL;
    if (ddtrace_integrations[DDTRACE_INTEGRATION_HTTPSTREAM].is_enabled() && get_DD_TRACE_ENABLED()) {
        span = ddtrace_alloc_execute_data_span(-2, EG(current_execute_data));
        if (span) {
            ddtrace_set_global_span_properties(span);

            zend_array *meta = ddtrace_property_array(&span->property_meta);
            zval zv;

            ZVAL_STRING(&zv, "php.stream");
            zend_hash_str_update(meta, ZEND_STRL("component"), &zv);

            ZVAL_STRING(&zv, "client");
            zend_hash_str_update(meta, ZEND_STRL("span.kind"), &zv);

            ZVAL_STRING(&zv, filename);
            zend_hash_str_update(meta, ZEND_STRL("http.url"), &zv);

            zval *method_zv = zend_hash_str_find(Z_ARRVAL_P(options_zv), "method", sizeof("method") - 1);
            if (method_zv && Z_TYPE_P(method_zv) == IS_STRING) {
                zend_hash_str_update(meta, ZEND_STRL("http.method"), method_zv);
            }

            const char *host_start = strstr(filename, "://");
            if (host_start) {
                host_start += 3; // skip "://"
                const char *host_end = strchr(host_start, '/');
                size_t host_len = host_end ? (size_t)(host_end - host_start) : strlen(host_start);
                ZVAL_STRINGL(&zv, host_start, host_len);
                zend_hash_str_update(meta, ZEND_STRL("network.destination.name"), &zv);
            }
        }
    }

    php_stream *stream = original_wrapper->wops->stream_opener(DD_STREAM_OPENER_CALL_ARGS);

    if (span) {
        ddtrace_clear_execute_data_span((zend_ulong)-2, true);
    }

    return stream;
}

static php_stream *dd_stream_opener_http(DD_STREAM_OPENER_ARGS) {
    return dd_stream_opener(&dd_original_http_wrapper, DD_STREAM_OPENER_CALL_ARGS);
}

static php_stream *dd_stream_opener_https(DD_STREAM_OPENER_ARGS) {
    return dd_stream_opener(&dd_original_https_wrapper, DD_STREAM_OPENER_CALL_ARGS);
}

static void dd_instrument_stream_wrapper(
    const char *name,
    php_stream_wrapper *original_wrapper,
    php_stream_wrapper *traced_wrapper,
    php_stream_wrapper_ops *traced_wops,
    php_stream *(*stream_opener)(DD_STREAM_OPENER_ARGS)
) {
    HashTable *wrappers = php_stream_get_url_stream_wrappers_hash_global();
    zval *wrapper_zv = zend_hash_str_find(wrappers, name, strlen(name));

    if (!wrapper_zv) {
        return;
    }

    php_stream_wrapper *orig = (php_stream_wrapper *)Z_PTR_P(wrapper_zv);
    memcpy(original_wrapper, orig, sizeof(php_stream_wrapper));
    memcpy(traced_wrapper, orig, sizeof(php_stream_wrapper));
    memcpy(traced_wops, orig->wops, sizeof(php_stream_wrapper_ops));

    traced_wops->stream_opener = stream_opener;
    traced_wrapper->wops = traced_wops;

    zend_hash_str_update_ptr(wrappers, name, strlen(name), traced_wrapper);
}

void ddtrace_instrument_stream_wrappers(void) {
    dd_instrument_stream_wrapper(
        "http",
        &dd_original_http_wrapper,
        &dd_traced_http_wrapper,
        &traced_http_wrapper_wops,
        dd_stream_opener_http
    );

    dd_instrument_stream_wrapper(
        "https",
        &dd_original_https_wrapper,
        &dd_traced_https_wrapper,
        &traced_https_wrapper_wops,
        dd_stream_opener_https
    );
}
