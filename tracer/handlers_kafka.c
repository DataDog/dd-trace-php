#include <php.h>
#include <stdbool.h>
#include "configuration.h"
#include "data_streams.h"
#include "ddtrace.h"
#include "handlers_kafka.h"
#include <ext/handlers_api.h>

ZEND_EXTERN_MODULE_GLOBALS(datadog);

#define MAX_PRODUCEV_ARGS 7

// True global - only modify during MINIT/MSHUTDOWN
static bool dd_ext_kafka_loaded = false;
static uint32_t opaque_param = 0;

static zif_handler dd_kafka_produce_handler = NULL;

// librdkafka's, set only where dd_rdkafka_handle() can read php-rdkafka's objects
static char *(*dd_rd_kafka_clusterid)(void *rk, int timeout_ms) = NULL;
static void (*dd_rd_kafka_mem_free)(void *rk, void *ptr) = NULL;
static zend_class_entry *dd_rdkafka_ce = NULL;
static zend_class_entry *dd_rdkafka_kafka_consumer_ce = NULL;

/* php-rdkafka keeps a client's rd_kafka_t in a struct that ends with the zend_object, at the offset
 * its handlers give: a kafka_object for RdKafka\Producer and RdKafka\Consumer, where it follows an
 * rd_kafka_type_t, and an object_intern for RdKafka\KafkaConsumer, where it comes first. Neither is
 * a versioned ABI, but both have been the same in every php-rdkafka release for PHP 7 and later,
 * 2.0.0 to 7.x (php_rdkafka.h, kafka_consumer.c). The handle is NULL before the constructor has
 * run and after KafkaConsumer::close(). */
struct dd_rdkafka_kafka_object {
    int type;
    void *rk;
};

static void *dd_rdkafka_handle(zend_object *kafka) {
    char *intern = (char *)kafka - kafka->handlers->offset;
    if (instanceof_function(kafka->ce, dd_rdkafka_kafka_consumer_ce)) {
        return kafka->handlers->offset >= sizeof(void *) ? *(void **)intern : NULL;
    }
    if (instanceof_function(kafka->ce, dd_rdkafka_ce)) {
        return kafka->handlers->offset >= sizeof(struct dd_rdkafka_kafka_object)
                   ? ((struct dd_rdkafka_kafka_object *)intern)->rk
                   : NULL;
    }
    return NULL;
}

bool ddtrace_kafka_is_client(zend_object *obj) {
    // The class entries are only set when php-rdkafka is loaded
    return (dd_rdkafka_ce && instanceof_function(obj->ce, dd_rdkafka_ce)) ||
           (dd_rdkafka_kafka_consumer_ce && instanceof_function(obj->ce, dd_rdkafka_kafka_consumer_ce));
}

zend_string *ddtrace_kafka_cluster_id(zend_object *kafka) {
    void *rk;
    if (!dd_rd_kafka_clusterid || !(rk = dd_rdkafka_handle(kafka))) {
        return NULL;
    }

    // Without a timeout, librdkafka returns the id it has without waiting for a metadata response
    char *cluster_id = dd_rd_kafka_clusterid(rk, 0);
    if (!cluster_id) {
        return NULL;
    }
    zend_string *str = zend_string_init(cluster_id, strlen(cluster_id), 0);
    dd_rd_kafka_mem_free(rk, cluster_id);
    return str;
}

static void dd_kafka_cluster_id_startup(zend_module_entry *rdkafka_me) {
    dd_rdkafka_ce = zend_hash_str_find_ptr(CG(class_table), ZEND_STRL("rdkafka"));
    dd_rdkafka_kafka_consumer_ce = zend_hash_str_find_ptr(CG(class_table), ZEND_STRL("rdkafka\\kafkaconsumer"));
    // php-rdkafka loads librdkafka, so its handle resolves librdkafka's symbols too
    dd_rd_kafka_mem_free = (void (*)(void *, void *))DL_FETCH_SYMBOL(rdkafka_me->handle, "rd_kafka_mem_free");
    if (dd_rdkafka_ce && dd_rdkafka_kafka_consumer_ce && dd_rd_kafka_mem_free) {
        dd_rd_kafka_clusterid = (char *(*)(void *, int))DL_FETCH_SYMBOL(rdkafka_me->handle, "rd_kafka_clusterid");
    }
}

static bool rdkafka_version_supported(void) {
    zend_module_entry *rdkafka_me = zend_hash_str_find_ptr(&module_registry, ZEND_STRL("rdkafka"));
    return rdkafka_me && strncmp(rdkafka_me->version, "6", 1) >= 0;
}

static bool dd_load_kafka_integration(void) {
    return dd_ext_kafka_loaded &&
           get_DD_TRACE_ENABLED() &&
           get_DD_TRACE_KAFKA_ENABLED() &&
           (get_DD_DISTRIBUTED_TRACING() || ddtrace_dsm_enabled()) &&
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
    if (!dd_load_kafka_integration()) {
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
    zval_ptr_dtor_nogc(&function_name);

    zend_string_release(Z_STR(args[2]));
    zend_string_release(Z_STR(args[3]));
}

/**
 * Called during MINIT.
 */
void ddtrace_kafka_handlers_startup(void) {
    zend_module_entry *rdkafka_me = zend_hash_str_find_ptr(&module_registry, ZEND_STRL("rdkafka"));
    dd_ext_kafka_loaded = rdkafka_me != NULL;
    if (!dd_ext_kafka_loaded) {
        return;
    }

    dd_kafka_cluster_id_startup(rdkafka_me);

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
