#include <php.h>
#include <stdbool.h>

#include <components-rs/ddtrace.h>

#include "ddtrace.h"
#include "span.h"
#include "configuration.h"
#include "random.h"
#include "sidecar.h"
#include "handlers_internal.h"  // For 'ddtrace_replace_internal_function'
#include <zend_types.h>
#include <zend_portability.h>

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

static zif_handler dd_rdkafla_produce_handler = NULL;

static void dd_handle_produce(zval *return_value) {
    printf("!!!!!!!! Handling produce ourselves\n");
}

static inline void dd_install_internal_func_name(HashTable *baseTable, const char *name) {
    zend_function *func;
    if ((func = zend_hash_str_find_ptr(baseTable, name, strlen(name)))) {
        dd_install_internal_func(func);
    }
}

ZEND_FUNCTION(ddtrace_librdkafka_produce) {
    printf("!!!!!!!! Declaring function\n");
    dd_rdkafla_produce_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
    dd_handle_produce(return_value);
}

/* This function is called during process startup so all of the memory allocations should be
 * persistent to avoid using the Zend Memory Manager. This will avoid an accidental use after free.
 *
 * "If you use ZendMM out of the scope of a request (like in MINIT()), the allocation will be
 * silently cleared by ZendMM before treating the first request, and you'll probably use-after-free:
 * simply don't."
 *
 * @see http://www.phpinternalsbook.com/php7/memory_management/zend_memory_manager.html#common-errors-and-mistakes
 */
void ddtrace_rdkafka_handlers_startup(void) {
    //printf("!!!!!!!! Starting up\n");
    // if we cannot find ext-rdkafka then do not instrument it
    zend_string *rdkafka = zend_string_init(ZEND_STRL("rdkafka"), 1);
    bool rdkafka_loaded = zend_hash_exists(&module_registry, rdkafka);
    //printf("!!!!!!!! Is kafka loaded\n");
    zend_string_release(rdkafka);
    if (!rdkafka_loaded) {
        //printf("!!!!!!!! Is not sadge\n");
        return;
    }
    //printf("!!!!!!!! It is\n");

    zend_module_entry *rdkafka_me = NULL;
    rdkafka_me = zend_hash_str_find_ptr(&module_registry, ZEND_STRL("rdkafka"));

    if (rdkafka_me != NULL && rdkafka_me->handle != NULL) {
        printf("!!!!!!!! size: %d\n", rdkafka_me->size);
        printf("!!!!!!!! zend_api: %d\n", rdkafka_me->zend_api);
        printf("!!!!!!!! zend_debug: %d\n", rdkafka_me->zend_debug);
        printf("!!!!!!!! name: %s\n", rdkafka_me->name);

        zend_class_entry **producertopic_ce_ptr = (zend_class_entry **)DL_FETCH_SYMBOL(rdkafka_me->handle, "_rd_kafka_produce");

        if(producertopic_ce_ptr == NULL) {
            printf("!!!!!!!! Could not retrieve _rd_kafka_produce\n");
            producertopic_ce_ptr = (zend_class_entry **)DL_FETCH_SYMBOL(rdkafka_me->handle, "topic_produce");
        }
        if(producertopic_ce_ptr == NULL) {
            printf("!!!!!!!! Could not retrieve topic_produce\n");
            producertopic_ce_ptr = (zend_class_entry **)DL_FETCH_SYMBOL(rdkafka_me->handle, "topic_produce");
        }

        zend_class_entry *producertopic_ce = *producertopic_ce_ptr;
        dd_install_internal_func_name(&producertopic_ce->function_table, "produce");
    }
    else
    {
        printf("!!!!!!!! Could not find module\n");
    }

    printf("!!!!!!!! Done finding module\n");
    datadog_php_zif_handler handlers[] = {
        //{ZEND_STRL("RdKafka\\ProducerTopic_produce"), &dd_rdkafla_produce_handler, ZEND_FN(ddtrace_librdkafka_produce)},
        {ZEND_STRL("RdKafka\\ProducerTopic_produce"), &dd_rdkafla_produce_handler, ZEND_FN(ddtrace_librdkafka_produce)},
    };
    size_t handlers_len = sizeof handlers / sizeof handlers[0];
    for (size_t i = 0; i < handlers_len; ++i) {
        //printf("!!!!!!!! Installing handler");
        datadog_php_install_handler(handlers[i]);
    }

    //printf("!!!!!!!! Done starting up\n");
}
