// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#ifdef TESTING
#    include <components-rs/common.h>

// True while the mock transport is active (set by
// dd_testing_setup_mock_transport). dd_trace_send_appsec_message checks this
// before touching the real sidecar.
extern bool dd_testing_send_active;

// Drop-in for ddog_sidecar_send_appsec_message used in phpt tests.
// Connects directly to the mock_helper Unix socket and performs the same framed
// request/response exchange the real sidecar would. session_id is ignored.
ddog_AppsecCResponse dd_testing_mock_send_appsec_message(
    ddog_CharSlice session_id, uint64_t client_id, ddog_CharSlice data);

// Activate the mock transport. Call once during extension startup when
// DD_APPSEC_TESTING is true.
void dd_testing_setup_mock_transport(void);
#endif /* TESTING */
