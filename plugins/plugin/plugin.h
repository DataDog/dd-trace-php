#ifndef DATADOG_PHP_PLUGIN_H
#define DATADOG_PHP_PLUGIN_H

#include <stdbool.h>

// forward declare this so this isn't tied to a specific PHP header
struct _zend_extension;

/**
 * PLUGIN_FAILURE means something failed, but it doesn't think the failure is
 * significant enough to abort loading/request; maybe just that feature won't
 * work.
 * DATADOG_PHP_PLUGIN_DISABLE_PLUGIN means serious failure occurred and the
 * plugins recommends the extension disable itself.
 */
enum datadog_php_plugin_result {
    DATADOG_PHP_PLUGIN_SUCCESS = 0,
    DATADOG_PHP_PLUGIN_FAILURE,
    DATADOG_PHP_PLUGIN_DISABLE_PLUGIN,
};

/* Plugins MUST set .minit and/or .startup; all other function pointers are
 * optional. If .minit or .startup doesn't return SUCCESS then no other hooks
 * will get called, _including the shutdown hooks_, so be sure to clean up
 * anything you need to before returning from .minit or .startup in this case.
 * There's a similar story for .activate and .rinit; if you return anything
 * but SUCCESS then clean up the request globals, because .rshutdown and
 * .deactivate will not be called.
 */
typedef struct datadog_php_plugin {
    // PHP lifecycle hooks {{{
    enum datadog_php_plugin_result (*minit)(int type, int module_number);
    enum datadog_php_plugin_result (*startup)(struct _zend_extension);

    enum datadog_php_plugin_result (*activate)(void);
    enum datadog_php_plugin_result (*rinit)(int type, int module_number);

    /* The upstream hooks here have return values, but the return value has been
     * ignored since at least PHP 5.4, so we just return void.
     */
    void (*rshutdown)(int type, int module_number);
    void (*deactivate_func_t)(void);

    void (*mshutdown)(int type, int module_number);
    void (*shutdown)(struct _zend_extension);
    // }}} PHP lifecycle hooks

    // Tracer lifecycle hooks {{{
    // begin?
    // post-flush?
    // }}}
} datadog_php_plugin;

#endif  // DATADOG_PHP_PLUGIN_H
