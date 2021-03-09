#include "runtime_id_plugin.h"

#include <sodium.h>

#include "uuid/uuid.h"

datadog_php_uuid runtime_id = DATADOG_PHP_UUID_INIT;

typedef enum datadog_php_plugin_result plugin_result_t;

static plugin_result_t datadog_php_runtime_id_plugin_minit(int type, int module_number) {
    (void)type;
    (void)module_number;

    if (sodium_init() == -1) {
        return DATADOG_PHP_PLUGIN_FAILURE;
    }

    _Alignas(16) uint8_t bytes[16];
    randombytes_buf(bytes, sizeof bytes);

    datadog_php_uuidv4_bytes_ctor(&runtime_id, bytes);

    return DATADOG_PHP_PLUGIN_SUCCESS;
}

static void datadog_php_runtime_id_plugin_mshutdown(int type, int module_number) {
    (void)type;
    (void)module_number;
    randombytes_close();
}

datadog_php_plugin datadog_php_runtime_id_plugin = {
    .minit = datadog_php_runtime_id_plugin_minit,
    .mshutdown = datadog_php_runtime_id_plugin_mshutdown,
};
