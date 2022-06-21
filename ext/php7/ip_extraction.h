// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2022 Datadog, Inc.

#pragma once

#include <php.h>

void dd_ip_extraction_startup(void);

// Since the headers looked at can in principle be forged, it's very much
// recommended that a datadog.appsec.ipheader is set to a header that the server
// guarantees cannot be forged
zend_string *dd_ip_extraction_find(zval *server);
