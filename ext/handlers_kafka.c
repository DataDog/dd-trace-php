#include <php.h>
#include <stdbool.h>
#include "configuration.h"
#include "handlers_api.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

#define MAX_PRODUCEV_ARGS 7

// True global - only modify during MINIT/MSHUTDOWN
static bool dd_ext_kafka_loaded = false;
static uint32_t opaque_param = 0;

static zif_handler dd_kafka_produce_handler = NULL;

static bool rdkafka_version_supported(void) {
    zend_module_entry *rdkafka_me = zend_hash_str_find_ptr(&module_registry, ZEND_STRL("rdkafka"));
    return rdkafka_me && strncmp(rdkafka_me->version, "6", 1) >= 0;
}

static bool dd_load_kafka-integration(void) {
    return dd_ext_kafka_loaded &&
           get_DD_TRACE_ENABLED() &&
           get_DD_TRACE_KAFKA_ENABLED() &&
           get_DD_DISTRIBUTED_TRACING() &&
           rdkafka_version_supported();
}

static void dd_initialize_producev_args(zval* args, zend_long partition, zend_long msgflags,
                                        const char* payload, size_t payload_len,
                                        const char* key, size_t key_len,
                                        zend_string* opaque) {
    ZVAL_LONG(&args[0], partition);               // Partition
    ZVAL_LONG(&args[1], msgflags);                 // Msgflags
    ZVAL_STRINGL(&args[2], payload ? payload : "", payload_len);  // Payload (optional)
    ZVAL_STRINGL(&args[3], key ? key : "", key_len);              // Key (optional)
    ZVAL_NULL(&args[4]);                          // Headers (distributed tracing)
    ZVAL_NULL(&args[5]);                          // Timestamp (optional)
    if (opaque_param) {
        ZVAL_STR(&args[6], opaque ? opaque : ZSTR_EMPTY_ALLOC()); // Opaque (optional)
    }
}

ZEND_FUNCTION(ddtrace_kafka_produce) {
    if (!dd_load_kafka-integration()) {
        // Call the original handler
        dd_kafka_produce_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
        return;
    }

    zend_long partition, msgflags;
    char* payload = NULL;
    size_t payload_len = 0;
    char* key = NULL;
    size_t key_len = 0;
    zend_string* opaque = NULL;

    ZEND_PARSE_PARAMETERS_START(2, 4 + opaque_param)
        Z_PARAM_LONG(partition)
        Z_PARAM_LONG(msgflags)
        Z_PARAM_OPTIONAL
        Z_PARAM_STRING_OR_NULL(payload, payload_len)
        Z_PARAM_STRING_OR_NULL(key, key_len)
        Z_PARAM_STR_OR_NULL(opaque)
    ZEND_PARSE_PARAMETERS_END();

    zval args[MAX_PRODUCEV_ARGS];
    dd_initialize_producev_args(args, partition, msgflags, payload, payload_len, key, key_len, opaque);

    zval function_name;
    ZVAL_STRING(&function_name, "producev");
    call_user_function(NULL, getThis(), &function_name, return_value, 6 + opaque_param, args);
    zval_dtor(&function_name);

    zend_string_release(Z_STR(args[2]));
    zend_string_release(Z_STR(args[3]));
}

/**
 * Called during MINIT.
 */
void ddtrace_kafka_handlers_startup(void) {
    dd_ext_kafka_loaded = zend_hash_str_exists(&module_registry, ZEND_STRL("rdkafka"));
    if (!dd_ext_kafka_loaded) {
        return;
    }

    zend_class_entry* producer_topic_ce = zend_hash_str_find_ptr(CG(class_table), ZEND_STRL("rdkafka\\producertopic"));
    if (!producer_topic_ce || !zend_hash_str_exists(&producer_topic_ce->function_table, ZEND_STRL("producev"))) {
        return; // Don't install handlers if producev doesn't exist
    }

    // Determine the number of arguments for producev (check if purge exists)
    // See https://github.com/arnaud-lb/php-rdkafka/blob/d6f4d160422a0f8c1e3ee6a18add7cd8f805ba07/topic.c#L495-L497
    zend_class_entry* kafka_ce = zend_hash_str_find_ptr(CG(class_table), ZEND_STRL("rdkafka"));
    if (kafka_ce) {
        zend_function* purge_func = zend_hash_str_find_ptr(&kafka_ce->function_table, ZEND_STRL("purge"));
        opaque_param = purge_func ? 1 : 0;
    }

    static const datadog_php_zim_handler handlers[] = {
            {ZEND_STRL("rdkafka\\producertopic"),
             {ZEND_STRL("produce"), &dd_kafka_produce_handler, ZEND_FN(ddtrace_kafka_produce)}}
    };

    size_t handlers_len = sizeof(handlers) / sizeof(handlers[0]);
    for (size_t i = 0; i < handlers_len; ++i) {
        datadog_php_install_method_handler(handlers[i]);
    }
}
