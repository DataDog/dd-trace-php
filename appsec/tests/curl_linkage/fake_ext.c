// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

// A stand-in for ext/curl (curl.so, or the php binary with ext/curl built
// in): fake_ext_minit() plays the role of ext/curl's MINIT (it calls
// curl_global_init, binding its GOT slot even under lazy binding), and its
// address is the anchor handed to the detection. fake_ext_use() references
// the other functions, like ext/curl does, but is never called;
// fake_ext_version() reports which libcurl copy the object's calls reach.

// stays exported when built with -fvisibility=hidden
#define FAKE_EXT_API __attribute__((visibility("default")))

int curl_global_init(long flags);
int curl_easy_setopt(void *curl, int option, ...);
int curl_easy_getinfo(void *curl, int info, ...);
void *curl_version_info(int age);

FAKE_EXT_API int fake_ext_minit(void) { return curl_global_init(3); }

// version string of the libcurl instance this object's calls actually reach
FAKE_EXT_API const char *fake_ext_version(void)
{
    struct {
        int age;
        const char *version;
    } *info = curl_version_info(0);
    return info->version;
}

FAKE_EXT_API int fake_ext_use(void)
{
    return curl_easy_setopt((void *)0, 0) + curl_easy_getinfo((void *)0, 0) +
           (curl_version_info(0) != (void *)0);
}
