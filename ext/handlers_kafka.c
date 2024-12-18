#ifndef _WIN32

#include <php.h>
#include <stdbool.h>

#include <components-rs/ddtrace.h>
#include <components/log/log.h>

#include "configuration.h"
#include "ddtrace.h"
#include "handlers_http.h"

#include "handlers_internal.h"  // For 'ddtrace_replace_internal_function'

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

#ifndef Z_PARAM_STRING_OR_NULL
#define Z_PARAM_STRING_OR_NULL(dest, dest_len) \
    Z_PARAM_STRING_EX(dest, dest_len, 1, 0)
#endif

#ifndef Z_PARAM_STR_OR_NULL
#define Z_PARAM_STR_OR_NULL(dest) \
	Z_PARAM_STR_EX(dest, 1, 0)
#endif

// True global - only modify during MINIT/MSHUTDOWN
bool dd_ext_kafka_loaded = false;
uint32_t opaque_param = 0;

static zif_handler dd_kafka_produce_handler = NULL;

static bool dd_load_kafka_integration(void) {
    if (!dd_ext_kafka_loaded || !get_DD_TRACE_ENABLED() || !get_DD_TRACE_KAFKA_ENABLED()) {
        return false;
    }
    return get_DD_DISTRIBUTED_TRACING();
}

ZEND_FUNCTION(ddtrace_kafka_produce) {
    LOG(DEBUG, "ddtrace_kafka_produce");
    if (!dd_load_kafka_integration()) {
        // Call the original handler
        LOG(DEBUG, "Kafka integration is not enabled");
        dd_kafka_produce_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
        return;
    }

    zend_long partition;
    zend_long msgflags;
    char* payload = NULL;
    size_t payload_len = 0;
    char* key = NULL;
    size_t key_len = 0;
    zend_string* opaque = NULL;

    LOG(DEBUG, "Number of arguments: %d", 6 + opaque_param);
    ZEND_PARSE_PARAMETERS_START(2, 4 + opaque_param)
            Z_PARAM_LONG(partition)
            Z_PARAM_LONG(msgflags)
            Z_PARAM_OPTIONAL
            Z_PARAM_STRING_OR_NULL(payload, payload_len)
            Z_PARAM_STRING_OR_NULL(key, key_len)
            Z_PARAM_STR_OR_NULL(opaque)
    ZEND_PARSE_PARAMETERS_END();

    // Create distributed tracing headers if not passed
    LOG(DEBUG, "Creating distributed tracing headers");
    zval headers;
    array_init(&headers);
    ddtrace_inject_distributed_headers(Z_ARR(headers), true);

    // Prepare arguments for calling the producev method
    zval args[6 + opaque_param];  // We have 7 arguments for producev at max

    ZVAL_LONG(&args[0], partition);  // Partition
    ZVAL_LONG(&args[1], msgflags);  // Msgflags
    ZVAL_STRINGL(&args[2], payload ? payload : "", payload_len);  // Payload (optional)
    ZVAL_STRINGL(&args[3], key ? key : "", key_len);  // Key (optional)
    ZVAL_ZVAL(&args[4], &headers, 0, 0);  // Headers (distributed tracing)
    ZVAL_NULL(&args[5]);  // Timestamp (optional) - NULL for now
    if (opaque_param) {
        ZVAL_STR(&args[6], opaque ? opaque : ZSTR_EMPTY_ALLOC());  // Opaque (optional)
    }

    LOG(DEBUG, "Calling 'producev' method");
    zval function_name;
    ZVAL_STRING(&function_name, "producev");
    call_user_function(NULL, getThis(), &function_name, return_value, 6 + opaque_param, args);
    LOG(DEBUG, "Called 'producev' method");
    zval_dtor(&function_name);

    zval_ptr_dtor(&headers);
    if (payload) {
        zend_string_release(Z_STR(args[2]));
    }
    if (key) {
        zend_string_release(Z_STR(args[3]));
    }
}

/*
 * This function is called during process startup so all of the memory allocations should be
 * persistent to avoid using the Zend Memory Manager. This will avoid an accidental use after free.
 *
 * "If you use ZendMM out of the scope of a request (like in MINIT()), the allocation will be
 * silently cleared by ZendMM before treating the first request, and you'll probably use-after-free:
 * simply don't."
 *
 * @see http://www.phpinternalsbook.com/php7/memory_management/zend_memory_manager.html#common-errors-and-mistakes
 */
void ddtrace_kafka_handlers_startup(void) {
    // If we cannot find ext/rdkafka, then do not instrument it
    dd_ext_kafka_loaded = zend_hash_str_exists(&module_registry, ZEND_STRL("rdkafka"));
    if (!dd_ext_kafka_loaded) {
        return;
    }

    // Checks if the RdKafka::purge method exists (this trick will help us determine the number of arguments)
    // If it exists, then we have 7 arguments for producev (opaque)
    zend_class_entry* kafka_ce = zend_hash_str_find_ptr(CG(class_table), "rdkafka", sizeof("rdkafka") - 1);
    zend_function* purge_func = NULL;
    if (kafka_ce != NULL) {
        purge_func = zend_hash_str_find_ptr(&kafka_ce->function_table, "purge", sizeof("purge") - 1);
        if (purge_func != NULL) {
            LOG(DEBUG, "Found 'purge' method in the class 'RdKafka'");
            opaque_param = 1;
        }
    }

    // Note for legacy: The class name has to be the fully qualified class name in lowercase
    datadog_php_zim_handler handlers[] = {
            {ZEND_STRL("rdkafka\\producertopic"),
             {ZEND_STRL("produce"), &dd_kafka_produce_handler, ZEND_FN(ddtrace_kafka_produce)}}
    };
    size_t handlers_len = sizeof(handlers) / sizeof(handlers[0]);
    for (size_t i = 0; i < handlers_len; ++i) {
        datadog_php_install_method_handler(handlers[i]);
    }
}

#endif