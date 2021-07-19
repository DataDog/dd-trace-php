#include <main/php.h>

#if PHP_VERSION_ID < 70100

#define ZEND_STR_MESSAGE "message"
#define ZEND_STR_CODE "code"

#endif
