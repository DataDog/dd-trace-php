// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog (https://www.datadoghq.com/).
// Copyright 2021 Datadog, Inc.
#pragma once

#include <php.h>
#include <stdbool.h>
#include "attributes.h"

#define DD_MAX_REQ_BODY_TO_BUFFER (1L * 1024L * 1024L) // 1 MB

zend_string *nonnull dd_request_body_buffered(size_t limit);
