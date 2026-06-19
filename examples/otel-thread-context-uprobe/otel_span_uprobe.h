#pragma once

#ifdef OTEL_SPAN_UPROBE_BPF
typedef __u8 otu_u8;
typedef __u32 otu_u32;
typedef __u64 otu_u64;
typedef __s64 otu_s64;
#else
#include <stdint.h>
typedef uint8_t otu_u8;
typedef uint32_t otu_u32;
typedef uint64_t otu_u64;
typedef int64_t otu_s64;
#endif

enum libc_kind {
    LIBC_KIND_UNKNOWN = 0,
    LIBC_KIND_GLIBC = 1,
    LIBC_KIND_MUSL = 2,
};

enum read_status {
    READ_STATUS_OK = 0,
    READ_STATUS_NO_CONFIG = 1,
    READ_STATUS_TGID_MISMATCH = 2,
    READ_STATUS_NO_THREAD_POINTER = 3,
    READ_STATUS_BAD_LIBC = 4,
    READ_STATUS_BAD_TLS_OFFSET = 5,
    READ_STATUS_NO_DTV = 6,
    READ_STATUS_BAD_MODULE_ID = 7,
    READ_STATUS_NO_TLS_BLOCK = 8,
    READ_STATUS_NO_TLS_SLOT = 9,
    READ_STATUS_NO_RECORD = 10,
    READ_STATUS_INVALID_RECORD = 11,
    READ_STATUS_READ_FAILED = 12,
};

struct tls_reader_config {
    otu_u64 tls_module_id;
    otu_s64 tls_block_offset;
    otu_u32 libc_kind;
    otu_u32 target_tgid;
};

struct span_event {
    otu_u32 tgid;
    otu_u32 tid;
    otu_u32 status;
    otu_u32 libc_kind;
    otu_u64 tls_slot_addr;
    otu_u64 record_addr;
    otu_u64 span_id;
    char comm[16];
};
