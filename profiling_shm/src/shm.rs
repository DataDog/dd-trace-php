use core::sync::atomic::{AtomicU32, AtomicU64};

use crate::function_interner::{get_function, intern_function, FunctionHtSlot};
use crate::spinlock::try_lock;
use crate::string_interner::{get_str, intern_rope, intern_str, StringHtSlot};
use crate::{
    FunctionIndex,
    InternError,
    StrRope5,
    StringIndex,
    ARENA_USED_OFF,
    FN_CTRL_OFF,
    FN_DATA_OFF,
    FN_IDX_OFF,
    FN_SPINLOCK_OFF,
    FUNCTION_COUNT_OFF,
    HEADER_OFF,
    SEGMENT_SIZE,
    STRING_COUNT_OFF,
    STRING_COUNT_STR,
    STRING_CPU_TIME_STR,
    STRING_EMPTY,
    // Well-known string literals (indices 0–7)
    STRING_EMPTY_STR,
    STRING_END_TIMESTAMP_NS_STR,
    STRING_EVAL,
    STRING_EVAL_STR,
    STRING_KEY_EVENT_STR,
    STRING_KEY_EXCEPTION_MESSAGE_STR,
    STRING_KEY_EXCEPTION_TYPE_STR,
    STRING_KEY_FIBER_STR,
    STRING_KEY_FILENAME_STR,
    STRING_KEY_GC_COLLECTED_STR,
    STRING_KEY_GC_REASON_STR,
    STRING_KEY_GC_RUNS_STR,
    STRING_KEY_LOCAL_ROOT_SPAN_ID_STR,
    STRING_KEY_MESSAGE_STR,
    STRING_KEY_REASON_STR,
    STRING_KEY_SPAN_ID_STR,
    STRING_KEY_THREAD_ID_STR,
    STRING_KEY_THREAD_NAME_STR,
    STRING_KEY_TRACE_ENDPOINT_STR,
    STRING_LOCAL_ROOT_SPAN_ID_STR,
    STRING_NANOSECONDS_STR,
    STRING_OOM,
    STRING_OOM_STR,
    // PHP profiler string literals (indices 8–34)
    STRING_PHP_OPEN_TAG_STR,
    STRING_SPAN_ID_STR,
    STRING_SUSPICIOUSLY_LONG_FILE_STR,
    STRING_SUSPICIOUSLY_LONG_FN,
    STRING_SUSPICIOUSLY_LONG_FN_STR,
    STRING_THREAD_ID_STR,
    STRING_THREAD_NAME_STR,
    STRING_TIMELINE_STR,
    STRING_TRACE_ENDPOINT_STR,
    STRING_TRUNCATED,
    STRING_TRUNCATED_STR,
    STRING_UNKNOWN_INTERNAL_FUNCTION,
    STRING_UNKNOWN_INTERNAL_FUNCTION_STR,
    // Pre-interned string indices used to pre-intern functions
    STRING_UNKNOWN_USER_FUNCTION,
    STRING_UNKNOWN_USER_FUNCTION_STR,
    STRING_WALL_TIME_STR,
    STR_CTRL_OFF,
    STR_DATA_OFF,
    STR_IDX_OFF,
    STR_SPINLOCK_OFF,
};

// FUNCTION_EMPTY must be index 0 so that a NULL void* slot (raw value 0)
// is equivalent to "not set" and decodes to the empty function without any
// +1 tagging. Enforce this at compile time.
const _: () = assert!(
    crate::FUNCTION_EMPTY.0 == 0,
    "FUNCTION_EMPTY must be FunctionIndex(0)"
);

/// Verify all layout assumptions that depend on the base-pointer's alignment.
/// Called once from `create`; zero-cost in release builds.
#[allow(unused_variables)] // ptr only used inside debug_assert_eq!
fn debug_assert_layout(ptr: *mut u8) {
    // All fixed offsets are multiples of 8, so a page-aligned (or even
    // 8-byte-aligned) base pointer satisfies every typed cast we make.
    debug_assert!(!ptr.is_null(), "segment base pointer is null");
    unsafe {
        debug_assert_eq!(
            ptr.add(HEADER_OFF + STRING_COUNT_OFF)
                .align_offset(core::mem::align_of::<AtomicU32>()),
            0,
            "string_count misaligned"
        );
        debug_assert_eq!(
            ptr.add(HEADER_OFF + ARENA_USED_OFF)
                .align_offset(core::mem::align_of::<AtomicU32>()),
            0,
            "arena_used misaligned"
        );
        debug_assert_eq!(
            ptr.add(HEADER_OFF + STR_SPINLOCK_OFF)
                .align_offset(core::mem::align_of::<AtomicU32>()),
            0,
            "str_spinlock misaligned"
        );
        debug_assert_eq!(
            ptr.add(HEADER_OFF + FN_SPINLOCK_OFF)
                .align_offset(core::mem::align_of::<AtomicU32>()),
            0,
            "fn_spinlock misaligned"
        );
        debug_assert_eq!(
            ptr.add(HEADER_OFF + FUNCTION_COUNT_OFF)
                .align_offset(core::mem::align_of::<AtomicU32>()),
            0,
            "function_count misaligned"
        );
        debug_assert_eq!(
            ptr.add(STR_DATA_OFF)
                .align_offset(core::mem::align_of::<StringHtSlot>()),
            0,
            "str_data misaligned"
        );
        debug_assert_eq!(
            ptr.add(STR_CTRL_OFF)
                .align_offset(core::mem::align_of::<u8>()),
            0,
            "str_ctrl misaligned"
        );
        debug_assert_eq!(
            ptr.add(STR_IDX_OFF)
                .align_offset(core::mem::align_of::<AtomicU32>()),
            0,
            "str_idx misaligned"
        );
        debug_assert_eq!(
            ptr.add(FN_DATA_OFF)
                .align_offset(core::mem::align_of::<FunctionHtSlot>()),
            0,
            "fn_data misaligned"
        );
        debug_assert_eq!(
            ptr.add(FN_CTRL_OFF)
                .align_offset(core::mem::align_of::<u8>()),
            0,
            "fn_ctrl misaligned"
        );
        debug_assert_eq!(
            ptr.add(FN_IDX_OFF)
                .align_offset(core::mem::align_of::<AtomicU64>()),
            0,
            "fn_idx misaligned"
        );
    }
}

/// A `MAP_SHARED | MAP_ANONYMOUS` region of `SEGMENT_SIZE` bytes.
///
/// The pointer is valid for the lifetime of the region.  `Drop` does nothing;
/// call `unmap` explicitly from the parent after all children have exited.
pub struct ShmRegion {
    ptr: *mut u8,
}

// SAFETY: the mapping is independent of any thread; multiple threads may hold
// a reference to the same region and call `intern_*` / `get_*` concurrently.
unsafe impl Send for ShmRegion {}
unsafe impl Sync for ShmRegion {}

impl ShmRegion {
    /// Create a new `MAP_SHARED | MAP_ANONYMOUS` mapping of 512 MiB and
    /// pre-intern the 8 well-known strings (indices 0–7).
    ///
    /// Call once in the parent before `fork`.
    ///
    /// # Safety
    /// The caller is responsible for calling `unmap` when all children
    /// have exited.
    pub unsafe fn create() -> Result<Self, ()> {
        let ptr = unsafe {
            libc::mmap(
                core::ptr::null_mut(),
                SEGMENT_SIZE,
                libc::PROT_READ | libc::PROT_WRITE,
                libc::MAP_SHARED | libc::MAP_ANONYMOUS,
                -1,
                0,
            )
        };
        if ptr == libc::MAP_FAILED {
            return Err(());
        }

        let region = ShmRegion {
            ptr: ptr as *mut u8,
        };
        debug_assert_layout(region.ptr);

        // Pre-intern 35 strings in index order (0–34).
        // MAP_ANONYMOUS zeroes all pages so ctrl arrays are EMPTY (0x00) and
        // all counters are 0 — no memset needed.
        let strings: [&str; 35] = [
            // indices 0–7: libdatadog well-known strings
            STRING_EMPTY_STR,
            STRING_END_TIMESTAMP_NS_STR,
            STRING_LOCAL_ROOT_SPAN_ID_STR,
            STRING_TRACE_ENDPOINT_STR,
            STRING_SPAN_ID_STR,
            STRING_THREAD_ID_STR,
            STRING_THREAD_NAME_STR,
            STRING_OOM_STR,
            // indices 8–14: PHP profiler placeholder strings
            STRING_PHP_OPEN_TAG_STR,
            STRING_UNKNOWN_USER_FUNCTION_STR,
            STRING_UNKNOWN_INTERNAL_FUNCTION_STR,
            STRING_SUSPICIOUSLY_LONG_FN_STR,
            STRING_SUSPICIOUSLY_LONG_FILE_STR,
            STRING_EVAL_STR,
            STRING_TRUNCATED_STR,
            // indices 15–29: pprof label keys
            STRING_KEY_THREAD_ID_STR,
            STRING_KEY_THREAD_NAME_STR,
            STRING_KEY_LOCAL_ROOT_SPAN_ID_STR,
            STRING_KEY_SPAN_ID_STR,
            STRING_KEY_TRACE_ENDPOINT_STR,
            STRING_KEY_FIBER_STR,
            STRING_KEY_EVENT_STR,
            STRING_KEY_FILENAME_STR,
            STRING_KEY_MESSAGE_STR,
            STRING_KEY_REASON_STR,
            STRING_KEY_GC_REASON_STR,
            STRING_KEY_GC_RUNS_STR,
            STRING_KEY_GC_COLLECTED_STR,
            STRING_KEY_EXCEPTION_TYPE_STR,
            STRING_KEY_EXCEPTION_MESSAGE_STR,
            // indices 30–34: sample type names and units
            STRING_CPU_TIME_STR,
            STRING_WALL_TIME_STR,
            STRING_TIMELINE_STR,
            STRING_NANOSECONDS_STR,
            STRING_COUNT_STR,
        ];

        for s in strings {
            // Bypass the spinlock: no other thread/process exists yet.
            if intern_str(region.ptr, s).is_err() {
                let _ = libc::munmap(ptr, SEGMENT_SIZE);
                return Err(());
            }
        }

        // Pre-intern 7 functions in index order (0–6).
        // Index 0 is the empty/default function (both name and file are STRING_EMPTY).
        // A NULL reserved[slot] (raw value 0) reads back as FunctionIndex(0), so this
        // doubles as the "not yet interned" sentinel — no +1 encoding needed.
        let functions: [(StringIndex, StringIndex); 7] = [
            (STRING_EMPTY, STRING_EMPTY),                     // FUNCTION_EMPTY
            (STRING_UNKNOWN_USER_FUNCTION, STRING_EMPTY),     // FUNCTION_UNKNOWN_USER
            (STRING_UNKNOWN_INTERNAL_FUNCTION, STRING_EMPTY), // FUNCTION_UNKNOWN_INTERNAL
            (STRING_OOM, STRING_EMPTY),                       // FUNCTION_OOM
            (STRING_SUSPICIOUSLY_LONG_FN, STRING_EMPTY),      // FUNCTION_SUSPICIOUSLY_LONG
            (STRING_TRUNCATED, STRING_EMPTY),                 // FUNCTION_TRUNCATED
            (STRING_EVAL, STRING_EMPTY),                      // FUNCTION_EVAL
        ];

        for (name, file) in functions {
            if intern_function(region.ptr, name, file).is_err() {
                let _ = libc::munmap(ptr, SEGMENT_SIZE);
                return Err(());
            }
        }

        Ok(region)
    }

    /// Intern a string.
    ///
    /// Acquires `str_spinlock`; returns `WouldBlock` immediately if contended.
    pub fn intern_str(&self, s: &str) -> Result<StringIndex, InternError> {
        let lock_ptr =
            unsafe { &*(self.ptr.add(HEADER_OFF + STR_SPINLOCK_OFF) as *const AtomicU32) };
        let _guard = try_lock(lock_ptr).ok_or(InternError::WouldBlock)?;
        unsafe { intern_str(self.ptr, s) }
    }

    /// Intern a rope of up to 5 byte slices as a single string.
    ///
    /// Acquires `str_spinlock`; returns `WouldBlock` immediately if contended.
    /// Non-UTF-8 bytes are replaced with U+FFFD.
    pub fn intern_rope(&self, rope: &StrRope5) -> Result<StringIndex, InternError> {
        let lock_ptr =
            unsafe { &*(self.ptr.add(HEADER_OFF + STR_SPINLOCK_OFF) as *const AtomicU32) };
        let _guard = try_lock(lock_ptr).ok_or(InternError::WouldBlock)?;
        unsafe { intern_rope(self.ptr, rope) }
    }

    /// Intern a function.
    ///
    /// Acquires `fn_spinlock` with a brief spin (a handful of attempts with
    /// CPU spin-loop hints) before giving up.  Returns `WouldBlock` if the
    /// lock is still unavailable after those attempts.
    /// `name` and `file` must be valid `StringIndex` values already interned
    /// in this region.
    pub fn intern_function(
        &self,
        name: StringIndex,
        file: StringIndex,
    ) -> Result<FunctionIndex, InternError> {
        let lock_ptr =
            unsafe { &*(self.ptr.add(HEADER_OFF + FN_SPINLOCK_OFF) as *const AtomicU32) };
        let _guard = try_lock(lock_ptr).ok_or(InternError::WouldBlock)?;
        unsafe { intern_function(self.ptr, name, file) }
    }

    /// Look up a string by index.  Lock-free.
    pub fn get_str(&self, idx: StringIndex) -> Option<&str> {
        unsafe { get_str(self.ptr, idx) }
    }

    /// Look up a function by index.  Lock-free.
    pub fn get_function(&self, idx: FunctionIndex) -> Option<(StringIndex, StringIndex)> {
        unsafe { get_function(self.ptr, idx) }
    }

    /// Return the raw segment pointer (for passing to fork children).
    pub fn ptr(&self) -> *mut u8 {
        self.ptr
    }

    /// Unmap the segment.  Only call from the parent after all children
    /// have exited.
    ///
    /// # Safety
    /// No other thread or process may use the region after this call.
    pub unsafe fn unmap(self) {
        unsafe {
            libc::munmap(self.ptr as *mut libc::c_void, SEGMENT_SIZE);
        }
    }
}

#[cfg(test)]
mod tests {
    extern crate std;
    use super::*;
    use crate::{
        FUNCTION_EMPTY, FUNCTION_EVAL, FUNCTION_OOM, FUNCTION_SUSPICIOUSLY_LONG,
        FUNCTION_TRUNCATED, FUNCTION_UNKNOWN_INTERNAL, FUNCTION_UNKNOWN_USER, STRING_COUNT,
        STRING_COUNT_STR, STRING_CPU_TIME, STRING_CPU_TIME_STR, STRING_EMPTY, STRING_EMPTY_STR,
        STRING_END_TIMESTAMP_NS, STRING_END_TIMESTAMP_NS_STR, STRING_EVAL, STRING_EVAL_STR,
        STRING_KEY_EVENT, STRING_KEY_EVENT_STR, STRING_KEY_EXCEPTION_MESSAGE,
        STRING_KEY_EXCEPTION_MESSAGE_STR, STRING_KEY_EXCEPTION_TYPE, STRING_KEY_EXCEPTION_TYPE_STR,
        STRING_KEY_FIBER, STRING_KEY_FIBER_STR, STRING_KEY_FILENAME, STRING_KEY_FILENAME_STR,
        STRING_KEY_GC_COLLECTED, STRING_KEY_GC_COLLECTED_STR, STRING_KEY_GC_REASON,
        STRING_KEY_GC_REASON_STR, STRING_KEY_GC_RUNS, STRING_KEY_GC_RUNS_STR,
        STRING_KEY_LOCAL_ROOT_SPAN_ID, STRING_KEY_LOCAL_ROOT_SPAN_ID_STR, STRING_KEY_MESSAGE,
        STRING_KEY_MESSAGE_STR, STRING_KEY_REASON, STRING_KEY_REASON_STR, STRING_KEY_SPAN_ID,
        STRING_KEY_SPAN_ID_STR, STRING_KEY_THREAD_ID, STRING_KEY_THREAD_ID_STR,
        STRING_KEY_THREAD_NAME, STRING_KEY_THREAD_NAME_STR, STRING_KEY_TRACE_ENDPOINT,
        STRING_KEY_TRACE_ENDPOINT_STR, STRING_LOCAL_ROOT_SPAN_ID, STRING_LOCAL_ROOT_SPAN_ID_STR,
        STRING_NANOSECONDS, STRING_NANOSECONDS_STR, STRING_OOM, STRING_OOM_STR,
        STRING_PHP_OPEN_TAG, STRING_PHP_OPEN_TAG_STR, STRING_SPAN_ID, STRING_SPAN_ID_STR,
        STRING_SUSPICIOUSLY_LONG_FILE, STRING_SUSPICIOUSLY_LONG_FILE_STR,
        STRING_SUSPICIOUSLY_LONG_FN, STRING_SUSPICIOUSLY_LONG_FN_STR, STRING_THREAD_ID,
        STRING_THREAD_ID_STR, STRING_THREAD_NAME, STRING_THREAD_NAME_STR, STRING_TIMELINE,
        STRING_TIMELINE_STR, STRING_TRACE_ENDPOINT, STRING_TRACE_ENDPOINT_STR, STRING_TRUNCATED,
        STRING_TRUNCATED_STR, STRING_UNKNOWN_INTERNAL_FUNCTION,
        STRING_UNKNOWN_INTERNAL_FUNCTION_STR, STRING_UNKNOWN_USER_FUNCTION,
        STRING_UNKNOWN_USER_FUNCTION_STR, STRING_WALL_TIME, STRING_WALL_TIME_STR,
    };
    use core::sync::atomic::Ordering;

    fn create() -> ShmRegion {
        unsafe { ShmRegion::create().expect("mmap failed") }
    }

    #[test]
    fn pre_interned_strings_have_correct_indices() {
        // create() pre-interns indices 0–34; verify all of them.
        let region = create();

        // indices 0–7: libdatadog well-known strings
        assert_eq!(region.get_str(STRING_EMPTY).unwrap(), STRING_EMPTY_STR);
        assert_eq!(
            region.get_str(STRING_END_TIMESTAMP_NS).unwrap(),
            STRING_END_TIMESTAMP_NS_STR
        );
        assert_eq!(
            region.get_str(STRING_LOCAL_ROOT_SPAN_ID).unwrap(),
            STRING_LOCAL_ROOT_SPAN_ID_STR
        );
        assert_eq!(
            region.get_str(STRING_TRACE_ENDPOINT).unwrap(),
            STRING_TRACE_ENDPOINT_STR
        );
        assert_eq!(region.get_str(STRING_SPAN_ID).unwrap(), STRING_SPAN_ID_STR);
        assert_eq!(
            region.get_str(STRING_THREAD_ID).unwrap(),
            STRING_THREAD_ID_STR
        );
        assert_eq!(
            region.get_str(STRING_THREAD_NAME).unwrap(),
            STRING_THREAD_NAME_STR
        );
        assert_eq!(region.get_str(STRING_OOM).unwrap(), STRING_OOM_STR);

        // indices 8–14: PHP profiler placeholders
        assert_eq!(
            region.get_str(STRING_PHP_OPEN_TAG).unwrap(),
            STRING_PHP_OPEN_TAG_STR
        );
        assert_eq!(
            region.get_str(STRING_UNKNOWN_USER_FUNCTION).unwrap(),
            STRING_UNKNOWN_USER_FUNCTION_STR
        );
        assert_eq!(
            region.get_str(STRING_UNKNOWN_INTERNAL_FUNCTION).unwrap(),
            STRING_UNKNOWN_INTERNAL_FUNCTION_STR
        );
        assert_eq!(
            region.get_str(STRING_SUSPICIOUSLY_LONG_FN).unwrap(),
            STRING_SUSPICIOUSLY_LONG_FN_STR
        );
        assert_eq!(
            region.get_str(STRING_SUSPICIOUSLY_LONG_FILE).unwrap(),
            STRING_SUSPICIOUSLY_LONG_FILE_STR
        );
        assert_eq!(region.get_str(STRING_EVAL).unwrap(), STRING_EVAL_STR);
        assert_eq!(
            region.get_str(STRING_TRUNCATED).unwrap(),
            STRING_TRUNCATED_STR
        );

        // indices 15–29: pprof label keys
        assert_eq!(
            region.get_str(STRING_KEY_THREAD_ID).unwrap(),
            STRING_KEY_THREAD_ID_STR
        );
        assert_eq!(
            region.get_str(STRING_KEY_THREAD_NAME).unwrap(),
            STRING_KEY_THREAD_NAME_STR
        );
        assert_eq!(
            region.get_str(STRING_KEY_LOCAL_ROOT_SPAN_ID).unwrap(),
            STRING_KEY_LOCAL_ROOT_SPAN_ID_STR
        );
        assert_eq!(
            region.get_str(STRING_KEY_SPAN_ID).unwrap(),
            STRING_KEY_SPAN_ID_STR
        );
        assert_eq!(
            region.get_str(STRING_KEY_TRACE_ENDPOINT).unwrap(),
            STRING_KEY_TRACE_ENDPOINT_STR
        );
        assert_eq!(
            region.get_str(STRING_KEY_FIBER).unwrap(),
            STRING_KEY_FIBER_STR
        );
        assert_eq!(
            region.get_str(STRING_KEY_EVENT).unwrap(),
            STRING_KEY_EVENT_STR
        );
        assert_eq!(
            region.get_str(STRING_KEY_FILENAME).unwrap(),
            STRING_KEY_FILENAME_STR
        );
        assert_eq!(
            region.get_str(STRING_KEY_MESSAGE).unwrap(),
            STRING_KEY_MESSAGE_STR
        );
        assert_eq!(
            region.get_str(STRING_KEY_REASON).unwrap(),
            STRING_KEY_REASON_STR
        );
        assert_eq!(
            region.get_str(STRING_KEY_GC_REASON).unwrap(),
            STRING_KEY_GC_REASON_STR
        );
        assert_eq!(
            region.get_str(STRING_KEY_GC_RUNS).unwrap(),
            STRING_KEY_GC_RUNS_STR
        );
        assert_eq!(
            region.get_str(STRING_KEY_GC_COLLECTED).unwrap(),
            STRING_KEY_GC_COLLECTED_STR
        );
        assert_eq!(
            region.get_str(STRING_KEY_EXCEPTION_TYPE).unwrap(),
            STRING_KEY_EXCEPTION_TYPE_STR
        );
        assert_eq!(
            region.get_str(STRING_KEY_EXCEPTION_MESSAGE).unwrap(),
            STRING_KEY_EXCEPTION_MESSAGE_STR
        );

        // indices 30–34: sample type names and units
        assert_eq!(
            region.get_str(STRING_CPU_TIME).unwrap(),
            STRING_CPU_TIME_STR
        );
        assert_eq!(
            region.get_str(STRING_WALL_TIME).unwrap(),
            STRING_WALL_TIME_STR
        );
        assert_eq!(
            region.get_str(STRING_TIMELINE).unwrap(),
            STRING_TIMELINE_STR
        );
        assert_eq!(
            region.get_str(STRING_NANOSECONDS).unwrap(),
            STRING_NANOSECONDS_STR
        );
        assert_eq!(region.get_str(STRING_COUNT).unwrap(), STRING_COUNT_STR);
    }

    #[test]
    fn pre_interned_functions_have_correct_indices() {
        let region = create();

        let (name, file) = region.get_function(FUNCTION_EMPTY).unwrap();
        assert_eq!(region.get_str(name).unwrap(), STRING_EMPTY_STR);
        assert_eq!(region.get_str(file).unwrap(), STRING_EMPTY_STR);

        let (name, file) = region.get_function(FUNCTION_UNKNOWN_USER).unwrap();
        assert_eq!(
            region.get_str(name).unwrap(),
            STRING_UNKNOWN_USER_FUNCTION_STR
        );
        assert_eq!(region.get_str(file).unwrap(), STRING_EMPTY_STR);

        let (name, file) = region.get_function(FUNCTION_UNKNOWN_INTERNAL).unwrap();
        assert_eq!(
            region.get_str(name).unwrap(),
            STRING_UNKNOWN_INTERNAL_FUNCTION_STR
        );
        assert_eq!(region.get_str(file).unwrap(), STRING_EMPTY_STR);

        let (name, file) = region.get_function(FUNCTION_OOM).unwrap();
        assert_eq!(region.get_str(name).unwrap(), STRING_OOM_STR);
        assert_eq!(region.get_str(file).unwrap(), STRING_EMPTY_STR);

        let (name, file) = region.get_function(FUNCTION_SUSPICIOUSLY_LONG).unwrap();
        assert_eq!(
            region.get_str(name).unwrap(),
            STRING_SUSPICIOUSLY_LONG_FN_STR
        );
        assert_eq!(region.get_str(file).unwrap(), STRING_EMPTY_STR);

        let (name, file) = region.get_function(FUNCTION_TRUNCATED).unwrap();
        assert_eq!(region.get_str(name).unwrap(), STRING_TRUNCATED_STR);
        assert_eq!(region.get_str(file).unwrap(), STRING_EMPTY_STR);

        let (name, file) = region.get_function(FUNCTION_EVAL).unwrap();
        assert_eq!(region.get_str(name).unwrap(), STRING_EVAL_STR);
        assert_eq!(region.get_str(file).unwrap(), STRING_EMPTY_STR);
    }

    #[test]
    fn intern_dedup() {
        let region = create();
        let idx1 = unsafe { intern_str(region.ptr, "hello").unwrap() };
        let idx2 = unsafe { intern_str(region.ptr, "hello").unwrap() };
        assert_eq!(idx1.0, idx2.0);
    }

    #[test]
    fn intern_different_strings_get_different_indices() {
        let region = create();
        let a = unsafe { intern_str(region.ptr, "foo").unwrap() };
        let b = unsafe { intern_str(region.ptr, "bar").unwrap() };
        assert_ne!(a.0, b.0);
    }

    #[test]
    fn str_too_long() {
        let region = create();
        let long = std::string::String::from_utf8(std::vec![b'x'; crate::MAX_STR_LEN + 1]).unwrap();
        let result = unsafe { intern_str(region.ptr, &long) };
        assert!(matches!(result, Err(InternError::StrTooLong)));
    }

    #[test]
    fn would_block_when_lock_held() {
        let region = create();
        let lock_ptr =
            unsafe { &*(region.ptr.add(HEADER_OFF + STR_SPINLOCK_OFF) as *const AtomicU32) };
        lock_ptr.store(1, Ordering::SeqCst); // simulate held lock
        let result = region.intern_str("test");
        assert!(matches!(result, Err(InternError::WouldBlock)));
        lock_ptr.store(0, Ordering::SeqCst);
    }

    #[test]
    fn function_dedup() {
        let region = create();
        let name = unsafe { intern_str(region.ptr, "main").unwrap() };
        let file = unsafe { intern_str(region.ptr, "app.php").unwrap() };
        let f1 = unsafe { intern_function(region.ptr, name, file).unwrap() };
        let f2 = unsafe { intern_function(region.ptr, name, file).unwrap() };
        assert_eq!(f1.0, f2.0);
    }

    #[test]
    fn get_str_out_of_range_returns_none() {
        let region = create();
        // create() pre-interns 8 strings (indices 0–7); u32::MAX is always out of range.
        assert!(region.get_str(StringIndex(u32::MAX)).is_none());
    }

    #[test]
    fn get_function_out_of_range_returns_none() {
        let region = create();
        assert!(region.get_function(FunctionIndex(u32::MAX)).is_none());
    }

    #[test]
    fn lock_free_get_str_after_intern() {
        let region = create();
        let idx = unsafe { intern_str(region.ptr, "profiling").unwrap() };
        let s = region.get_str(idx).unwrap();
        assert_eq!(s, "profiling");
    }

    #[test]
    fn intern_rope_all_ascii() {
        let region = create();
        // "Core|MyClass::myMethod"
        let rope = StrRope5 {
            leaves: [b"Core", b"|", b"MyClass", b"::", b"myMethod"],
        };
        let idx1 = region.intern_rope(&rope).unwrap();
        let idx2 = region.intern_rope(&rope).unwrap();
        assert_eq!(idx1, idx2, "rope dedup");
        assert_eq!(region.get_str(idx1).unwrap(), "Core|MyClass::myMethod");
    }

    #[test]
    fn intern_rope_method_only() {
        let region = create();
        let rope = StrRope5 {
            leaves: [b"standalone", b"", b"", b"", b""],
        };
        let idx = region.intern_rope(&rope).unwrap();
        assert_eq!(region.get_str(idx).unwrap(), "standalone");
    }

    #[test]
    fn intern_rope_matches_intern_str() {
        let region = create();
        // "Foo::bar"
        let rope = StrRope5 {
            leaves: [b"Foo", b"::", b"bar", b"", b""],
        };
        let rope_idx = region.intern_rope(&rope).unwrap();
        let str_idx = region.intern_str("Foo::bar").unwrap();
        assert_eq!(
            rope_idx, str_idx,
            "rope and str for same content must give same index"
        );
    }

    #[test]
    fn intern_rope_non_utf8_lossy() {
        let region = create();
        // 0xFF is invalid UTF-8; should be replaced with U+FFFD (3 bytes: EF BF BD).
        let rope = StrRope5 {
            leaves: [b"bad\xFFbytes", b"", b"", b"", b""],
        };
        let idx = region.intern_rope(&rope).unwrap();
        let stored = region.get_str(idx).unwrap();
        assert_eq!(stored, "bad\u{FFFD}bytes");
        // Second call returns the same index.
        let idx2 = region.intern_rope(&rope).unwrap();
        assert_eq!(idx, idx2);
    }

    #[test]
    fn intern_rope_too_long() {
        let region = create();
        let long = std::vec![b'x'; crate::MAX_STR_LEN + 1];
        let rope = StrRope5 {
            leaves: [&long, b"", b"", b"", b""],
        };
        assert!(matches!(
            region.intern_rope(&rope),
            Err(InternError::StrTooLong)
        ));
    }

    #[test]
    fn intern_rope_utf8_expansion_too_long() {
        let region = create();
        // Each 0xFF byte expands to 3 bytes (U+FFFD) after lossy replacement.
        // MAX_STR_LEN / 3 + 1 bytes of 0xFF fits within MAX_STR_LEN optimistically
        // but expands to (MAX_STR_LEN / 3 + 1) * 3 > MAX_STR_LEN after encoding.
        let input = std::vec![0xFF_u8; crate::MAX_STR_LEN / 3 + 1];
        let rope = StrRope5 {
            leaves: [&input, b"", b"", b"", b""],
        };
        assert!(matches!(
            region.intern_rope(&rope),
            Err(InternError::StrTooLong)
        ));
    }
}

/// Property-based tests using bolero.
///
/// The region is created once per test function (512 MiB zeroed, u64-aligned)
/// and shared across all bolero iterations, so the table fills incrementally.
/// This exercises both the fast (empty-table) and slow (loaded-table) paths
/// and lets Miri check every unsafe pointer operation across many inputs.
///
/// Run under Miri:
///   cargo miri test -p libdatadog-php-profiling-shm -- prop_
#[cfg(test)]
mod prop_tests {
    extern crate std;
    use super::*;
    use crate::{InternError, MAX_STR_LEN};

    /// Interning the same valid UTF-8 string twice must return the same index,
    /// and `get_str` must return the original bytes.
    #[test]
    fn prop_str_dedup_and_retrieval() {
        let region = unsafe { ShmRegion::create().expect("mmap failed") };

        bolero::check!().for_each(|input: &[u8]| {
            let Ok(s) = core::str::from_utf8(input) else {
                return;
            };
            if s.len() > MAX_STR_LEN {
                return;
            }

            let r1 = unsafe { intern_str(region.ptr, s) };
            let r2 = unsafe { intern_str(region.ptr, s) };
            match (r1, r2) {
                (Ok(i1), Ok(i2)) => {
                    assert_eq!(i1.0, i2.0, "dedup: same string got two indices");
                    assert_eq!(
                        unsafe { get_str(region.ptr as *const u8, i1) },
                        Some(s),
                        "retrieval returned wrong bytes"
                    );
                }
                (Err(InternError::OutOfMemory), _) | (_, Err(InternError::OutOfMemory)) => {
                    // Acceptable once the arena/table is full
                }
                (Err(e), _) | (_, Err(e)) => {
                    panic!("unexpected error {:?}", e);
                }
            }
        });
    }

    /// Two distinct valid UTF-8 strings must get distinct indices (when both fit).
    #[test]
    fn prop_distinct_strings_get_distinct_indices() {
        let region = unsafe { ShmRegion::create().expect("mmap failed") };

        // Input layout: [1-byte split point][bytes_a][bytes_b]
        bolero::check!().for_each(|input: &[u8]| {
            let Some((&split, rest)) = input.split_first() else {
                return;
            };
            let split = split as usize % (rest.len() + 1);
            let (a, b) = rest.split_at(split);
            let (Ok(sa), Ok(sb)) = (core::str::from_utf8(a), core::str::from_utf8(b)) else {
                return;
            };
            if sa == sb || sa.len() > MAX_STR_LEN || sb.len() > MAX_STR_LEN {
                return;
            }
            let (Ok(ia), Ok(ib)) =
                (unsafe { (intern_str(region.ptr, sa), intern_str(region.ptr, sb)) })
            else {
                return; // OOM
            };
            assert_ne!(ia.0, ib.0, "distinct strings got the same index");
        });
    }

    /// Interning a (name, file) function pair twice must return the same
    /// `FunctionIndex`, and `get_function` must return the original indices.
    #[test]
    fn prop_function_dedup_and_retrieval() {
        let region = unsafe { ShmRegion::create().expect("mmap failed") };

        // Input layout: [1-byte split][name bytes][file bytes]
        bolero::check!().for_each(|input: &[u8]| {
            let Some((&split, rest)) = input.split_first() else {
                return;
            };
            let split = split as usize % (rest.len() + 1);
            let (name_b, file_b) = rest.split_at(split);

            let (Ok(name_s), Ok(file_s)) =
                (core::str::from_utf8(name_b), core::str::from_utf8(file_b))
            else {
                return;
            };
            if name_s.len() > MAX_STR_LEN || file_s.len() > MAX_STR_LEN {
                return;
            }

            let (Ok(name), Ok(file)) = (unsafe {
                (
                    intern_str(region.ptr, name_s),
                    intern_str(region.ptr, file_s),
                )
            }) else {
                return; // OOM on strings
            };

            let f1 = unsafe { intern_function(region.ptr, name, file) };
            let f2 = unsafe { intern_function(region.ptr, name, file) };
            match (f1, f2) {
                (Ok(fi1), Ok(fi2)) => {
                    assert_eq!(fi1.0, fi2.0, "function dedup failed");
                    let (rn, rf) = unsafe { get_function(region.ptr as *const u8, fi1) }
                        .expect("get_function returned None for valid index");
                    assert_eq!(rn.0, name.0, "retrieved name index wrong");
                    assert_eq!(rf.0, file.0, "retrieved file index wrong");
                }
                (Err(InternError::OutOfMemory), _) | (_, Err(InternError::OutOfMemory)) => {}
                (Err(e), _) | (_, Err(e)) => panic!("unexpected error {:?}", e),
            }
        });
    }
}
