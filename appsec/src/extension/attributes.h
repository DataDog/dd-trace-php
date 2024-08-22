// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License
// or BSD-3-Clause License.
//
// This product includes software developed at Datadog (https://www.datadoghq.com/).
// Copyright 2021 Datadog, Inc.
#ifndef DD_ATTRIBUTES_H
#define DD_ATTRIBUTES_H

#ifndef __has_feature
# define __has_feature(x) 0
#endif

#if !__has_feature(nullability)
# ifndef _Nullable
#  define _Nullable
# endif
# ifndef _Nonnull
#  define _Nonnull
# endif
# ifndef _Null_unspecified
#  define _Null_unspecified
# endif
#endif

#if defined(__has_attribute) && __has_attribute(format)
#define ATTR_FORMAT(fmt_idx, first_arg_idx)                                    \
    __attribute__((format(printf, fmt_idx, first_arg_idx)))
#elif
#define ATTR_FORMAT(fmt_idx, first_arg_idx)
#endif

// see also zend_always_inline
#if defined(__has_attribute) && __has_attribute(always_inline)
#    define ATTR_ALWAYS_INLINE inline __attribute((always_inline))
#else
#    error do not know how to force inlining for this compiler
#endif

#if defined(__has_attribute) && __has_attribute(noreturn)
#    define ATTR_NO_RETURN __attribute((noreturn))
#else
#    define ATTR_NO_RETURN
#endif

#if defined(__has_attribute) && __has_attribute(warn_unused_result)
#    define ATTR_WARN_UNUSED __attribute((warn_unused_result))
#else
#    define ATTR_WARN_UNUSED
#endif

#if defined(__has_attribute) && __has_attribute(tls_model)
#    define ATTR_TLS_LOCAL_DYNAMIC __attribute__((tls_model("local-dynamic")))
#else
#    define ATTR_TLS_LOCAL_DYNAMIC
#endif

#if defined(__has_attribute) && __has_attribute(unused)
#    define ATTR_UNUSED __attribute((unused))
#    define UNUSED(x)                                                          \
        do {                                                                   \
            ATTR_UNUSED __auto_type _ignored = (x);                            \
        } while (0)
#else
#    define ATTR_UNUSED
#    define UNUSED(x)                                                          \
        do {                                                                   \
            (void)(x);                                                         \
        } while (0)
#endif

#if defined(__GNUC__) && (__GNUC__ > 3 || __GNUC__ == 3 && __GNUC_MINOR__ > 0)
# define may_alias __attribute((__may_alias__))
#else
# define may_alias
#endif

#define nonnull _Nonnull
#define nullable _Nullable
#define unspecnull _Null_unspecified

#ifdef __cplusplus
#define EXTERN_C extern "C"
#define EXTERN_C_BEGIN extern "C" {
#define EXTERN_C_END }
#else
#define EXTERN_C
#define EXTERN_C_BEGIN
#define EXTERN_C_END
#endif

#define PACKED __attribute__((__packed__))

#endif // DD_ATTRIBUTES_H
