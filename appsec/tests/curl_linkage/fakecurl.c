// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

// A stand-in for libcurl: the functions the linkage detection looks for.
// Compiled as a shared library with a SONAME (a "dynamic libcurl"), or into
// the fake extension itself (a "static libcurl").

#ifndef FAKECURL_VERSION
#    define FAKECURL_VERSION "7.99.0-fake"
#endif

typedef struct {
    int age;
    const char *version;
    unsigned int version_num;
} fake_version_info;

int curl_easy_setopt(void *curl, int option, ...)
{
    (void)curl;
    (void)option;
    return 0;
}

static fake_version_info info = {0, FAKECURL_VERSION, 0x076300};

int curl_global_init(long flags)
{
    (void)flags;
    return 0;
}

int curl_easy_getinfo(void *curl, int info, ...)
{
    (void)curl;
    (void)info;
    return 0;
}

fake_version_info *curl_version_info(int age)
{
    (void)age;
    return &info;
}

void *curl_easy_init(void) { return 0; }

void curl_easy_cleanup(void *curl) { (void)curl; }
