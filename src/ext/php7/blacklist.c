#include "blacklist.h"

#include <php.h>
#include <stdbool.h>

#include <ext/standard/php_versioning.h>

#include "logging.h"

static bool _dd_is_blacklisted_module(zend_module_entry *module) {
    if (strcmp("ionCube Loader", module->name) == 0) {
        ddtrace_log_debugf("Found blacklisted module: %s, disabling conflicting functionality", module->name);
        return true;
    }
    if (strcmp("xdebug", module->name) == 0) {
        /*
        PHP 7.0 was only supported from Xdebug 2.4 through 2.7
        @see: https://xdebug.org/docs/compat
        */
#if PHP_VERSION_ID < 70100
        ddtrace_log_errf("Found incompatible Xdebug version %s; disabling conflicting functionality", module->version);
        return true;
#endif
        /*
        Xdebug versions < 2.9.3 did not call neighboring extension's opcode handlers.
        @see: https://github.com/xdebug/xdebug/commit/87c61401e27df786a06cc881bd2011ce985b08dd
        @see: https://xdebug.org/announcements/2020-03-13

        A bug was introduced in Xdebug version 2.9.1 that would cause a SIGSEGV when xdebug.remote_enable=1
        and a neighboring extension compiles PHP in RINIT. This was fixed in Xdebug 2.9.5.
        @see: https://bugs.xdebug.org/view.php?id=1736
        @see: https://bugs.xdebug.org/view.php?id=1775
        @see: https://github.com/xdebug/xdebug/commit/6c6c08233593ffc1d64d70c51c56f567e6528010
        @see: https://xdebug.org/announcements/2020-04-25

        Ideally we would disable Xdebug 2.9.3 and 2.9.4 only when xdebug.remote_enable=1, but the SIGSEGV
        happens when ddtrace compiles PHP in RINIT. This occurs before Xdebug's INI settings are loaded and
        we are therefore unable to check the value of xdebug.remote_enable.
        */
        int compare = php_version_compare(module->version, "2.9.5");
        if (compare == -1) {
            ddtrace_log_errf(
                "Found incompatible Xdebug version %s; ddtrace requires Xdebug 2.9.5 or greater; disabling conflicting "
                "functionality",
                module->version);
            return true;
        }
    }
    if (strcmp("newrelic", module->name) == 0) {
        ddtrace_blacklisted_disable_legacy = true;
    }
    return false;
}

void ddtrace_blacklist_startup() {
    zend_module_entry *module;

    ddtrace_blacklisted_disable_legacy = false;
    ddtrace_has_blacklisted_module = false;

    ZEND_HASH_FOREACH_PTR(&module_registry, module) {
        if (module && module->name && module->version && _dd_is_blacklisted_module(module)) {
            ddtrace_has_blacklisted_module = true;
            return;
        }
    }
    ZEND_HASH_FOREACH_END();
}
