// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog (https://www.datadoghq.com/).
// Copyright 2021 Datadog, Inc.
#ifndef DD_HELPER_MGR_H
#define DD_HELPER_MGR_H

#include "dddefs.h"
#include "network.h"
#include "attributes.h"

void dd_helper_startup(void);
void dd_helper_shutdown(void);
void dd_helper_rshutdown(void);

typedef dd_result (*client_init_func)(dd_conn *nonnull);
dd_conn *nullable dd_helper_mgr_acquire_conn(client_init_func nonnull);
dd_conn *nullable dd_helper_mgr_cur_conn(void);
void dd_helper_close_conn(void);

#endif // DD_HELPER_MGR_H
