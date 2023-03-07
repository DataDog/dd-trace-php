// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include "attributes.h"
#include <zend.h>

void dd_user_tracking_startup(void);
void dd_user_tracking_shutdown(void);
void dd_find_and_apply_verdict_for_user(zend_string *nonnull user_id);
