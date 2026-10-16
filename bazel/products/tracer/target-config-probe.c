#include <config.h>

#if !defined(COMPILE_DL_DDTRACE) || COMPILE_DL_DDTRACE != 1
#error COMPILE_DL_DDTRACE must select the shared PHP extension registration path
#endif
#if !defined(HAVE_LINUX_SECUREBITS_H) || !defined(HAVE_LINUX_CAPABILITY_H)
#error target sysroot must supply Linux securebits and capabilities headers
#endif
#if !defined(_REENTRANT)
#error tracer pthread sources must compile with the reentrant libc interface
#endif

#include <linux/capability.h>
#include <linux/securebits.h>

#if defined(DDTRACE_EXPECT_BACKTRACE)
#if !defined(HAVE_EXECINFO_H) || !defined(HAVE_BACKTRACE)
#error glibc target sysroot must supply the configured backtrace interface
#endif
#include <execinfo.h>
static backtrace_size_t (*const backtrace_type_probe)(void **, int) = backtrace;
#else
#if defined(HAVE_EXECINFO_H) || defined(HAVE_BACKTRACE) || defined(backtrace_size_t)
#error musl target config must not claim the unavailable execinfo interface
#endif
#endif

int ddtrace_target_config_probe(void) {
    return SECBIT_NO_SETUID_FIXUP < 0 || _LINUX_CAPABILITY_VERSION_3 == 0
#if defined(DDTRACE_EXPECT_BACKTRACE)
           || backtrace_type_probe == 0
#endif
        ;
}
