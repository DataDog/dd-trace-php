// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include "zai_string/string.h"
#include "attributes.h"
#include <zend.h>

typedef enum _user_collection_mode {
    user_mode_disabled = 0,
    user_mode_anon,
    user_mode_ident,
    user_mode_undefined,
} user_collection_mode;

void dd_user_tracking_startup(void);
void dd_user_tracking_shutdown(void);

void dd_find_and_apply_verdict_for_user(
    zend_string *nullable user_id, zend_string *nullable user_login);

bool dd_parse_user_collection_mode(
    zai_str value, zval *nonnull decoded_value, bool persistent);

void dd_parse_user_collection_mode_rc(
    const char *nonnull value, size_t value_len);

zend_string *nullable dd_user_info_anonymize(zend_string *nonnull user_info);

user_collection_mode dd_get_user_collection_mode(void);
zend_string *nonnull dd_get_user_collection_mode_zstr(void);
