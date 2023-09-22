// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once
#include "attributes.h"
#include "configuration.h"
#include <php.h>
#include <stdbool.h>
#include <zend.h>

#define DD_TAG_DATA_MAX_LEN (1024UL * 1024UL)

void dd_tags_startup(void);
void dd_tags_shutdown(void);
void dd_tags_rinit(void);
void dd_tags_rshutdown(void);
void dd_tags_add_tags(void);
void dd_tags_add_blocked(void);
void dd_tags_rshutdown_testing(void);

void dd_tags_set_sampling_priority();

// Copies (or increases refcount) of zstr
void dd_tags_set_event_user_id(zend_string *nonnull zstr);

// does not increase the refcount on zstr
void dd_tags_add_appsec_json_frag(zend_string *nonnull zstr);

bool dd_parse_automated_user_events_tracking(
    zai_str value, zval *nonnull decoded_value, bool persistent);
