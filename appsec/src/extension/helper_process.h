// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#ifndef DD_HELPER_MGR_H
#define DD_HELPER_MGR_H

#include <components-rs/ddtrace.h>
#include <stdbool.h>

#include "attributes.h"
#include "dddefs.h"
#include "network.h"

typedef enum _helper_runtime {
    HELPER_RUNTIME_UNKNOWN = 0,
    HELPER_RUNTIME_CPP = 1,
    HELPER_RUNTIME_RUST = 2,
} helper_runtime;

helper_runtime dd_helper_get_runtime(void);
void dd_helper_set_runtime(helper_runtime rt);
bool dd_helper_is_rust(void);

typedef typeof(&ddog_sidecar_enable_appsec) sidecar_enable_appsec_t;

__attribute__((visibility("default"))) bool dd_appsec_maybe_enable_helper(
    sidecar_enable_appsec_t nonnull enable_appsec,
    bool *nonnull appsec_activation, bool *nonnull appsec_conf);

void dd_helper_startup(void);
void dd_helper_shutdown(void);
void dd_helper_gshutdown(void);
void dd_helper_rshutdown(void);

typedef dd_result (*client_init_func)(dd_conn *nonnull, void *unspecnull ctx);
dd_conn *nullable dd_helper_mgr_acquire_conn(
    client_init_func nonnull, void *unspecnull ctx);
dd_conn *nullable dd_helper_mgr_cur_conn(void);
void dd_helper_close_conn(void);

bool dd_on_runtime_path_update(zval *nullable old_value,
    zval *nonnull new_value, zend_string *nullable new_str);

#endif // DD_HELPER_MGR_H
