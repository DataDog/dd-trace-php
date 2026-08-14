#define _GNU_SOURCE

#include <dlfcn.h>
#include <errno.h>
#include <fcntl.h>
#include <stdatomic.h>
#include <stdio.h>
#include <stdlib.h>
#include <sys/socket.h>
#include <time.h>
#include <unistd.h>

#if defined(__linux__) && !defined(__GLIBC__)

#ifdef __aarch64__
#define MUSL_LIBC_DSO "ld-musl-aarch64.so.1"
#else
#define MUSL_LIBC_DSO "libc.musl-x86_64.so.1"
#endif

#define LAZY_RESOLVE(var, init_expr, cleanup_expr)                                                                         \
    __extension__({                                                                                                        \
        void *_lv = atomic_load_explicit(&(var), memory_order_acquire);                                                    \
        if (!_lv) {                                                                                                        \
            _lv = (init_expr);                                                                                             \
            void *_le = NULL;                                                                                              \
            if (!atomic_compare_exchange_strong_explicit(&(var), &_le, _lv, memory_order_release, memory_order_relaxed)) { \
                void *p = _lv;                                                                                             \
                (void)(cleanup_expr);                                                                                      \
                _lv = _le;                                                                                                 \
            }                                                                                                              \
        }                                                                                                                  \
        _lv;                                                                                                               \
    })

static int is_glibc(void) {
    static _Atomic(int) cached = -1;
    int value = atomic_load_explicit(&cached, memory_order_acquire);
    if (value < 0) {
        value = dlsym(RTLD_DEFAULT, "gnu_get_libc_version") != NULL;
        atomic_store_explicit(&cached, value, memory_order_release);
    }
    return value;
}

static void *host_libc_handle(void) {
    static _Atomic(void *) handle;
    return LAZY_RESOLVE(handle, ({
                            const char *dso = is_glibc() ? "libc.so.6" : MUSL_LIBC_DSO;
                            void *_handle = dlopen(dso, RTLD_LAZY);
                            if (!_handle) {
                                (void)fprintf(stderr, "glibc_compat: dlopen(%s) failed: %s\n", dso, dlerror());
                                abort();
                            }
                            _handle;
                        }),
                        dlclose(p));
}

static void *musl_libc_handle(void) {
    static _Atomic(void *) handle;
    return LAZY_RESOLVE(handle, ({
                            void *_handle = dlopen(MUSL_LIBC_DSO, RTLD_LAZY);
                            if (!_handle) {
                                (void)fprintf(stderr, "glibc_compat: dlopen(%s) failed: %s\n", MUSL_LIBC_DSO, dlerror());
                                abort();
                            }
                            _handle;
                        }),
                        dlclose(p));
}

static void *xdlsym(void *handle, const char *name) {
    void *symbol = dlsym(handle, name);
    if (!symbol) {
        (void)fprintf(stderr, "glibc_compat: dlsym(%s) failed: %s\n", name, dlerror());
        abort();
    }
    return symbol;
}

#ifdef __x86_64__
float ceilf(float x) {
    float result;
    // NOLINTNEXTLINE(hicpp-no-assembler)
    __asm__("roundss $0x0A, %[x], %[result]" : [result] "=x"(result) : [x] "x"(x));
    return result;
}
double ceil(double x) {
    double result;
    // NOLINTNEXTLINE(hicpp-no-assembler)
    __asm__("roundsd $0x0A, %[x], %[result]" : [result] "=x"(result) : [x] "x"(x));
    return result;
}
#endif

#ifdef __aarch64__
float ceilf(float x) {
    float result;
    __asm__("frintp %s0, %s1\n" : "=w"(result) : "w"(x));
    return result;
}
double ceil(double x) {
    double result;
    __asm__("frintp %d0, %d1\n" : "=w"(result) : "w"(x));
    return result;
}
#endif

#ifdef __aarch64__
#define _STAT_VER 0
#else
#define _STAT_VER 1
#endif

// glibc before 2.33 (2021) doesn't have these
int stat(const char *restrict path, void *restrict buf) {
    int __xstat(int, const char *restrict, void *restrict);
    return __xstat(_STAT_VER, path, buf);
}

int fstat(int fd, void *buf) {
    int __fxstat(int, int, void *);
    return __fxstat(_STAT_VER, fd, buf);
}

int lstat(const char *restrict path, void *restrict buf) {
    int __lxstat(int, const char *restrict, void *restrict);
    return __lxstat(_STAT_VER, path, buf);
}

int fstatat(int dirfd, const char *restrict pathname, void *restrict statbuf, int flags) {
    int __fxstatat(int, int, const char *restrict, void *restrict, int);
    return __fxstatat(_STAT_VER, dirfd, pathname, statbuf, flags);
}

// glibc doesn't define pthread_atfork on aarch64. We need to delegate to
// glibc's __register_atfork() instead. __register_atfork() takes an extra
// argument, __dso_handle, which is a pointer to the DSO that is registering the
// fork handlers. This is used to ensure that the handlers are not called after
// the DSO is unloaded. glibc on amd64 also implements pthread_atfork() in terms
// of __register_atfork().  (musl never unloads modules so that potential
// problem doesn't exist)

// On amd64, even though pthread_atfork is exported by glibc, it should not be
// used. Code that uses pthread_atfork will compile to an import to
// __register_atfork(), but here we're compiling against musl, resulting in an
// import to pthread_atfork. This will cause a runtime error after the test that
// unloads our module. The reason is that when we call pthread_atfork in glibc,
// __register_atfork() is called with the __dso_handle of libc6.so, not the
// __dso_handle of our module. So the fork handler is not unregistered when our
// module is unloaded.

extern void *__dso_handle __attribute__((weak));
int __register_atfork(void (*prepare)(void), void (*parent)(void), void (*child)(void), void *__dso_handle) __attribute__((weak));

int pthread_atfork(void (*prepare)(void), void (*parent)(void), void (*child)(void)) {
    // glibc
    if (__dso_handle && __register_atfork) {
        return __register_atfork(prepare, parent, child, __dso_handle);
    }

    static _Atomic(void *) real_atfork;
    void *function = LAZY_RESOLVE(real_atfork, xdlsym(musl_libc_handle(), "pthread_atfork"), (void)p);
    return ((int (*)(void (*)(void), void (*)(void), void (*)(void)))function)(prepare, parent, child);
}

int recvmmsg(int fd, struct mmsghdr *messages, unsigned int count, unsigned int flags, struct timespec *timeout) {
    // On 64-bit platforms, musl represents msg_iovlen and msg_controllen as
    // 32-bit values followed by explicit padding. glibc represents each pair
    // as a size_t. Code compiled against musl must therefore clear the padding
    // before passing the structure to glibc.
#if __SIZEOF_LONG__ > 4
    for (unsigned int i = 0; i < count; i++) {
        messages[i].msg_hdr.__pad1 = 0;
        messages[i].msg_hdr.__pad2 = 0;
    }
#endif

    static _Atomic(void *) real_recvmmsg;
    void *function = LAZY_RESOLVE(real_recvmmsg, xdlsym(host_libc_handle(), "recvmmsg"), (void)p);
    return ((int (*)(int, struct mmsghdr *, unsigned int, unsigned int, struct timespec *))function)(fd, messages, count, flags, timeout);
}

#ifdef __x86_64__
char *realpath(const char *restrict path, char *restrict resolved) {
    static _Atomic(void *) real_realpath;
    void *function = LAZY_RESOLVE(real_realpath, xdlsym(host_libc_handle(), "realpath"), (void)p);
    return ((char *(*)(const char *restrict, char *restrict))function)(path, resolved);
}

struct pthread_cond;
struct pthread_condattr;
typedef struct pthread_cond pthread_cond_t;
typedef struct pthread_condattr pthread_condattr_t;

int pthread_cond_init(pthread_cond_t *cond, const pthread_condattr_t *cond_attr) {
    static _Atomic(void *) real_pthread_cond_init;
    void *function = LAZY_RESOLVE(real_pthread_cond_init, xdlsym(host_libc_handle(), "pthread_cond_init"), (void)p);
    return ((int (*)(pthread_cond_t *, const pthread_condattr_t *))function)(cond, cond_attr);
}
#endif

// the symbol strerror_r in glibc is not the POSIX version; it returns char *
// __xpg_strerror_r is exported by both glibc and musl
int strerror_r(int errnum, char *buf, size_t buflen) {
    int __xpg_strerror_r(int, char *, size_t);
    return __xpg_strerror_r(errnum, buf, buflen);
}

// when compiling with --coverage, some references to atexit show up.
// glibc doesn't provide atexit for similar reasons as pthread_atfork presumably
int __cxa_atexit(void (*func)(void *), void *arg, void *dso_handle);
int atexit(void (*function)(void)) {
    if (!__dso_handle) {
        (void)fprintf(stderr, "Aborting because __dso_handle is NULL\n");
        abort();
    }

    // the cast is harmless on amd64 and aarch64. Passing an extra argument to a
    // function that expects none causes no problems
    return __cxa_atexit((void (*)(void *))function, 0, __dso_handle);
}

// introduced in glibc 2.25
ssize_t getrandom(void *buf, size_t buflen, unsigned int flags) {
    (void)flags;
    // SYS_getrandom is 318 (amd64) or 278 (aarch64)
    // This was only added in Linux 3.17 (2014), so don't use it
    // return syscall(SYS_getrandom, buf, buflen, flags);
    int fd;
    size_t bytes_read = 0;

    fd = open("/dev/urandom", O_RDONLY);
    if (fd < 0) {
        return -1;
    }

    while (bytes_read < buflen) {
        ssize_t result = read(fd, (char *)buf + bytes_read, buflen - bytes_read);
        if (result < 0) {
            if (errno == EINTR) {
                continue;
            }
            close(fd);
            return -1;
        }
        bytes_read += result;
    }

    close(fd);
    return (ssize_t)bytes_read;
}

#ifdef __x86_64__
#define MEMFD_CREATE_SYSCALL 319
#elif __aarch64__
#define MEMFD_CREATE_SYSCALL 279
#endif

// introduced in glibc 2.27
int memfd_create(const char *name, unsigned flags) { return syscall(MEMFD_CREATE_SYSCALL, name, flags); }

#endif
