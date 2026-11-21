// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

#include "request_exec.h"
#include "../appsec_requests.h"
#include "../ddappsec.h"
#include "../logging.h"
#include "../msgpack_helpers.h"
#include <php.h>
#include <zend_hash.h>
#include <zend_types.h>

dd_result dd_request_exec(zend_array *nonnull data,
    const struct req_exec_opts *nonnull opts,
    struct block_params *nonnull block_params)
{
    if (zend_hash_num_elements(data) == 0) {
        mlog(dd_log_info, "Skipping request_exec with empty payload");
        return dd_error;
    }

    dd_appsec_addresses addresses;
    dd_appsec_addresses_init(&addresses);
    dd_mpack_limits limits = dd_mpack_def_limits;
    dd_mpack_write_array_lim(&addresses.writer, data, &limits);
    if (dd_mpack_limits_reached(&limits)) {
        mlog(dd_log_info, "Limits reached when serializing request exec data");
    }

    struct req_info req_info = {0};
    dd_result res = dd_appsec_send_request_exec(
        &addresses, opts->rasp_rule, opts->rule_variant, &req_info);
    memcpy(block_params, &req_info.block_params, sizeof *block_params);
    return res;
}
