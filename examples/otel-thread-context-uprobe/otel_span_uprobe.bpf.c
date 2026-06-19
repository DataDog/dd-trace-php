#include "vmlinux.h"

#define OTEL_SPAN_UPROBE_BPF
#include "otel_span_uprobe.h"

#include <bpf/bpf_core_read.h>
#include <bpf/bpf_helpers.h>
#include <bpf/bpf_tracing.h>

#define GLIBC_DTV_ENTRY_SIZE 16
#define MUSL_DTV_ENTRY_SIZE 8
#define TLS_DTV_UNALLOCATED (~0ULL)
#define MAX_REASONABLE_MODULE_ID (1ULL << 20)

#if defined(__TARGET_ARCH_x86)
#define GLIBC_DTV_OFFSET 8
#define MUSL_DTV_OFFSET 8
#elif defined(__TARGET_ARCH_arm64)
#define GLIBC_DTV_OFFSET 0
#define MUSL_DTV_OFFSET -8
#else
#error "Only x86_64 and arm64 are supported"
#endif

struct {
    __uint(type, BPF_MAP_TYPE_ARRAY);
    __uint(max_entries, 1);
    __type(key, __u32);
    __type(value, struct tls_reader_config);
} tls_config_map SEC(".maps");

struct {
    __uint(type, BPF_MAP_TYPE_RINGBUF);
    __uint(max_entries, 256 * 1024);
} events SEC(".maps");

static __always_inline int read_user_u8(__u64 addr, __u8 *value)
{
    return bpf_probe_read_user(value, sizeof(*value), (const void *)addr);
}

static __always_inline int read_user_u64(__u64 addr, __u64 *value)
{
    return bpf_probe_read_user(value, sizeof(*value), (const void *)addr);
}

static __always_inline int current_thread_pointer(__u64 *tp)
{
    struct task_struct *task = (struct task_struct *)bpf_get_current_task_btf();

#if defined(__TARGET_ARCH_x86)
    *tp = BPF_CORE_READ(task, thread.fsbase);
#elif defined(__TARGET_ARCH_arm64)
    *tp = BPF_CORE_READ(task, thread.uw.tp_value);
#endif

    return *tp == 0 ? -1 : 0;
}

static __always_inline __u64 add_signed_offset(__u64 base, __s64 offset)
{
    if (offset < 0) {
        return base - (__u64)(-offset);
    }

    return base + (__u64)offset;
}

static __always_inline int resolve_glibc_tls_slot(const struct tls_reader_config *cfg, __u64 tp,
                                                  __u64 *slot_addr)
{
    __u64 dtv = 0;
    __u64 max_module_id = 0;
    __u64 tls_block = 0;
    __u64 dtv_ptr_addr = add_signed_offset(tp, GLIBC_DTV_OFFSET);

    if (cfg->tls_module_id == 0 || cfg->tls_module_id > MAX_REASONABLE_MODULE_ID) {
        return READ_STATUS_BAD_MODULE_ID;
    }

    if (cfg->tls_block_offset < 0) {
        return READ_STATUS_BAD_TLS_OFFSET;
    }

    if (read_user_u64(dtv_ptr_addr, &dtv) || dtv == 0) {
        return READ_STATUS_NO_DTV;
    }

    if (read_user_u64(dtv - GLIBC_DTV_ENTRY_SIZE, &max_module_id)) {
        return READ_STATUS_NO_DTV;
    }

    if (cfg->tls_module_id > max_module_id) {
        return READ_STATUS_BAD_MODULE_ID;
    }

    if (read_user_u64(dtv + cfg->tls_module_id * GLIBC_DTV_ENTRY_SIZE, &tls_block)) {
        return READ_STATUS_READ_FAILED;
    }

    if (tls_block == 0 || tls_block == TLS_DTV_UNALLOCATED) {
        return READ_STATUS_NO_TLS_BLOCK;
    }

    *slot_addr = tls_block + (__u64)cfg->tls_block_offset;
    return READ_STATUS_OK;
}

static __always_inline int resolve_musl_tls_slot(const struct tls_reader_config *cfg, __u64 tp,
                                                 __u64 *slot_addr)
{
    __u64 dtv = 0;
    __u64 max_module_id = 0;
    __u64 tls_block = 0;
    __u64 dtv_ptr_addr = add_signed_offset(tp, MUSL_DTV_OFFSET);

    if (cfg->tls_module_id == 0 || cfg->tls_module_id > MAX_REASONABLE_MODULE_ID) {
        return READ_STATUS_BAD_MODULE_ID;
    }

    if (cfg->tls_block_offset < 0) {
        return READ_STATUS_BAD_TLS_OFFSET;
    }

    if (read_user_u64(dtv_ptr_addr, &dtv) || dtv == 0) {
        return READ_STATUS_NO_DTV;
    }

    if (read_user_u64(dtv, &max_module_id)) {
        return READ_STATUS_NO_DTV;
    }

    if (cfg->tls_module_id > max_module_id) {
        return READ_STATUS_BAD_MODULE_ID;
    }

    if (read_user_u64(dtv + cfg->tls_module_id * MUSL_DTV_ENTRY_SIZE, &tls_block)) {
        return READ_STATUS_READ_FAILED;
    }

    if (tls_block == 0) {
        return READ_STATUS_NO_TLS_BLOCK;
    }

    *slot_addr = tls_block + (__u64)cfg->tls_block_offset;
    return READ_STATUS_OK;
}

static __always_inline int resolve_tls_slot(const struct tls_reader_config *cfg, __u64 tp,
                                            __u64 *slot_addr)
{
    if (cfg->libc_kind == LIBC_KIND_GLIBC) {
        return resolve_glibc_tls_slot(cfg, tp, slot_addr);
    }

    if (cfg->libc_kind == LIBC_KIND_MUSL) {
        return resolve_musl_tls_slot(cfg, tp, slot_addr);
    }

    return READ_STATUS_BAD_LIBC;
}

static __always_inline __u64 read_be64(const __u8 bytes[8])
{
    __u64 value = 0;

#pragma unroll
    for (int i = 0; i < 8; i++) {
        value = (value << 8) | bytes[i];
    }

    return value;
}

static __always_inline void emit_event(__u32 status, const struct tls_reader_config *cfg,
                                       __u64 tls_slot_addr, __u64 record_addr, __u64 span_id)
{
    struct span_event *event;
    __u64 pid_tgid = bpf_get_current_pid_tgid();

    event = bpf_ringbuf_reserve(&events, sizeof(*event), 0);
    if (!event) {
        return;
    }

    event->tgid = pid_tgid >> 32;
    event->tid = (__u32)pid_tgid;
    event->status = status;
    event->libc_kind = cfg ? cfg->libc_kind : LIBC_KIND_UNKNOWN;
    event->tls_slot_addr = tls_slot_addr;
    event->record_addr = record_addr;
    event->span_id = span_id;
    bpf_get_current_comm(event->comm, sizeof(event->comm));

    bpf_ringbuf_submit(event, 0);
}

SEC("uprobe")
int BPF_KPROBE(handle_zif_sleep)
{
    __u32 key = 0;
    __u64 tp = 0;
    __u64 tls_slot_addr = 0;
    __u64 record_addr = 0;
    __u8 valid_before = 0;
    __u8 valid_after = 0;
    __u8 span_id_bytes[8] = {};
    int status;
    const struct tls_reader_config *cfg = bpf_map_lookup_elem(&tls_config_map, &key);

    if (!cfg) {
        emit_event(READ_STATUS_NO_CONFIG, cfg, 0, 0, 0);
        return 0;
    }

    if (current_thread_pointer(&tp)) {
        emit_event(READ_STATUS_NO_THREAD_POINTER, cfg, 0, 0, 0);
        return 0;
    }

    status = resolve_tls_slot(cfg, tp, &tls_slot_addr);
    if (status != READ_STATUS_OK) {
        emit_event(status, cfg, tls_slot_addr, 0, 0);
        return 0;
    }

    if (tls_slot_addr == 0) {
        emit_event(READ_STATUS_NO_TLS_SLOT, cfg, tls_slot_addr, 0, 0);
        return 0;
    }

    if (read_user_u64(tls_slot_addr, &record_addr)) {
        emit_event(READ_STATUS_READ_FAILED, cfg, tls_slot_addr, 0, 0);
        return 0;
    }

    if (record_addr == 0) {
        emit_event(READ_STATUS_NO_RECORD, cfg, tls_slot_addr, 0, 0);
        return 0;
    }

    if (read_user_u8(record_addr + 24, &valid_before) || valid_before != 1) {
        emit_event(READ_STATUS_INVALID_RECORD, cfg, tls_slot_addr, record_addr, 0);
        return 0;
    }

    if (bpf_probe_read_user(span_id_bytes, sizeof(span_id_bytes), (const void *)(record_addr + 16))) {
        emit_event(READ_STATUS_READ_FAILED, cfg, tls_slot_addr, record_addr, 0);
        return 0;
    }

    if (read_user_u8(record_addr + 24, &valid_after) || valid_after != 1) {
        emit_event(READ_STATUS_INVALID_RECORD, cfg, tls_slot_addr, record_addr, 0);
        return 0;
    }

    emit_event(READ_STATUS_OK, cfg, tls_slot_addr, record_addr, read_be64(span_id_bytes));
    return 0;
}

char LICENSE[] SEC("license") = "Dual BSD/GPL";
