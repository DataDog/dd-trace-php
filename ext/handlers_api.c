#include "handlers_api.h"
#include <components/log/log.h>

// This file is compiled by both the tracer and profiler.

static void datadog_php_install_table_handler(HashTable *table, datadog_php_zif_handler handler) {
    zend_function *old_handler = zend_hash_str_find_ptr(table, handler.name, handler.name_len);

    if (old_handler) {
        *handler.old_handler = old_handler->internal_function.handler;
        old_handler->internal_function.handler = handler.new_handler;
    }
}

void datadog_php_install_handler(datadog_php_zif_handler handler) {
    datadog_php_install_table_handler(CG(function_table), handler);
}

void datadog_php_install_method_handler(datadog_php_zim_handler handler) {
    zend_class_entry *ce = zend_hash_str_find_ptr(CG(class_table), handler.class_name, handler.class_name_len);

    if (ce) {
        LOG(DEBUG, "Installing handler for %s", ce->name->val);
        datadog_php_install_table_handler(&ce->function_table, handler.zif);
    } else {
        LOG(DEBUG, "Could not find class %s", handler.class_name);
    }
}
