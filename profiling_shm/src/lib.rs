#![no_std]

pub(crate) mod atomic;
pub(crate) mod function_interner;
pub(crate) mod group;
pub(crate) mod hash;
pub mod shm;
pub(crate) mod spinlock;
pub(crate) mod string_interner;

// ── Public types ─────────────────────────────────────────────────────────────

#[repr(transparent)]
#[derive(Copy, Clone, Debug, PartialEq, Eq)]
pub struct StringIndex(pub u32);

#[repr(transparent)]
#[derive(Copy, Clone, Debug, PartialEq, Eq)]
pub struct FunctionIndex(pub u32);

/// Single error type for string and function interning.
#[repr(u8)]
#[derive(Copy, Clone, Debug, PartialEq, Eq)]
pub enum InternError {
    /// `s.len() > MAX_STR_LEN`; only returned by `intern_str`.
    StrTooLong = 1,
    /// Hash table or arena is full.
    OutOfMemory = 2,
    /// Spinlock is held (async-signal-safe re-entrancy guard).
    WouldBlock = 3,
}

#[repr(C)]
#[derive(Copy, Clone, Debug)]
pub struct Function {
    pub name: StringIndex,
    pub file: StringIndex,
}

/// A rope of up to 5 byte slices that are interned as a single contiguous string.
/// Empty slices are skipped; non-empty slices are concatenated in order.
pub struct StrRope5<'a> {
    pub leaves: [&'a [u8]; 5],
}

impl StrRope5<'_> {
    /// Total byte length assuming all input is valid UTF-8 (no replacement expansion).
    #[inline]
    pub fn optimistic_len(&self) -> usize {
        self.leaves.iter().map(|s| s.len()).sum()
    }
}

/// Maximum byte length of a string that can be interned.
pub const MAX_STR_LEN: usize = 0b00111111_11111111; // 16383

// ── Pre-interned well-known string indices ────────────────────────────────────

// ── Pre-interned libdatadog well-known strings (indices 0–7) ─────────────────
pub const STRING_EMPTY: StringIndex = StringIndex(0);
pub const STRING_END_TIMESTAMP_NS: StringIndex = StringIndex(1);
pub const STRING_LOCAL_ROOT_SPAN_ID: StringIndex = StringIndex(2);
pub const STRING_TRACE_ENDPOINT: StringIndex = StringIndex(3);
pub const STRING_SPAN_ID: StringIndex = StringIndex(4);
pub const STRING_THREAD_ID: StringIndex = StringIndex(5);
pub const STRING_THREAD_NAME: StringIndex = StringIndex(6);
pub const STRING_OOM: StringIndex = StringIndex(7); // "[oom]"

// ── Pre-interned PHP profiler strings (indices 8–36) ─────────────────────────
pub const STRING_PHP_OPEN_TAG: StringIndex = StringIndex(8); // "<?php"
pub const STRING_UNKNOWN_USER_FUNCTION: StringIndex = StringIndex(9); // "[unknown user function]"
pub const STRING_UNKNOWN_INTERNAL_FUNCTION: StringIndex = StringIndex(10); // "[unknown internal function]"
pub const STRING_SUSPICIOUSLY_LONG_FN: StringIndex = StringIndex(11); // "[suspiciously long function]"
pub const STRING_SUSPICIOUSLY_LONG_FILE: StringIndex = StringIndex(12); // "[suspiciously long filename]"
pub const STRING_EVAL: StringIndex = StringIndex(13); // "[eval]"
pub const STRING_TRUNCATED: StringIndex = StringIndex(14); // "[truncated]"
                                                           // pprof label keys
pub const STRING_KEY_THREAD_ID: StringIndex = StringIndex(15); // "thread id"
pub const STRING_KEY_THREAD_NAME: StringIndex = StringIndex(16); // "thread name"
pub const STRING_KEY_LOCAL_ROOT_SPAN_ID: StringIndex = StringIndex(17); // "local root span id"
pub const STRING_KEY_SPAN_ID: StringIndex = StringIndex(18); // "span id"
pub const STRING_KEY_TRACE_ENDPOINT: StringIndex = StringIndex(19); // "trace endpoint"
pub const STRING_KEY_FIBER: StringIndex = StringIndex(20); // "fiber"
pub const STRING_KEY_EVENT: StringIndex = StringIndex(21); // "event"
pub const STRING_KEY_FILENAME: StringIndex = StringIndex(22); // "filename"
pub const STRING_KEY_MESSAGE: StringIndex = StringIndex(23); // "message"
pub const STRING_KEY_REASON: StringIndex = StringIndex(24); // "reason"
pub const STRING_KEY_GC_REASON: StringIndex = StringIndex(25); // "gc reason"
pub const STRING_KEY_GC_RUNS: StringIndex = StringIndex(26); // "gc runs"
pub const STRING_KEY_GC_COLLECTED: StringIndex = StringIndex(27); // "gc collected"
pub const STRING_KEY_EXCEPTION_TYPE: StringIndex = StringIndex(28); // "exception type"
pub const STRING_KEY_EXCEPTION_MESSAGE: StringIndex = StringIndex(29); // "exception message"
                                                                       // sample type names and units
pub const STRING_CPU_TIME: StringIndex = StringIndex(30); // "cpu-time"
pub const STRING_WALL_TIME: StringIndex = StringIndex(31); // "wall-time"
pub const STRING_TIMELINE: StringIndex = StringIndex(32); // "timeline"
pub const STRING_NANOSECONDS: StringIndex = StringIndex(33); // "nanoseconds"
pub const STRING_COUNT: StringIndex = StringIndex(34); // "count"
pub const STRING_GC: StringIndex = StringIndex(35); // "[gc]"
pub const STRING_IDLE: StringIndex = StringIndex(36); // "[idle]"
pub(crate) const STRINGS_PRE_INTERNED: usize = STRING_IDLE.0 as usize + 1;

// ── Pre-interned PHP profiler functions (indices 0–8) ────────────────────────
// These are interned by create() immediately after the strings above.
// Note: "<?php" (top-level code) is NOT pre-interned as a function because it
// is always paired with a real filename, so each file gets its own FunctionIndex.
//
// INVARIANT: FUNCTION_EMPTY must always be FunctionIndex(0).
//
// Callers store FunctionIndex values as raw `uintptr_t` in a `void*` slot
// (e.g., zend_op_array->reserved[n]). A NULL slot (value 0) is therefore
// indistinguishable from an explicitly stored FUNCTION_EMPTY, and both are
// treated identically: an empty function with name="" and file="". This allows
// callers to skip the +1 tagging trick and treat uninitialised slots correctly
// without any special-casing.
pub const FUNCTION_EMPTY: FunctionIndex = FunctionIndex(0);
pub const FUNCTION_UNKNOWN_USER: FunctionIndex = FunctionIndex(1);
pub const FUNCTION_UNKNOWN_INTERNAL: FunctionIndex = FunctionIndex(2);
pub const FUNCTION_OOM: FunctionIndex = FunctionIndex(3);
pub const FUNCTION_SUSPICIOUSLY_LONG: FunctionIndex = FunctionIndex(4);
pub const FUNCTION_TRUNCATED: FunctionIndex = FunctionIndex(5);
pub const FUNCTION_EVAL: FunctionIndex = FunctionIndex(6);
pub const FUNCTION_GC: FunctionIndex = FunctionIndex(7);
pub const FUNCTION_IDLE: FunctionIndex = FunctionIndex(8);
pub(crate) const FUNCTIONS_PRE_INTERNED: usize = FUNCTION_IDLE.0 as usize + 1;

// Corresponding string literals for indices 0–36 (used by create())
pub(crate) const STRING_EMPTY_STR: &str = "";
pub(crate) const STRING_END_TIMESTAMP_NS_STR: &str = "end_timestamp_ns";
pub(crate) const STRING_LOCAL_ROOT_SPAN_ID_STR: &str = "local_root_span_id";
pub(crate) const STRING_TRACE_ENDPOINT_STR: &str = "trace_endpoint";
pub(crate) const STRING_SPAN_ID_STR: &str = "span_id";
pub(crate) const STRING_THREAD_ID_STR: &str = "thread_id";
pub(crate) const STRING_THREAD_NAME_STR: &str = "thread_name";
pub(crate) const STRING_OOM_STR: &str = "[oom]";
pub(crate) const STRING_PHP_OPEN_TAG_STR: &str = "<?php";
pub(crate) const STRING_UNKNOWN_USER_FUNCTION_STR: &str = "[unknown user function]";
pub(crate) const STRING_UNKNOWN_INTERNAL_FUNCTION_STR: &str = "[unknown internal function]";
pub(crate) const STRING_SUSPICIOUSLY_LONG_FN_STR: &str = "[suspiciously long function]";
pub(crate) const STRING_SUSPICIOUSLY_LONG_FILE_STR: &str = "[suspiciously long filename]";
pub(crate) const STRING_EVAL_STR: &str = "[eval]";
pub(crate) const STRING_TRUNCATED_STR: &str = "[truncated]";
pub(crate) const STRING_KEY_THREAD_ID_STR: &str = "thread id";
pub(crate) const STRING_KEY_THREAD_NAME_STR: &str = "thread name";
pub(crate) const STRING_KEY_LOCAL_ROOT_SPAN_ID_STR: &str = "local root span id";
pub(crate) const STRING_KEY_SPAN_ID_STR: &str = "span id";
pub(crate) const STRING_KEY_TRACE_ENDPOINT_STR: &str = "trace endpoint";
pub(crate) const STRING_KEY_FIBER_STR: &str = "fiber";
pub(crate) const STRING_KEY_EVENT_STR: &str = "event";
pub(crate) const STRING_KEY_FILENAME_STR: &str = "filename";
pub(crate) const STRING_KEY_MESSAGE_STR: &str = "message";
pub(crate) const STRING_KEY_REASON_STR: &str = "reason";
pub(crate) const STRING_KEY_GC_REASON_STR: &str = "gc reason";
pub(crate) const STRING_KEY_GC_RUNS_STR: &str = "gc runs";
pub(crate) const STRING_KEY_GC_COLLECTED_STR: &str = "gc collected";
pub(crate) const STRING_KEY_EXCEPTION_TYPE_STR: &str = "exception type";
pub(crate) const STRING_KEY_EXCEPTION_MESSAGE_STR: &str = "exception message";
pub(crate) const STRING_CPU_TIME_STR: &str = "cpu-time";
pub(crate) const STRING_WALL_TIME_STR: &str = "wall-time";
pub(crate) const STRING_TIMELINE_STR: &str = "timeline";
pub(crate) const STRING_NANOSECONDS_STR: &str = "nanoseconds";
pub(crate) const STRING_COUNT_STR: &str = "count";
pub(crate) const STRING_GC_STR: &str = "[gc]";
pub(crate) const STRING_IDLE_STR: &str = "[idle]";

// ── Layout constants ──────────────────────────────────────────────────────────

pub(crate) const STR_HT_CAP: usize = 1 << 21; // 2_097_152 slots; StringIndex uses ≤21 bits
pub(crate) const FN_HT_CAP: usize = 1 << 20; // 1_048_576 slots; FunctionIndex uses ≤20 bits
pub(crate) const MAX_STRINGS: usize = STR_HT_CAP * 7 / 8; // 1_835_008
pub(crate) const MAX_FUNCTIONS: usize = FN_HT_CAP * 7 / 8; //   917_504
pub(crate) const SEGMENT_SIZE: usize = 512 * 1024 * 1024; // 536_870_912

// Fixed byte offsets within the segment.
// The STR_IDX array stores one AtomicU32 per string slot; FN_IDX stores one
// AtomicU64 per function slot.  Both sizes vary between production and loom,
// so these constants are derived from crate::atomic::{AtomicU32, AtomicU64}.
pub(crate) const HEADER_OFF: usize = 0;
pub(crate) const STR_DATA_OFF: usize = 256; // header always fits in 256 B
pub(crate) const STR_CTRL_OFF: usize = STR_DATA_OFF + STR_HT_CAP * 8; // StringHtSlot = u64
pub(crate) const STR_IDX_OFF: usize = STR_CTRL_OFF + STR_HT_CAP + 16; // +16 for mirror tail
pub(crate) const FN_DATA_OFF: usize =
    STR_IDX_OFF + MAX_STRINGS * core::mem::size_of::<crate::atomic::AtomicU32>();
pub(crate) const FN_CTRL_OFF: usize = FN_DATA_OFF + FN_HT_CAP * 8; // FunctionHtSlot = u64
pub(crate) const FN_IDX_OFF: usize = FN_CTRL_OFF + FN_HT_CAP + 16; // +16 for mirror tail
pub(crate) const FIXED_END: usize =
    FN_IDX_OFF + MAX_FUNCTIONS * core::mem::size_of::<crate::atomic::AtomicU64>();
/// Arena capacity: whatever remains after the fixed tables.  Shrinks to a
/// smaller value under loom (AtomicU32 = 8 B instead of 4 B), but the tests
/// only need a handful of strings so this is fine.
pub(crate) const ARENA_CAPACITY: usize = SEGMENT_SIZE - FIXED_END;

// Byte offsets within the header (HEADER_OFF = 0).
// Derived from the size of the atomic types so the layout is self-consistent
// under both production (AtomicU32 = 4 B) and loom (AtomicU32 = 8 B).
pub(crate) const STR_SPINLOCK_OFF: usize = 0;
pub(crate) const STRING_COUNT_OFF: usize =
    STR_SPINLOCK_OFF + core::mem::size_of::<crate::atomic::AtomicU32>();
pub(crate) const ARENA_USED_OFF: usize =
    STRING_COUNT_OFF + core::mem::size_of::<crate::atomic::AtomicU32>();
// FN_SPINLOCK is placed on a fresh cache line (≥ 64 B from the start of the
// header).  128 always fits: even under loom (AtomicU32 = 8 B) the three
// header atomics before FN_SPINLOCK occupy at most 24 B.
pub(crate) const FN_SPINLOCK_OFF: usize = 128;
pub(crate) const FUNCTION_COUNT_OFF: usize =
    FN_SPINLOCK_OFF + core::mem::size_of::<crate::atomic::AtomicU32>();
#[allow(dead_code)]
pub(crate) const REFCOUNT_OFF: usize =
    FUNCTION_COUNT_OFF + core::mem::size_of::<crate::atomic::AtomicU32>();

// Compile-time sanity: the fixed tables must always fit within SEGMENT_SIZE.
const _: () = assert!(
    FIXED_END <= SEGMENT_SIZE,
    "fixed tables exceed SEGMENT_SIZE"
);

// Re-export the main API type
pub use shm::ShmRegion;
