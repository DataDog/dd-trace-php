// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include <SAPI.h>
#include <php.h>

#include "../network.h"
#include "../request_abort.h"

dd_result dd_request_exec(dd_conn *nonnull conn, zval *nonnull data,
    zend_string *nullable rasp_rule, struct block_params *nonnull block_params);
