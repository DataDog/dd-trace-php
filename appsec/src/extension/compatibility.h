#ifndef DD_COMPATIBILITY_H
#define DD_COMPATIBILITY_H

#include <php.h>

#if PHP_VERSION_ID < 80400
#    undef ZEND_RAW_FENTRY
#    define ZEND_RAW_FENTRY(zend_name, name, arg_info, flags, ...)             \
        {zend_name, name, arg_info,                                            \
            (uint32_t)((sizeof(arg_info) /                                     \
                           sizeof(struct _zend_internal_arg_info)) -           \
                       1),                                                     \
            flags},
#endif

#if PHP_VERSION_ID < 80000
// ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE was added in PHP 8.0.
// For PHP 7.x, fall back to ZEND_ARG_TYPE_INFO (the default_value hint is not
// stored in PHP 7 internal arginfo structs).
#    define ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(                           \
        pass_by_ref, name, type, allow_null, default_value)                  \
    ZEND_ARG_TYPE_INFO(pass_by_ref, name, type, allow_null)
#endif

#if PHP_VERSION_ID < 70200
// PHP 7.0 and 7.1's ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX takes a 6th
// class_name argument that was folded into the type in 7.2.  Our generated
// arginfo uses the 5-argument (7.2+) form.  Redefine to accept 5 args and pass
// NULL as the class name (correct for all primitive return types we expose).
// The zend_internal_arg_info layout is identical in 7.0 and 7.1.
#    undef ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX
#    define ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(                         \
        name, return_reference, required_num_args, type, allow_null)         \
        static const zend_internal_arg_info name[] = {                       \
            {(const char *)(zend_uintptr_t)(required_num_args), NULL,        \
                (zend_uchar)(type), return_reference, allow_null, 0},
#endif

#endif // DD_COMPATIBILITY_H
