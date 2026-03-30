use crate::bindings::{zend_execute_data, zend_function, zend_op, zend_op_array};
use crate::profiling::Backtrace;
use crate::vec_ext::VecExt;
use profiling_shm::{
    FunctionIndex, InternError, ShmRegion, StrRope5, FUNCTION_OOM, FUNCTION_SUSPICIOUSLY_LONG,
    FUNCTION_TRUNCATED, FUNCTION_UNKNOWN_INTERNAL, FUNCTION_UNKNOWN_USER, MAX_STR_LEN,
    STRING_EMPTY, STRING_OOM, STRING_PHP_OPEN_TAG, STRING_SUSPICIOUSLY_LONG_FILE,
    STRING_SUSPICIOUSLY_LONG_FN,
};

#[cfg(php_frameless)]
use crate::bindings::zend_flf_functions;

#[cfg(php_frameless)]
use crate::bindings::{
    ZEND_FRAMELESS_ICALL_0, ZEND_FRAMELESS_ICALL_1, ZEND_FRAMELESS_ICALL_2, ZEND_FRAMELESS_ICALL_3,
};

use crate::bindings as zend;
use crate::module_globals;
use core::ffi::c_void;
use log::trace;
use std::sync::atomic::{AtomicU64, Ordering};

static FILE_CACHE_SKIP_LOG_COUNT: AtomicU64 = AtomicU64::new(0);

#[derive(Debug, Clone, Copy)]
pub struct ZendFrame {
    pub function: FunctionIndex,
    pub line: u32, // 0 for internal functions or when line info unavailable
}

#[derive(thiserror::Error, Debug)]
pub enum CollectStackSampleError {
    #[error("failed to borrow request locals: already destroyed")]
    AccessError(#[from] std::thread::AccessError),
    #[error("failed to borrow request locals: non-mutable borrow while mutably borrowed")]
    BorrowError(#[from] std::cell::BorrowError),
    #[error("failed to borrow request locals: mutable borrow while mutably borrowed")]
    BorrowMutError(#[from] std::cell::BorrowMutError),
    #[error(transparent)]
    TryReserveError(#[from] std::collections::TryReserveError),
}

#[inline]
fn function_index_slot(func: &zend_function) -> Option<*const AtomicU64> {
    if unsafe { func.common.fn_flags } & zend::ZEND_ACC_CALL_VIA_TRAMPOLINE as u32 != 0 {
        return None;
    }

    let slot = unsafe { zend::ddog_php_prof_op_array_reserved_slot() as usize };
    let is_internal = func.is_internal();
    let func = func as *const zend_function;
    let reserved = unsafe {
        if is_internal {
            core::ptr::addr_of!((*func).internal_function.reserved).cast::<*mut c_void>()
        } else {
            core::ptr::addr_of!((*func).op_array.reserved).cast::<*mut c_void>()
        }
    };
    Some(unsafe { reserved.add(slot) }.cast::<AtomicU64>())
}

#[inline]
fn load_function_index(func: &zend_function) -> FunctionIndex {
    let Some(slot) = function_index_slot(func) else {
        return FunctionIndex(0);
    };

    FunctionIndex(unsafe { &*slot }.load(Ordering::Relaxed) as u32)
}

#[inline]
fn store_function_index_if_zero(func: &zend_function, idx: FunctionIndex) {
    if idx.0 == 0 {
        return;
    }

    let Some(slot) = function_index_slot(func) else {
        return;
    };

    let slot = unsafe { &*slot };
    let _ = slot.compare_exchange(0, idx.0 as u64, Ordering::Relaxed, Ordering::Relaxed);
}

#[inline]
fn debug_function_name(func: &zend_function) -> String {
    String::from_utf8_lossy(func.name().unwrap_or(b"<toplevel>")).into_owned()
}

#[inline]
fn empty_function_fallback(func: &zend_function) -> FunctionIndex {
    if func.is_internal() {
        FunctionIndex(0)
    } else {
        FUNCTION_UNKNOWN_USER
    }
}

#[inline]
fn should_store_in_reserved_slot(func: &zend_function) -> bool {
    if func.is_internal() {
        return true;
    }

    unsafe {
        if module_globals::request_opcache_policy_initialized() {
            !module_globals::request_opcache_file_cache_enabled()
        } else {
            !zend::ddog_php_prof_opcache_file_cache_enabled()
        }
    }
}

#[inline]
fn can_write_runtime_function_index(func: &zend_function) -> bool {
    if unsafe { func.common.fn_flags } & zend::ZEND_ACC_CALL_VIA_TRAMPOLINE as u32 != 0 {
        return false;
    }

    if func.is_internal() {
        return true;
    }

    let request_write_allowed =
        unsafe { module_globals::runtime_user_reserved_slot_write_allowed() };
    if !request_write_allowed {
        return false;
    }

    if zend::PHP_VERSION_ID < 80100 {
        return false;
    }

    if !unsafe { module_globals::request_opcache_enabled() } {
        return true;
    }
    (unsafe { func.common.fn_flags } & zend::ZEND_ACC_IMMUTABLE as u32) == 0
}

/// Intern a zend_function into the SHM and return its FunctionIndex.
///
/// Function name format: `{module}|{class}::{method}` for internal methods,
/// `{class}::{method}` for user methods, `{module}|{fn}` for internal functions,
/// or just `{fn}` for user functions.
fn intern_function_index(shm: &ShmRegion, func: &zend_function) -> FunctionIndex {
    use crate::bindings::zai_str_from_zstr;

    // Compute the interned name StringIndex.
    let method_name = func.name().unwrap_or(b"");
    let name_idx = if method_name.is_empty() {
        STRING_PHP_OPEN_TAG
    } else {
        let module = func.module_name().unwrap_or(b"");
        let class = func.scope_name().unwrap_or(b"");
        let rope = StrRope5 {
            leaves: [
                module,
                if module.is_empty() { b"" } else { b"|" },
                class,
                if class.is_empty() { b"" } else { b"::" },
                method_name,
            ],
        };
        match shm.intern_rope(&rope) {
            Ok(idx) => idx,
            Err(InternError::StrTooLong) => STRING_SUSPICIOUSLY_LONG_FN,
            Err(InternError::OutOfMemory) => STRING_OOM,
            Err(InternError::WouldBlock) => STRING_EMPTY,
        }
    };

    // Compute the interned file StringIndex (empty for internal functions).
    let file_idx = if func.is_internal() {
        STRING_EMPTY
    } else {
        let bytes = unsafe {
            zai_str_from_zstr(func.op_array.filename.as_mut())
                .as_bytes()
                .to_vec()
        };
        if bytes.is_empty() {
            STRING_EMPTY
        } else {
            // from_utf8_lossy returns Borrowed (no alloc) for valid UTF-8 — the common case.
            let s = String::from_utf8_lossy(&bytes);
            match shm.intern_str(&s) {
                Ok(idx) => idx,
                Err(InternError::StrTooLong) => STRING_SUSPICIOUSLY_LONG_FILE,
                Err(_) => STRING_EMPTY,
            }
        }
    };

    // Intern the (name, file) function pair.
    let fn_idx = match shm.intern_function(name_idx, file_idx) {
        Ok(idx) => idx,
        Err(InternError::StrTooLong) => return FUNCTION_SUSPICIOUSLY_LONG,
        Err(InternError::OutOfMemory) => return FUNCTION_OOM,
        Err(InternError::WouldBlock) => {
            return if func.is_internal() {
                FUNCTION_UNKNOWN_INTERNAL
            } else {
                FUNCTION_UNKNOWN_USER
            }
        }
    };

    fn_idx
}

/// Intern a zend_function into the SHM and store the FunctionIndex in
/// func->common.reserved[slot] when policy allows it.
pub fn intern_function(shm: &ShmRegion, func: &zend_function) -> FunctionIndex {
    let should_store = should_store_in_reserved_slot(func);
    if !func.is_internal() && !should_store {
        let count = FILE_CACHE_SKIP_LOG_COUNT.fetch_add(1, Ordering::Relaxed) + 1;
        if count <= 20 {
            trace!(
                "Skipping FunctionIndex interning/store for user function {} because opcache file cache is enabled.",
                debug_function_name(func)
            );
        }
        return FUNCTION_UNKNOWN_USER;
    }

    let fn_idx = intern_function_index(shm, func);
    if should_store {
        store_function_index_if_zero(func, fn_idx);
    }
    fn_idx
}

/// Helper when a caller already has a `zend_op_array`.
pub fn intern_op_array(shm: &ShmRegion, op_array: &zend_op_array) {
    // zend_op_array and zend_function share a common prefix; cast is safe.
    let func = unsafe { &*(op_array as *const zend_op_array as *const zend_function) };
    intern_function(shm, func);
}

/// Gets an opline reference after doing bounds checking.
#[inline]
fn safely_get_opline(execute_data: &zend_execute_data) -> Option<&zend_op> {
    let func = unsafe { execute_data.func.as_ref()? };
    let op_array = func.op_array()?;
    if opline_in_bounds(op_array, execute_data.opline) {
        unsafe { Some(&*execute_data.opline) }
    } else {
        None
    }
}

#[inline]
fn opline_in_bounds(op_array: &zend_op_array, opline: *const zend_op) -> bool {
    let opcodes_start = op_array.opcodes;
    if opcodes_start.is_null() || opline.is_null() {
        return false;
    }
    let begin = opcodes_start as usize;
    let end = begin + (op_array.last as usize) * core::mem::size_of::<zend_op>();
    (begin..end).contains(&(opline as usize))
}

/// Read the FunctionIndex from func->reserved[slot].
/// A NULL slot (0) falls back to FUNCTION_EMPTY for internal functions and
/// FUNCTION_UNKNOWN_USER for user functions when symbolization is unavailable.
#[inline]
fn read_or_fallback(func: &zend_function) -> FunctionIndex {
    let idx = load_function_index(func);
    if idx.0 != 0 {
        return idx;
    }

    let can_write = can_write_runtime_function_index(func);
    if !can_write {
        return empty_function_fallback(func);
    }

    let Some(shm) = crate::SHM.get() else {
        return empty_function_fallback(func);
    };

    let idx = intern_function_index(shm, func);
    if can_write {
        store_function_index_if_zero(func, idx);
    }
    idx
}

#[no_mangle]
pub unsafe extern "C" fn ddog_php_prof_can_write_runtime_function_index(
    func: *const zend_function,
) -> bool {
    func.as_ref().is_some_and(can_write_runtime_function_index)
}

unsafe fn collect_call_frame(execute_data: &zend_execute_data) -> Option<ZendFrame> {
    let func = unsafe { execute_data.func.as_ref()? };

    // Handle frameless icalls — look up the real internal function.
    #[cfg(php_frameless)]
    if !func.is_internal() {
        if let Some(opline) = safely_get_opline(execute_data) {
            match opline.opcode as u32 {
                ZEND_FRAMELESS_ICALL_0
                | ZEND_FRAMELESS_ICALL_1
                | ZEND_FRAMELESS_ICALL_2
                | ZEND_FRAMELESS_ICALL_3 => {
                    let flf_func =
                        unsafe { &**zend_flf_functions.offset(opline.extended_value as isize) };
                    let function = read_or_fallback(flf_func);
                    return Some(ZendFrame { function, line: 0 });
                }
                _ => {}
            }
        }
    }

    let function = read_or_fallback(func);
    let line = if func.is_internal() {
        0
    } else {
        safely_get_opline(execute_data).map_or(0, |op| op.lineno)
    };

    Some(ZendFrame { function, line })
}

/// # Safety
/// Must be called in Zend Extension activate.
#[inline]
pub unsafe fn activate() {}

#[inline]
pub fn rshutdown() {}

#[inline(never)]
pub fn collect_stack_sample(
    top_execute_data: *mut zend_execute_data,
) -> Result<Backtrace, CollectStackSampleError> {
    #[cfg(feature = "tracing")]
    let _span = tracing::trace_span!("collect_stack_sample").entered();

    let max_depth = 512;
    let mut samples = Vec::new();
    let mut execute_data_ptr = top_execute_data;

    samples.try_reserve(max_depth >> 3)?;

    while let Some(execute_data) = unsafe { execute_data_ptr.as_ref() } {
        #[allow(unused_variables)]
        if let Some(func) = unsafe { execute_data.func.as_ref() } {
            let maybe_frame = unsafe { collect_call_frame(execute_data) };
            if let Some(frame) = maybe_frame {
                samples.try_push(frame)?;

                // -1 to reserve room for the [truncated] marker.
                if samples.len() == max_depth - 1 {
                    samples.try_push(ZendFrame {
                        function: FUNCTION_TRUNCATED,
                        line: 0,
                    })?;
                    break;
                }
            }
        }

        execute_data_ptr = execute_data.prev_execute_data;
    }
    Ok(Backtrace::new(samples))
}

enum FunctionNameBytes {
    TopLevel,
    TooLong,
    Bytes(Vec<u8>),
}

fn extract_function_name_bytes(func: &zend_function) -> FunctionNameBytes {
    let method_name = match func.name() {
        Some(name) if !name.is_empty() => name,
        _ => return FunctionNameBytes::TopLevel,
    };

    let module = func.module_name().unwrap_or(b"");
    let class = func.scope_name().unwrap_or(b"");

    let total_len = module.len()
        + if module.is_empty() { 0 } else { 1 } // "|"
        + class.len()
        + if class.is_empty() { 0 } else { 2 } // "::"
        + method_name.len();

    if total_len > MAX_STR_LEN {
        return FunctionNameBytes::TooLong;
    }

    let mut bytes = Vec::with_capacity(total_len);
    if !module.is_empty() {
        bytes.extend_from_slice(module);
        bytes.push(b'|');
    }
    if !class.is_empty() {
        bytes.extend_from_slice(class);
        bytes.extend_from_slice(b"::");
    }
    bytes.extend_from_slice(method_name);
    FunctionNameBytes::Bytes(bytes)
}

/// Legacy accessor for backwards compat with code that still uses
/// `extract_function_name`. This keeps the public API stable while the
/// migration to FunctionIndex-based frames is in progress.
pub fn extract_function_name(func: &zend_function) -> Option<std::borrow::Cow<'static, str>> {
    match extract_function_name_bytes(func) {
        FunctionNameBytes::TopLevel => None,
        FunctionNameBytes::TooLong => {
            Some(std::borrow::Cow::Borrowed("[suspiciously large string]"))
        }
        FunctionNameBytes::Bytes(bytes) => {
            let string = if core::str::from_utf8(&bytes).is_ok() {
                unsafe { String::from_utf8_unchecked(bytes) }
            } else {
                String::from_utf8_lossy(&bytes).into_owned()
            };
            Some(std::borrow::Cow::Owned(string))
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::bindings as zend;

    extern "C" {
        fn ddog_php_test_create_fake_zend_function_with_name_len(
            len: libc::size_t,
        ) -> *mut zend::zend_function;
        fn ddog_php_test_free_fake_zend_function(func: *mut zend::zend_function);
        fn ddog_php_test_set_op_array_reserved_slot(slot: libc::c_int);
        fn ddog_php_test_set_opcache_enabled(enabled: libc::c_int);
        fn ddog_php_test_set_opcache_file_cache_enabled(enabled: libc::c_int);
    }

    #[test]
    fn test_extract_function_name_short_string() {
        unsafe {
            let func = ddog_php_test_create_fake_zend_function_with_name_len(10);
            assert!(!func.is_null());

            let name = extract_function_name(&*func).expect("should extract name");
            assert_eq!(name, "xxxxxxxxxx");

            ddog_php_test_free_fake_zend_function(func);
        }
    }

    #[test]
    fn test_extract_function_name_at_limit() {
        unsafe {
            let func = ddog_php_test_create_fake_zend_function_with_name_len(MAX_STR_LEN);
            assert!(!func.is_null());

            let name = extract_function_name(&*func).expect("should extract name");
            assert_eq!(name.len(), MAX_STR_LEN);
            assert_ne!(name, "[suspiciously large string]");

            ddog_php_test_free_fake_zend_function(func);
        }
    }

    #[test]
    fn test_extract_function_name_over_limit() {
        unsafe {
            let func = ddog_php_test_create_fake_zend_function_with_name_len(MAX_STR_LEN + 1);
            assert!(!func.is_null());

            let name = extract_function_name(&*func).expect("should return large string marker");
            assert_eq!(name, "[suspiciously large string]");

            ddog_php_test_free_fake_zend_function(func);
        }
    }

    #[test]
    fn test_atomic_slot_round_trip() {
        unsafe {
            ddog_php_test_set_op_array_reserved_slot(0);

            let func = ddog_php_test_create_fake_zend_function_with_name_len(10);
            assert!(!func.is_null());

            assert_eq!(load_function_index(&*func), FunctionIndex(0));

            store_function_index_if_zero(&*func, FunctionIndex(42));
            assert_eq!(load_function_index(&*func), FunctionIndex(42));

            store_function_index_if_zero(&*func, FunctionIndex(99));
            assert_eq!(load_function_index(&*func), FunctionIndex(42));

            ddog_php_test_free_fake_zend_function(func);
            ddog_php_test_set_op_array_reserved_slot(-1);
        }
    }

    #[test]
    fn test_trampoline_slot_is_ignored() {
        unsafe {
            ddog_php_test_set_op_array_reserved_slot(0);

            let func = ddog_php_test_create_fake_zend_function_with_name_len(10);
            assert!(!func.is_null());
            (*func).common.fn_flags |= zend::ZEND_ACC_CALL_VIA_TRAMPOLINE as u32;

            assert_eq!(load_function_index(&*func), FunctionIndex(0));
            store_function_index_if_zero(&*func, FunctionIndex(42));
            assert_eq!(load_function_index(&*func), FunctionIndex(0));

            ddog_php_test_free_fake_zend_function(func);
            ddog_php_test_set_op_array_reserved_slot(-1);
        }
    }

    #[test]
    fn test_runtime_write_policy_for_php85_configs() {
        if zend::PHP_VERSION_ID < 80100 {
            return;
        }

        unsafe {
            ddog_php_test_set_op_array_reserved_slot(0);
            let func = ddog_php_test_create_fake_zend_function_with_name_len(10);
            assert!(!func.is_null());

            ddog_php_test_set_opcache_enabled(0);
            ddog_php_test_set_opcache_file_cache_enabled(0);
            zend::ddog_php_prof_refresh_request_opcache_policy();
            assert!(can_write_runtime_function_index(&*func));

            ddog_php_test_set_opcache_enabled(1);
            ddog_php_test_set_opcache_file_cache_enabled(0);
            zend::ddog_php_prof_refresh_request_opcache_policy();
            assert!(can_write_runtime_function_index(&*func));

            (*func).common.fn_flags |= zend::ZEND_ACC_IMMUTABLE as u32;
            assert!(!can_write_runtime_function_index(&*func));

            (*func).common.fn_flags &= !(zend::ZEND_ACC_IMMUTABLE as u32);
            ddog_php_test_set_opcache_file_cache_enabled(1);
            zend::ddog_php_prof_refresh_request_opcache_policy();
            assert!(!can_write_runtime_function_index(&*func));

            ddog_php_test_free_fake_zend_function(func);
            ddog_php_test_set_op_array_reserved_slot(-1);
            ddog_php_test_set_opcache_enabled(-1);
            ddog_php_test_set_opcache_file_cache_enabled(-1);
            crate::module_globals::ddog_php_prof_set_cached_request_opcache_policy(false, false);
        }
    }
}
