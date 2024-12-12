#include <php.h>
#include <stdbool.h>

#include "librdkafka/rdkafka.h"
#include "Zend/zend_exceptions.h"
#include "ext/spl/spl_exceptions.h"

#include <components-rs/ddtrace.h>
#include <components/log/log.h>

#ifndef _WIN32

#include "coms.h"

#endif

#include "configuration.h"
#include "ddtrace.h"
#include "span.h"
#include "handlers_http.h"

#include "handlers_internal.h"  // For 'ddtrace_replace_internal_function'

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

// True global - only modify during MINIT/MSHUTDOWN
bool dd_ext_kafka_loaded = false;

// ----------

#define Z_RDKAFKA_P(php_kafka_type, zobject) php_kafka_from_obj(php_kafka_type, Z_OBJ_P(zobject))

#define php_kafka_from_obj(php_kafka_type, object) \
    ((php_kafka_type*)((char *)(object) - XtOffsetOf(php_kafka_type, std)))

// ----------

// php-rdkafka's topic.[c|h]

typedef struct _kafka_topic_object {
    rd_kafka_topic_t    *rkt;
    zval               zrk;
    zend_object         std;
} kafka_topic_object;

kafka_topic_object * get_kafka_topic_object(zval *zrkt)
{
    kafka_topic_object *orkt = Z_RDKAFKA_P(kafka_topic_object, zrkt);

    if (!orkt->rkt) {
        zend_throw_exception_ex(NULL, 0, "RdKafka\\Topic::__construct() has not been called");
        return NULL;
    }

    return orkt;
}

// -------

typedef struct _kafka_conf_callback {
    zend_fcall_info fci;
    zend_fcall_info_cache fcc;
} kafka_conf_callback;

typedef struct _kafka_conf_callbacks {
    zval zrk;
    kafka_conf_callback *error;
    kafka_conf_callback *rebalance;
    kafka_conf_callback *dr_msg;
    kafka_conf_callback *stats;
    kafka_conf_callback *consume;
    kafka_conf_callback *offset_commit;
    kafka_conf_callback *log;
    kafka_conf_callback *oauthbearer_token_refresh;
} kafka_conf_callbacks;

// ---------

typedef struct _kafka_object {
    rd_kafka_type_t         type;
    rd_kafka_t              *rk;
    kafka_conf_callbacks    cbs;
    HashTable               consuming;
    HashTable				topics;
    HashTable				queues;
    zend_object             std;
} kafka_object;

// --------

kafka_object * get_kafka_object(zval *zrk)
{
    kafka_object *ork = Z_RDKAFKA_P(kafka_object, zrk);

    if (!ork->rk) {
        zend_throw_exception_ex(NULL, 0, "RdKafka\\Kafka::__construct() has not been called");
        return NULL;
    }

    return ork;
}

static zif_handler dd_kafka_produce_handler = NULL;

static bool dd_load_kafka_integration(void) {
    if (!dd_ext_kafka_loaded || !get_DD_TRACE_ENABLED()) {
        return false;
    }
    return get_DD_DISTRIBUTED_TRACING();
}

ZEND_FUNCTION(ddtrace_kafka_produce) {
    if (!dd_load_kafka_integration()) {
        // Call the original function
        dd_kafka_produce_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
        return;
    }

    LOG(DEBUG, "Calling the custom ddtrace_kafka_produce");
    //dd_kafka_produce_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);

    zend_long partition;
    zend_long msgflags;
    char *payload = NULL;
    size_t payload_len = 0;
    char *key = NULL;
    size_t key_len = 0;
    int ret;
    rd_kafka_resp_err_t err;
    kafka_topic_object *intern;
    kafka_object *kafka_intern;
    zend_long timestamp_ms = 0;
    zend_bool timestamp_ms_is_null = 0;
    zend_string *opaque = NULL;

    ZEND_PARSE_PARAMETERS_START(2, 5)
        Z_PARAM_LONG(partition)
        Z_PARAM_LONG(msgflags)
        Z_PARAM_OPTIONAL
        Z_PARAM_STRING_OR_NULL(payload, payload_len)
        Z_PARAM_STRING_OR_NULL(key, key_len)
        Z_PARAM_STR_OR_NULL(opaque)
    ZEND_PARSE_PARAMETERS_END();

    // For debug purposes, log the partition, msgflags, and payload
    LOG(DEBUG, "Partition: %ld, Msgflags: %ld, Payload: %s", partition, msgflags, payload);

    if (partition != RD_KAFKA_PARTITION_UA && (partition < 0 || partition > 0x7FFFFFFF)) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0, "Out of range value '" ZEND_LONG_FMT "' for $partition", partition);
        return;
    }

    if (msgflags != 0 && msgflags != RD_KAFKA_MSG_F_BLOCK) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0, "Invalid value '" ZEND_LONG_FMT "' for $msgflags", msgflags);
        return;
    }

    intern = get_kafka_topic_object(getThis());

    if (opaque != NULL) {
        zend_string_addref(opaque);
    }

    // Add datadog distributed headers
    zval *distributed_headers;
    array_init(distributed_headers);
    ddtrace_inject_distributed_headers(Z_ARR_P(distributed_headers), true);

    rd_kafka_headers_t *headers = rd_kafka_headers_new(1);
    zend_string *header_key;
    zval *header_value;
    ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARR_P(distributed_headers), header_key, header_value) {
        if (header_key == NULL) {
            continue;
        }
        if (Z_TYPE_P(header_value) != IS_STRING) {
            continue;
        }
        rd_kafka_header_add(
            headers,
            ZSTR_VAL(header_key),
            ZSTR_LEN(header_key),
            Z_STRVAL_P(header_value),
            Z_STRLEN_P(header_value)
        );
    } ZEND_HASH_FOREACH_END();

    kafka_intern = get_kafka_object(&intern->zrk);
    if (!kafka_intern) {
        return;
    }

    err = rd_kafka_producev(
            kafka_intern->rk,
            RD_KAFKA_V_RKT(intern->rkt),
            RD_KAFKA_V_PARTITION(partition),
            RD_KAFKA_V_MSGFLAGS(msgflags | RD_KAFKA_MSG_F_COPY),
            RD_KAFKA_V_VALUE(payload, payload_len),
            RD_KAFKA_V_KEY(key, key_len),
            RD_KAFKA_V_TIMESTAMP(timestamp_ms),
            RD_KAFKA_V_HEADERS(headers),
            RD_KAFKA_V_OPAQUE(opaque),
            RD_KAFKA_V_END
    );

    // Free the distributed headers
    zval_ptr_dtor(distributed_headers);

    if (err != RD_KAFKA_RESP_ERR_NO_ERROR) {
        rd_kafka_headers_destroy(headers);
        if (opaque != NULL) {
            zend_string_release(opaque);
        }
        //zend_throw_exception(ce_kafka_exception, rd_kafka_err2str(err), err);
        return;
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
    LOG(DEBUG, "[Start] Installed Kafka handlers");
    // If we cannot find ext/rdkafka, then do not instrument it
    dd_ext_kafka_loaded = zend_hash_str_exists(&module_registry, ZEND_STRL("rdkafka"));
    if (!dd_ext_kafka_loaded) {
        return;
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
    LOG(DEBUG, "[End] Installed Kafka handlers");
}
