// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog (https://www.datadoghq.com/).
// Copyright 2021 Datadog, Inc.
#pragma once

#include <php.h>
#include "attributes.h"
#include <stdbool.h>

void dd_entity_body_startup(void);
void dd_entity_body_gshutdown(void);
void dd_entity_body_rinit(void);
zend_string *nonnull dd_request_body_buffered(size_t limit);
zend_string *nonnull dd_response_body_buffered(void);

zval dd_entity_body_convert(
    const char *nonnull ct, size_t ct_len, zend_string *nonnull entity);
