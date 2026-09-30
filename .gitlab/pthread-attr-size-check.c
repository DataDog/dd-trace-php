// Copyright 2026-Present Datadog, Inc. https://www.datadoghq.com/
// SPDX-License-Identifier: Apache-2.0

#define _GNU_SOURCE

#include <dlfcn.h>
#include <fcntl.h>
#include <pthread.h>
#include <stdio.h>
#include <stdlib.h>
#include <unistd.h>

typedef int (*pthread_attr_init_fn)(pthread_attr_t *);

int pthread_attr_init(pthread_attr_t *attr)
{
    static pthread_attr_init_fn real_pthread_attr_init;

    if (real_pthread_attr_init == NULL) {
        real_pthread_attr_init =
            (pthread_attr_init_fn)dlsym(RTLD_NEXT, "pthread_attr_init");
        if (real_pthread_attr_init == NULL) {
            fputs("Unable to resolve pthread_attr_init\n", stderr);
            abort();
        }
    }

    int result = real_pthread_attr_init(attr);
    volatile unsigned char last_byte =
        ((const unsigned char *)attr)[sizeof(*attr) - 1];
    (void)last_byte;

    const char *marker = getenv("DD_PTHREAD_ATTR_SIZE_CHECK_MARKER");
    if (marker != NULL) {
        int fd = open(marker, O_WRONLY | O_APPEND | O_CLOEXEC);
        if (fd == -1 || write(fd, ".", 1) != 1) {
            fputs("Unable to record pthread_attr_t size check\n", stderr);
            abort();
        }
        close(fd);
    }
    return result;
}
