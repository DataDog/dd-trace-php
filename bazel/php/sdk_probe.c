#include <php.h>
#include <zend_modules.h>

_Static_assert(PHP_API_VERSION == SDK_EXPECT_API, "unexpected PHP API");
_Static_assert(ZEND_MODULE_API_NO == SDK_EXPECT_API, "unexpected Zend module API");
_Static_assert(ZEND_DEBUG == SDK_EXPECT_DEBUG, "unexpected debug ABI");

#if ZTS != SDK_EXPECT_ZTS
#error "unexpected thread-safety ABI"
#endif

int dd_php_sdk_header_probe(void) {
    return (int)sizeof(zval);
}
