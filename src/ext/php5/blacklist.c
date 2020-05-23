#include "blacklist.h"

#include "logging.h"

static bool _dd_is_blacklisted_module(zend_module_entry *module) {
    if (strcmp("ionCube Loader", module->name) == 0 || strcmp("newrelic", module->name) == 0) {
        ddtrace_log_debugf("Found blacklisted module: %s, disabling conflicting functionality", module->name);
        return true;
    }
    return false;
}

void ddtrace_blacklist_startup() {
    zend_module_entry *module;
    HashPosition pos;

    ddtrace_has_blacklisted_module = false;

    zend_hash_internal_pointer_reset_ex(&module_registry, &pos);
    while (zend_hash_get_current_data_ex(&module_registry, (void *)&module, &pos) != FAILURE) {
        if (module && module->name && _dd_is_blacklisted_module(module)) {
            ddtrace_has_blacklisted_module = true;
            return;
        }
        zend_hash_move_forward_ex(&module_registry, &pos);
    }
}
