// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

// A preload shim that interposes curl_easy_setopt only. The linkage
// detection does not detect it (the exception to G3.5); the harness asserts
// that, so that a later hardening cannot contradict the design silently.

int curl_easy_setopt(void *curl, int option, ...)
{
    (void)curl;
    (void)option;
    return 0;
}
