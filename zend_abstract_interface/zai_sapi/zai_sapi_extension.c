#include "zai_sapi_extension.h"

// clang-format off
static const zend_module_entry zai_sapi_extension_orig = {
    STANDARD_MODULE_HEADER,
    "ZAI SAPI extension",
    NULL,  // Functions
    NULL,  // MINIT
    NULL,  // MSHUTDOWN
    NULL,  // RINIT
    NULL,  // RSHUTDOWN
    NULL,  // Info function
    PHP_VERSION,
    STANDARD_MODULE_PROPERTIES
};
// clang-format on

zend_module_entry zai_sapi_extension;

void zai_sapi_reset_extension_global(void) { zai_sapi_extension = zai_sapi_extension_orig; }
