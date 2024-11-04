#ifndef DD_COMPATIBILITY_H
#define DD_COMPATIBILITY_H

#include <php.h>

#if PHP_VERSION_ID < 80400
#undef ZEND_RAW_FENTRY
#define ZEND_RAW_FENTRY(zend_name, name, arg_info, flags, ...)   { zend_name, name, arg_info, (uint32_t) (sizeof(arg_info)/sizeof(struct _zend_internal_arg_info)-1), flags },
#endif

#endif  // DD_COMPATIBILITY_H
