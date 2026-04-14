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
use std::borrow::Cow;
use std::sync::atomic::{AtomicU64, Ordering};

#[cfg(php_run_time_cache)]
use crate::string_set::StringSet;
#[cfg(php_run_time_cache)]
use libdd_profiling::profiles::collections::ThinStr;
#[cfg(php_run_time_cache)]
use std::ptr::NonNull;

#[cfg(php_run_time_cache)]
use crate::bindings::ddog_php_prof_function_run_time_cache;

static FILE_CACHE_SKIP_LOG_COUNT: AtomicU64 = AtomicU64::new(0);

/// Represents the PHP -> libdatadog intermediate representation of a function.
/// The order of preference is:
///  1. A `FunctionIndex` from shared memory.
///  2. Borrowed static string.
///  3. An owned string.
///
#[derive(Debug, Clone)]
pub enum IrFunction {
    Shm(FunctionIndex),
    Owned {
        name: Cow<'static, str>,
        file: Cow<'static, str>,
    },
}

/// Represents the PHP -> libdatadog intermediate representation of a location,
/// e.g. a function + line number (no inlined functions, no mappings in PHP).
#[derive(Debug, Clone)]
pub struct IrLocation {
    pub function: IrFunction,
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
    if unsafe { func.common.fn_flags } & zend::ZEND_ACC_CALL_VIA_TRAMPOLINE != 0 {
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

/// Per-request (per-worker) string cache backed by `ProfilerGlobals::string_cache`.
/// Slot 0 = function name ThinStr, Slot 1 = file path ThinStr.
/// Sentinel: 0 (null pointer) — ThinStr is never null, so 0 means "not yet cached".
#[cfg(php_run_time_cache)]
struct StringCache<'a> {
    cache_slots: &'a mut [usize; 2],
    string_set: &'a mut StringSet,
}

#[cfg(php_run_time_cache)]
impl StringCache<'_> {
    fn get_or_insert<F>(&mut self, slot: usize, f: F) -> Option<String>
    where
        F: FnOnce() -> Option<String>,
    {
        debug_assert!(slot < self.cache_slots.len());
        let cached = unsafe { self.cache_slots.get_unchecked_mut(slot) };
        let ptr = *cached as *mut c_void;
        match NonNull::new(ptr) {
            Some(non_null) => {
                // SAFETY: the raw pointer was produced by ThinStr::into_raw and the
                // StringSet (which owns the backing memory) is alive until GSHUTDOWN
                // or the RSHUTDOWN threshold reset — which only happens after all
                // ThinStr reads for the current request are done.
                let thin_str: ThinStr = unsafe { ThinStr::from_raw(non_null) };
                let s = unsafe { self.string_set.get_thin_str(thin_str) };
                Some(s.to_owned())
            }
            None => {
                let string = f()?;
                let thin_str = self.string_set.insert(&string);
                *cached = thin_str.into_raw().as_ptr() as usize;
                Some(string)
            }
        }
    }
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
    match shm.intern_function(name_idx, file_idx) {
        Ok(idx) => idx,
        Err(InternError::StrTooLong) => FUNCTION_SUSPICIOUSLY_LONG,
        Err(InternError::OutOfMemory) => FUNCTION_OOM,
        Err(InternError::WouldBlock) => {
            if func.is_internal() {
                FUNCTION_UNKNOWN_INTERNAL
            } else {
                FUNCTION_UNKNOWN_USER
            }
        }
    }
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

fn owned_function_ir(func: &zend_function) -> IrFunction {
    let name = match extract_function_name(func) {
        Some(n) => n,
        None => Cow::Borrowed("<?php"),
    };
    let file = if func.is_internal() {
        Cow::Borrowed("")
    } else {
        let bytes = unsafe {
            crate::bindings::zai_str_from_zstr(func.op_array.filename.as_mut()).as_bytes()
        };
        if bytes.is_empty() {
            Cow::Borrowed("")
        } else {
            Cow::Owned(String::from_utf8_lossy(bytes).into_owned())
        }
    };
    IrFunction::Owned { name, file }
}

/// Three-tier function lookup for stack walking:
///
/// 1. `reserved[slot]` — `FunctionIndex` written at OPcache persist time (hot path).
/// 2. PHP runtime cache 2 slots — `ThinStr` raw pointers written on first stack walk per
///    function; backed by the per-worker `ProfilerGlobals::string_cache` arena.
/// 3. `owned_function_ir` — allocates strings on every walk (fallback when runtime cache
///    is unavailable or on PHP ≤ 7.4).
#[inline]
fn read_or_fallback(func: &zend_function) -> IrFunction {
    // Tier 1: SHM FunctionIndex, written at OPcache persist time.
    let idx = load_function_index(func);
    if idx.0 != 0 {
        return IrFunction::Shm(idx);
    }

    // Tier 2: ThinStr runtime cache, populated on first stack walk per function.
    // Not available on PHP ≤ 7.4 (no runtime cache slots).
    // Safety: stack walking runs at zend_interrupt_function (worker thread between
    // GINIT and GSHUTDOWN), never inside a signal handler.
    #[cfg(php_run_time_cache)]
    {
        let string_cache = unsafe { module_globals::get_string_cache() };
        if let Ok(mut string_set) = string_cache.try_borrow_mut() {
            let slots = unsafe { ddog_php_prof_function_run_time_cache(func as *const _) };
            if !slots.is_null() {
                let mut cache = StringCache {
                    cache_slots: unsafe { &mut *slots },
                    string_set: &mut string_set,
                };
                let name = cache
                    .get_or_insert(0, || extract_function_name(func).map(Cow::into_owned))
                    .unwrap_or_default();
                let file = cache
                    .get_or_insert(1, || {
                        if func.is_internal() {
                            return None;
                        }
                        let bytes = unsafe {
                            crate::bindings::zai_str_from_zstr(func.op_array.filename.as_mut())
                                .as_bytes()
                        };
                        if bytes.is_empty() {
                            None
                        } else {
                            Some(String::from_utf8_lossy(bytes).into_owned())
                        }
                    })
                    .unwrap_or_default();
                return IrFunction::Owned {
                    name: Cow::Owned(name),
                    file: Cow::Owned(file),
                };
            }
        }
    }

    // Tier 3: no runtime cache available — allocates on every walk.
    owned_function_ir(func)
}

unsafe fn collect_call_frame(execute_data: &zend_execute_data) -> Option<IrLocation> {
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
                    return Some(IrLocation { function, line: 0 });
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

    Some(IrLocation { function, line })
}

/// # Safety
/// Must be called in Zend Extension activate.
#[inline]
pub unsafe fn activate() {}

pub fn rshutdown() {
    // Reset the per-worker string cache arena if it has grown too large.
    // Only available when PHP runtime cache slots exist (PHP ≥ 8.0).
    // Safety: rshutdown is called on the worker thread between GINIT and GSHUTDOWN.
    #[cfg(php_run_time_cache)]
    {
        let string_cache = unsafe { module_globals::get_string_cache() };
        if let Ok(mut string_set) = string_cache.try_borrow_mut() {
            // A slow ramp up to 2 MiB is probably _not_ going to look like a
            // memory leak. A higher threshold may make a user suspect a leak.
            const THRESHOLD: usize = 2 * 1024 * 1024;
            let string_set: &mut StringSet = &mut string_set;
            let used_bytes = string_set.arena_used_bytes();
            if used_bytes > THRESHOLD {
                log::debug!(
                    "string cache arena is using {used_bytes} bytes which exceeds the \
                     {THRESHOLD} byte threshold, resetting"
                );
                // Note: cannot reset _during_ a request. ThinStrs in the runtime
                // cache must remain valid until request end (RSHUTDOWN).
                *string_set = StringSet::new();
            } else {
                log::trace!(
                    "string cache arena is using {used_bytes} bytes which is under the \
                     {THRESHOLD} byte threshold"
                );
            }
        }
    }
}

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
                    samples.try_push(IrLocation {
                        function: IrFunction::Shm(FUNCTION_TRUNCATED),
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

pub fn extract_function_name(func: &zend_function) -> Option<Cow<'static, str>> {
    match extract_function_name_bytes(func) {
        FunctionNameBytes::TopLevel => None,
        FunctionNameBytes::TooLong => Some(Cow::Borrowed("[suspiciously large string]")),
        FunctionNameBytes::Bytes(bytes) => {
            let string = if core::str::from_utf8(&bytes).is_ok() {
                unsafe { String::from_utf8_unchecked(bytes) }
            } else {
                String::from_utf8_lossy(&bytes).into_owned()
            };
            Some(Cow::Owned(string))
        }
    }
}
