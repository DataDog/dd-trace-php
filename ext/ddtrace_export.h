#ifndef DDTRACE_EXPORT_H
#define DDTRACE_EXPORT_H

// clang-format off
/* Define DDTRACE_STATIC before including this header to avoid exporting the
 * functions, which usually is what is desired when building a static lib,
 * hence the name.
 */
#ifdef DDTRACE_STATIC
#  define DDTRACE_PUBLIC
#  define DDTRACE_LOCAL
#else
#  ifndef DDTRACE_PUBLIC
#    define DDTRACE_PUBLIC __attribute__((visibility("default")))
#  endif

#  ifndef DDTRACE_LOCAL
#    define DDTRACE_LOCAL __attribute__((visibility("hidden")))
#  endif
#endif

#ifndef DDTRACE_DEPRECATED
#  define DDTRACE_DEPRECATED __attribute__ ((__deprecated__))
#endif
// clang-format on

#endif /* DDTRACE_EXPORT_H */
