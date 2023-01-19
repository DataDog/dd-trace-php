// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2022 Datadog, Inc.

#pragma once

#include "attributes.h"
#include "zai_string/string.h"
#include <php.h>

void dd_ip_extraction_startup(void);
void dd_ip_extraction_rinit(void);
void dd_ip_extraction_rshutdown(void);

// Since the headers looked at can in principle be forged, it's very much
// recommended that a datadog.appsec.ipheader is set to a header that the server
// guarantees cannot be forged
zend_string *nullable dd_ip_extraction_find(
    zval *nonnull server, zval *nullable duplicated_headers);
bool dd_parse_client_ip_header_config(
    zai_string_view value, zval *nonnull decoded_value, bool persistent);
zend_string *nullable dd_ip_extraction_get_ip();
zval *nullable dd_ip_extraction_get_duplicated_headers();