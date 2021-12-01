// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog (https://www.datadoghq.com/).
// Copyright 2021 Datadog, Inc.
#pragma once

void dd_request_abort_startup(void);
// noreturn unless called from rinit on fpm
void dd_request_abort_static_page(void);
