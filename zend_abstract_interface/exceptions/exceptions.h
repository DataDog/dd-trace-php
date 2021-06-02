#if PHP_VERSION_ID < 70000
#include "php5/exceptions.h"
#elif PHP_VERSION_ID < 80000
#include "php7/exceptions.h"
#else
#include "php8/exceptions.h"
#endif
