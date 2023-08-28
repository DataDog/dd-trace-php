#ifndef DDTRACE_EXPORT_H
#define DDTRACE_EXPORT_H

// clang-format off
/* Define DDTRACE_STATIC before including this header to avoid exporting the
 * functions, which usually is what is desired when building a static lib,
 * hence the name.
 */
#ifdef DDTRACE_STATIC
#  define DDTRACE_PUBLIC
#else
#  ifndef DDTRACE_PUBLIC
#    ifndef _WIN32
#      define DDTRACE_PUBLIC __attribute__((visibility("default")))
#    else
#      define DDTRACE_PUBLIC __declspec(dllexport)
#    endif
#  endif
#endif

#ifndef DDTRACE_DEPRECATED
#  define DDTRACE_DEPRECATED __attribute__ ((__deprecated__))
#endif
// clang-format on

#endif /* DDTRACE_EXPORT_H */
