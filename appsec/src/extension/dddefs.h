// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include "attributes.h"
// fallback (misc) header with non-PHP related definitions

// Error codes
typedef enum {
    dd_success = 0,
    dd_network,      // error in communication; connection should be abandoned
    dd_should_block, // caller should abort the request
    dd_should_redirect, // caller should redirect the request
    dd_error,           // misc error
    dd_try_later,       // non-fatal error, try again
    dd_helper_error     // helper failed to process message (non-fatal)
} dd_result;

const char *nonnull dd_result_to_string(dd_result result);

#define ARRAY_SIZE(x) (sizeof(x) / sizeof((x)[0]))
