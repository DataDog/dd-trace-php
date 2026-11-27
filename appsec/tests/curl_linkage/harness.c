// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

// Runs dd_curl_linkage_detect() against a fake ext/curl object and checks the
// verdict. Usage:
//   harness [--no-minit] <fake_ext.so> <on|off> <expected reason substring>
// --no-minit skips the fake MINIT (so curl_global_init is never called).

#include "../../src/extension/downstream/curl_linkage_detect.h"

#include <dlfcn.h>
#include <stdio.h>
#include <string.h>

int main(int argc, char **argv)
{
    int argi = 1;
    int minit = 1;
    if (argi < argc && strcmp(argv[argi], "--no-minit") == 0) {
        minit = 0;
        argi++;
    }
    if (argc - argi != 3) {
        fprintf(
            stderr, "usage: %s [--no-minit] <so> <on|off> <reason>\n", argv[0]);
        return 2;
    }
    const char *so = argv[argi];
    int expect_on = strcmp(argv[argi + 1], "on") == 0;
    const char *expect_reason = argv[argi + 2];

    void *h = dlopen(so, RTLD_LAZY | RTLD_LOCAL);
    if (!h) {
        fprintf(stderr, "dlopen(%s): %s\n", so, dlerror());
        return 2;
    }
    int (*fake_minit)(void) = (int (*)(void))dlsym(h, "fake_ext_minit");
    if (!fake_minit) {
        fprintf(stderr, "no fake_ext_minit in %s\n", so);
        return 2;
    }
    if (minit) {
        fake_minit();
    }

    const char *(*ext_version)(void) =
        (const char *(*)(void))dlsym(h, "fake_ext_version");

    struct dd_curl_linkage res;
    dd_curl_linkage_detect((const void *)fake_minit, &res);
    printf("enabled=%d reason=\"%s\" path=\"%s\" version=\"%s\" "
           "version_num=%#x\n",
        res.enabled, res.reason, res.libcurl_path, res.libcurl_version,
        res.libcurl_version_num);

    if (minit && ext_version) {
        // only after detection: calling it binds the lazy GOT slot
        printf("calls from the fake extension reach libcurl \"%s\"\n",
            ext_version());
    }

    int ok = res.enabled == expect_on && strstr(res.reason, expect_reason);
    if (res.enabled) {
#define CHECK_FN(name, ret, params) ok = ok && res.funcs.name != NULL;
        DD_CURL_FUNCTIONS(CHECK_FN)
#undef CHECK_FN
        ok = ok && res.funcs.curl_version_info(0)->version_num ==
                       res.libcurl_version_num;
    } else {
#define CHECK_FN(name, ret, params) ok = ok && res.funcs.name == NULL;
        DD_CURL_FUNCTIONS(CHECK_FN)
#undef CHECK_FN
    }
    printf("%s\n", ok ? "PASS" : "FAIL");
    return ok ? 0 : 1;
}
