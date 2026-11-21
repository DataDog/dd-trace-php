// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#ifndef DD_HELPER_MGR_H
#define DD_HELPER_MGR_H

// The declarations are in ddappsec_api.h, for the sidecar setup.
#include "ddappsec_api.h"

void dd_helper_process_startup(void);

#endif // DD_HELPER_MGR_H
