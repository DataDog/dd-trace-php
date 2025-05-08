// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include <remote_config/client.hpp>
#include <service.hpp>
extern "C" {
#include <sidecar_ffi.h>
}

extern "C" {
struct ddog_CharSlice {
    const char *ptr;
    uintptr_t len;
};
struct ddog_RemoteConfigReader {
    std::string shm_path;
    struct ddog_CharSlice next_line;
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

__attribute__((visibility("default"))) enum FfiError
ddog_sidecar_enqueue_telemetry_log(struct FfiString /*session_id_ffi*/,
    struct FfiString /*runtime_id_ffi*/, uint64_t /*-queue_id*/,
    struct FfiString /*identifier_ffi*/, enum CLogLevel /*level*/,
    struct FfiString /*message_ffi*/, struct FfiString * /*stack_trace_ffi*/,
    struct FfiString * /*tags_ffi*/, bool /*is_sensitive*/)
{
    return FfiError::Ok;
}

__attribute__((constructor)) void resolve_symbols()
{
    dds::remote_config::resolve_symbols();
}
}
