#include <php.h>
#include <zend_extensions.h>
#include <php_ini.h>
#include <php_main.h>
#include <php_version.h>
#include <ext/standard/info.h>

#include "php_dd_library_loader.h"

extern injected_ext ddloader_injected_ext_config[];

static void ddloader_info_function(zend_module_entry *module) {
    UNUSED(module);

    php_info_print_table_start();
    php_info_print_table_header(2, "", "Datadog Library Loader");
    php_info_print_table_row(2, "Version", PHP_DD_LIBRARY_LOADER_VERSION);
    php_info_print_table_row(2, "Author", "Datadog");
    php_info_print_table_end();

    for (unsigned int i = 0; i < EXT_COUNT; ++i) {
        php_info_print_table_start();
        php_info_print_table_header(2, "", ddloader_injected_ext_config[i].ext_name);
        php_info_print_table_row(2, "Version", ddloader_injected_ext_config[i].version);
        php_info_print_table_row(2, "Injection success", ddloader_injected_ext_config[i].injection_success ? "true" : "false");
        php_info_print_table_row(2, "Injection error", ddloader_injected_ext_config[i].injection_error ? ddloader_injected_ext_config[i].injection_error : "");
        php_info_print_table_row(2, "Extra config", ddloader_injected_ext_config[i].extra_config);
        php_info_print_table_row(2, "Logs", ddloader_injected_ext_config[i].logs);
        php_info_print_table_end();
    }
}

zend_module_entry dd_library_loader_mod = {
    STANDARD_MODULE_HEADER,
    "dd_library_loader_mod",
    NULL,                         // functions
    NULL,                         // MINIT
    NULL,                         // MSHUTDOWN
    NULL,                         // RINIT
    NULL,                         // RSHUTDOWN
    ddloader_info_function,       // MINFO
    PHP_DD_LIBRARY_LOADER_VERSION, // version
    STANDARD_MODULE_PROPERTIES
};
