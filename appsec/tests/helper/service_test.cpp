// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "common.h"
#include <remote_config/client.hpp>
#include <service.hpp>
extern "C" {
#include <sidecar.h>
}

extern "C" {
struct ddog_RemoteConfigReader {
    std::string shm_path;
    ddog_CharSlice next_line;
};
__attribute__((visibility("default"))) ddog_RemoteConfigReader *
ddog_remote_config_reader_for_path(const char *path)
{
    return new ddog_RemoteConfigReader{path};
}
__attribute__((visibility("default"))) bool ddog_remote_config_read(
    ddog_RemoteConfigReader *reader, ddog_CharSlice *data)
{
    if (reader->next_line.len == 0) {
        return false;
    }
    data->ptr = reader->next_line.ptr;
    data->len = reader->next_line.len;
    reader->next_line.len = 0;
    return true;
}
__attribute__((visibility("default"))) void ddog_remote_config_reader_drop(
    struct ddog_RemoteConfigReader *reader)
{
    delete reader;
}

__attribute__((visibility("default"))) ddog_MaybeError
ddog_sidecar_enqueue_telemetry_log(ddog_CharSlice /*session_id_ffi*/,
    ddog_CharSlice /*runtime_id_ffi*/, ddog_CharSlice /*service_name_ffi*/,
    ddog_CharSlice /*env_name_ffi*/, ddog_CharSlice /*identifier_ffi*/,
    enum ddog_LogLevel /*level*/, ddog_CharSlice /*message_ffi*/,
    ddog_CharSlice * /*stack_trace_ffi*/, ddog_CharSlice * /*tags_ffi*/,
    bool /*is_sensitive*/)
{
    return ddog_MaybeError{
        .tag = DDOG_OPTION_ERROR_NONE_ERROR,
    };
}

__attribute__((visibility("default"))) void ddog_Error_drop(
    struct ddog_Error *error)
{
    // do nothing
}
__attribute__((visibility("default"))) ddog_CharSlice ddog_Error_message(
    const struct ddog_Error *error)
{
    return ddog_CharSlice{nullptr, 0};
}

__attribute__((constructor)) void resolve_symbols()
{
    dds::remote_config::resolve_symbols();
}
}
