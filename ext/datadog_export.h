#ifndef DATADOG_EXPORT_H
#define DATADOG_EXPORT_H

// clang-format off
/* Define DATADOG_STATIC before including this header to avoid exporting the
 * functions, which usually is what is desired when building a static lib,
 * hence the name.
 */
#ifdef DATADOG_STATIC
#  define DATADOG_PUBLIC
#else
#  ifndef DATADOG_PUBLIC
#    ifndef _WIN32
#      define DATADOG_PUBLIC __attribute__((visibility("default")))
#    else
#      define DATADOG_PUBLIC __declspec(dllexport)
#    endif
#  endif
#endif

#ifndef DATADOG_DEPRECATED
#  define DATADOG_DEPRECATED __attribute__ ((__deprecated__))
#endif
// clang-format on

#endif /* DDTRACE_EXPORT_H */
