// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog (https://www.datadoghq.com/).
// Copyright 2021 Datadog, Inc.
#pragma once

#include "../network.h"
#include "../commands_ctx.h"

dd_result dd_client_init(dd_conn *nonnull conn, struct req_info *nonnull ctx);
