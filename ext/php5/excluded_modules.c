#include "excluded_modules.h"

#include "logging.h"

bool ddtrace_is_excluded_module(zend_module_entry *module, char *error) {
    if (strcmp("ionCube Loader", module->name) == 0 || strcmp("newrelic", module->name) == 0 ||
        strcmp("Zend Guard Loader", module->name) == 0) {
        snprintf(error, DDTRACE_EXCLUDED_MODULES_ERROR_MAX_LEN,
                 "Found incompatible module: %s, disabling conflicting functionality", module->name);
        return true;
    }
    return false;
}

void ddtrace_excluded_modules_startup() {
    zend_module_entry *module;
    HashPosition pos;

    ddtrace_has_excluded_module = false;

    zend_hash_internal_pointer_reset_ex(&module_registry, &pos);
    while (zend_hash_get_current_data_ex(&module_registry, (void *)&module, &pos) != FAILURE) {
        char error[DDTRACE_EXCLUDED_MODULES_ERROR_MAX_LEN + 1];
        if (module && module->name && ddtrace_is_excluded_module(module, error)) {
            ddtrace_has_excluded_module = true;
            ddtrace_log_debug(error);
            return;
        }
        zend_hash_move_forward_ex(&module_registry, &pos);
    }
}
