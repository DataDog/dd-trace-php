#ifndef DD_MACROS_H
#define DD_MACROS_H

#ifndef _XOPEN_SOURCE
#if defined(__linux__) || defined(__OpenBSD__) || defined(__NetBSD__)
#define _XOPEN_SOURCE 700
#elif defined(__APPLE__) && defined(__MACH__)
#define _XOPEN_SOURCE
#elif defined(__FreeBSD__)
// intentionally left blank, don't define _XOPEN_SOURCE
#elif defined(AIX)
// intentionally left blank, don't define _XOPEN_SOURCE
#else
#define _XOPEN_SOURCE
#endif
#endif  //_XOPEN_SOURCE

#endif  // DD_MACROS_H
