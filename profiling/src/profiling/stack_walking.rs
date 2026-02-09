use crate::bindings::{
    zai_str_from_zstr, zend_execute_data, zend_function, zend_op, zend_op_array,
};
use crate::profiling::Backtrace;
use crate::vec_ext::{self, VecExt};
use libdd_profiling::profiles::datatypes::{Function2, FunctionId2, StringId2};
use std::borrow::Cow;
use std::collections::TryReserveError;

#[cfg(php_frameless)]
use crate::bindings::zend_flf_functions;

#[cfg(php_frameless)]
use crate::bindings::{
    ZEND_FRAMELESS_ICALL_0, ZEND_FRAMELESS_ICALL_1, ZEND_FRAMELESS_ICALL_2, ZEND_FRAMELESS_ICALL_3,
};

/// The profiler is not meant to handle such large strings--if a file or
/// function name exceeds this size, it will fail in some manner, or be
/// replaced by a shorter string, etc.
const STR_LEN_LIMIT: usize = u16::MAX as usize;

const COW_PHP_OPEN_TAG: Cow<str> = Cow::Borrowed("<?php");
const COW_LARGE_STRING: Cow<str> = Cow::Borrowed("[suspiciously large string]");

#[derive(Clone, Copy, Debug, Default)]
pub struct ZendFrame {
    /// A [`FunctionId2`] handle into the global [`ProfilesDictionary`].
    /// Encapsulates function name, system name, and filename.
    pub function: FunctionId2,
    pub line: u32, // use 0 for no line info
}

// SAFETY: FunctionId2 points into the global ProfilesDictionary which is
// Send + Sync and lives for the process lifetime.
unsafe impl Send for ZendFrame {}
unsafe impl Sync for ZendFrame {}

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

/// Computes the fully-qualified function name for a frame.
///
/// The result looks like `{module}|{class}::{method}` for internal methods,
/// `{class}::{method}` for user methods, `{function}` for plain functions,
/// or `"<?php"` for top-level script code (no function name).
///
/// All allocations are fallible — returns `Err` on OOM, never panics.
/// Names exceeding [`STR_LEN_LIMIT`] are replaced with
/// `"[suspiciously large string]"`.
pub fn extract_function_name(func: &zend_function) -> Result<Cow<'static, str>, TryReserveError> {
    let method_name: &[u8] = func.name().unwrap_or(b"");

    // The top of the stack seems to reasonably often not have a function, but
    // still has a scope. I don't know if this intentional, or if it's more of
    // a situation where scope is only valid if the func is present. So, I'm
    // erring on the side of caution and returning early.
    if method_name.is_empty() {
        return Ok(COW_PHP_OPEN_TAG);
    }

    // User functions do not have a "module". Maybe one day use composer info?
    let module_name = func.module_name().unwrap_or(b"");
    let class_name = func.scope_name().unwrap_or(b"");

    // Pre-reserving here avoids growing the vec in practice, observed with
    // whole-host profiler.
    let (has_module, has_class) = (!module_name.is_empty(), !class_name.is_empty());
    let module_len = has_module as usize * "|".len() + module_name.len();
    let class_name_len = has_class as usize * "::".len() + class_name.len();
    let len = module_len + class_name_len + method_name.len();

    // When refactoring, make sure large str len is checked before allocating.
    if len >= STR_LEN_LIMIT {
        return Ok(COW_LARGE_STRING);
    }

    let mut buffer = Vec::<u8>::new();
    buffer.try_reserve_exact(len)?;

    // Capacity was pre-reserved for the exact total length, so these
    // try_extend_from_slice calls will not allocate.
    if has_module {
        buffer.try_extend_from_slice(module_name)?;
        buffer.try_extend_from_slice(b"|")?;
    }

    if has_class {
        buffer.try_extend_from_slice(class_name)?;
        buffer.try_extend_from_slice(b"::")?;
    }

    buffer.try_extend_from_slice(method_name)?;

    vec_ext::try_cow_from_utf8_lossy_vec(buffer)
}

/// Gets an opline reference after doing bounds checking to prevent segfaults
/// on dangling pointers that have been observed when dereferencing
/// `execute_data.opline` under some conditions.
#[inline]
fn safely_get_opline(execute_data: &zend_execute_data) -> Option<&zend_op> {
    let func = unsafe { execute_data.func.as_ref()? };
    let op_array = func.op_array()?;
    if opline_in_bounds(op_array, execute_data.opline) {
        // SAFETY: we did our best we could to validate that this pointer is
        // non-NULL and not dangling and actually pointing to the right kind of
        // data. Otherwise, this is the crash site you are looking for.
        unsafe { Some(&*execute_data.opline) }
    } else {
        None
    }
}

#[inline]
fn opline_in_bounds(op_array: &zend_op_array, opline: *const zend_op) -> bool {
    let opcodes_start = op_array.opcodes;
    // Just being safe, not sure if this can happen in practice.
    if opcodes_start.is_null() || opline.is_null() {
        return false;
    }

    let begin = opcodes_start as usize;
    // `op_array.last` is a count of `zend_op` sized elements to be found in `op_array.opcodes`
    let end = begin + (op_array.last as usize) * core::mem::size_of::<zend_op>();
    (begin..end).contains(&(opline as usize))
}

/// Computes function name (and optionally filename) for a function and inserts
/// them into the global [`ProfilesDictionary`], returning a [`FunctionId2`].
///
/// Returns `None` if dictionary insertion fails or OOM.
fn insert_function_into_dictionary(func: &zend_function) -> Option<FunctionId2> {
    let dict = crate::interning::dictionary();
    let ks = crate::interning::known_strings();

    let function_name = extract_function_name(func).ok()?;
    let name_id = dict.try_insert_str2(&function_name).ok()?;

    let file_id = if !func.is_internal() {
        // SAFETY: op_array.filename is always valid for user functions
        // — this is a PHP engine invariant.
        let file_bytes = unsafe { zai_str_from_zstr(func.op_array.filename.as_mut()).as_bytes() };
        if file_bytes.len() < STR_LEN_LIMIT {
            let file_str = String::from_utf8_lossy(file_bytes);
            dict.try_insert_str2(&file_str).ok()?
        } else {
            ks.suspiciously_large
        }
    } else {
        StringId2::default()
    };

    let func2 = Function2 {
        name: name_id,
        system_name: StringId2::default(),
        file_name: file_id,
    };
    dict.try_insert_function2(func2).ok()
}

#[cfg(php_run_time_cache)]
mod detail {
    use super::*;
    use crate::RefCellExt;
    use log::debug;
    use std::cell::RefCell;

    /// Used to help track cache hit rates across the SHM and run_time_cache
    /// layers. A miss is only counted when both layers miss.
    #[derive(Debug, Default)]
    struct FunctionRunTimeCacheStats {
        /// Hit in the opcache SHM reserved[] slot (internal functions) or
        /// dictionary insertion from SHM data (op_arrays).
        shm_hit: usize,
        /// Hit in the per-request run_time_cache slot.
        hit: usize,
        /// All cache layers missed; had to compute fresh.
        missed: usize,
        /// Cache not applicable (e.g. no cache slots available).
        not_applicable: usize,
    }

    impl FunctionRunTimeCacheStats {
        const fn new() -> Self {
            Self {
                shm_hit: 0,
                hit: 0,
                missed: 0,
                not_applicable: 0,
            }
        }

        fn hit_rate(&self) -> f64 {
            let denominator = (self.shm_hit + self.hit + self.missed + self.not_applicable) as f64;
            (self.shm_hit + self.hit) as f64 / denominator
        }
    }

    thread_local! {
        static FUNCTION_CACHE_STATS: RefCell<FunctionRunTimeCacheStats> =
            const { RefCell::new(FunctionRunTimeCacheStats::new()) }
    }

    /// # Safety
    /// Must be called in Zend Extension activate.
    #[inline]
    pub unsafe fn activate() {}

    #[inline]
    pub fn rshutdown() {
        // If we cannot borrow the stats, then something has gone wrong, but
        // it's not that important.
        _ = FUNCTION_CACHE_STATS.try_with_borrow(|stats| {
            let hit_rate = stats.hit_rate();
            debug!("Process cumulative {stats:?} hit_rate: {hit_rate}");
        });
    }

    /// Collects the stack trace.
    ///
    /// # Errors
    /// Returns [`CollectStackSampleError::TryReserveError`] if the vec
    /// holding the frames is unable to allocate memory.
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
            // allowed because it's only used on the frameless path
            #[allow(unused_variables)]
            if let Some(func) = unsafe { execute_data.func.as_ref() } {
                #[cfg(php_frameless)]
                if !func.is_internal() {
                    if let Some(opline) = safely_get_opline(execute_data) {
                        match opline.opcode as u32 {
                            ZEND_FRAMELESS_ICALL_0
                            | ZEND_FRAMELESS_ICALL_1
                            | ZEND_FRAMELESS_ICALL_2
                            | ZEND_FRAMELESS_ICALL_3 => {
                                let flf_func = unsafe {
                                    &**zend_flf_functions.offset(opline.extended_value as isize)
                                };
                                if let Some(fid) = insert_function_into_dictionary(flf_func) {
                                    samples.try_push(ZendFrame {
                                        function: fid,
                                        line: 0,
                                    })?;
                                }
                            }
                            _ => {}
                        }
                    }
                }

                let maybe_frame = unsafe { collect_call_frame(execute_data) };
                if let Some(frame) = maybe_frame {
                    samples.try_push(frame)?;

                    if samples.len() == max_depth - 1 {
                        samples.try_push(ZendFrame {
                            function: crate::interning::known_functions().truncated,
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

    unsafe fn collect_call_frame(execute_data: &zend_execute_data) -> Option<ZendFrame> {
        #[cfg(not(feature = "stack_walking_tests"))]
        use crate::bindings::ddog_php_prof_function_run_time_cache;
        #[cfg(feature = "stack_walking_tests")]
        use crate::bindings::ddog_test_php_prof_function_run_time_cache as ddog_php_prof_function_run_time_cache;

        let func = execute_data.func.as_ref()?;

        // Layer 1: SHM / internal function cache (returns FunctionId2 directly).
        #[cfg(php_opcache_shm_cache)]
        if let Some(function_id) = unsafe { crate::shm_cache::try_get_cached(func) } {
            _ = FUNCTION_CACHE_STATS.try_with_borrow_mut(|stats| stats.shm_hit += 1);
            let line = safely_get_opline(execute_data).map_or(0, |op| op.lineno);
            return Some(ZendFrame {
                function: function_id,
                line,
            });
        }

        // Layer 2: per-request run_time_cache (stores FunctionId2 in slot[0]).
        if let Some(slots) = ddog_php_prof_function_run_time_cache(func) {
            let cached = slots[0];
            if cached != 0 {
                // Cache hit: the value is a FunctionId2 stored as usize.
                _ = FUNCTION_CACHE_STATS.try_with_borrow_mut(|stats| stats.hit += 1);
                // SAFETY: we only ever store valid FunctionId2 values (via
                // transmute) in this slot, and the dictionary is immortal.
                let function_id: FunctionId2 =
                    unsafe { std::mem::transmute::<usize, FunctionId2>(cached) };
                let line = safely_get_opline(execute_data).map_or(0, |op| op.lineno);
                return Some(ZendFrame {
                    function: function_id,
                    line,
                });
            }

            // Cache miss: compute, insert into dictionary, cache.
            _ = FUNCTION_CACHE_STATS.try_with_borrow_mut(|stats| stats.missed += 1);
            let function_id = insert_function_into_dictionary(func)?;
            slots[0] = unsafe { std::mem::transmute::<FunctionId2, usize>(function_id) };
            let line = safely_get_opline(execute_data).map_or(0, |op| op.lineno);
            return Some(ZendFrame {
                function: function_id,
                line,
            });
        }

        // Layer 3: fallback (no cache available).
        _ = FUNCTION_CACHE_STATS.try_with_borrow_mut(|stats| stats.not_applicable += 1);
        let function_id = insert_function_into_dictionary(func)?;
        let line = safely_get_opline(execute_data).map_or(0, |op| op.lineno);
        Some(ZendFrame {
            function: function_id,
            line,
        })
    }
}

#[cfg(not(php_run_time_cache))]
mod detail {
    use super::*;

    /// # Safety
    /// This is actually safe, but it is marked unsafe for symmetry when the
    /// run_time_cache is enabled.
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
        let mut samples = Vec::with_capacity(max_depth >> 3);
        let mut execute_data_ptr = top_execute_data;

        while let Some(execute_data) = unsafe { execute_data_ptr.as_ref() } {
            let maybe_frame = unsafe { collect_call_frame(execute_data) };
            if let Some(frame) = maybe_frame {
                samples.try_push(frame)?;

                if samples.len() == max_depth - 1 {
                    samples.try_push(ZendFrame {
                        function: crate::interning::known_functions().truncated,
                        line: 0,
                    })?;
                    break;
                }
            }

            execute_data_ptr = execute_data.prev_execute_data;
        }
        Ok(Backtrace::new(samples))
    }

    unsafe fn collect_call_frame(execute_data: &zend_execute_data) -> Option<ZendFrame> {
        let func = execute_data.func.as_ref()?;
        let function_id = insert_function_into_dictionary(func)?;
        let line = safely_get_opline(execute_data).map_or(0, |op| op.lineno);
        Some(ZendFrame {
            function: function_id,
            line,
        })
    }
}

pub use detail::*;

#[cfg(test)]
mod tests {
    use super::*;
    use crate::bindings as zend;

    extern "C" {
        fn ddog_php_test_create_fake_zend_function_with_name_len(
            len: libc::size_t,
        ) -> *mut zend::zend_function;
        fn ddog_php_test_free_fake_zend_function(func: *mut zend::zend_function);
    }

    #[cfg(feature = "stack_walking_tests")]
    /// Resolves the function name string from a [`ZendFrame`]'s
    /// [`FunctionId2`] via the global [`ProfilesDictionary`].
    unsafe fn resolve_function_name(frame: &ZendFrame) -> &str {
        let func2 = unsafe { frame.function.read() }.expect("FunctionId2 should not be empty");
        let string_ref = libdd_profiling::profiles::collections::StringRef::from(func2.name);
        unsafe { crate::interning::dictionary().strings().get(string_ref) }
    }

    #[cfg(feature = "stack_walking_tests")]
    /// Resolves the filename string from a [`ZendFrame`]'s [`FunctionId2`]
    /// via the global [`ProfilesDictionary`].
    unsafe fn resolve_file_name(frame: &ZendFrame) -> &str {
        let func2 = unsafe { frame.function.read() }.expect("FunctionId2 should not be empty");
        let string_ref = libdd_profiling::profiles::collections::StringRef::from(func2.file_name);
        unsafe { crate::interning::dictionary().strings().get(string_ref) }
    }

    #[test]
    #[cfg(feature = "stack_walking_tests")]
    fn test_collect_stack_sample() {
        crate::interning::init();

        unsafe {
            let fake_execute_data = zend::ddog_php_test_create_fake_zend_execute_data(3);

            let stack = collect_stack_sample(fake_execute_data).unwrap();

            assert_eq!(stack.len(), 3);

            let frames = &stack;
            assert_eq!(resolve_function_name(&frames[0]), "function name 003");
            assert_eq!(resolve_file_name(&frames[0]), "filename-003.php");
            assert_eq!(frames[0].line, 0);

            assert_eq!(resolve_function_name(&frames[1]), "function name 002");
            assert_eq!(resolve_file_name(&frames[1]), "filename-002.php");
            assert_eq!(frames[1].line, 0);

            assert_eq!(resolve_function_name(&frames[2]), "function name 001");
            assert_eq!(resolve_file_name(&frames[2]), "filename-001.php");
            assert_eq!(frames[2].line, 0);

            // Free the allocated memory
            zend::ddog_php_test_free_fake_zend_execute_data(fake_execute_data);
        }
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
    fn test_extract_function_name_at_limit_minus_one() {
        unsafe {
            let func = ddog_php_test_create_fake_zend_function_with_name_len(STR_LEN_LIMIT - 1);
            assert!(!func.is_null());

            let name = extract_function_name(&*func).expect("should extract name");
            assert_eq!(name.len(), STR_LEN_LIMIT - 1);
            assert_ne!(name, COW_LARGE_STRING);

            ddog_php_test_free_fake_zend_function(func);
        }
    }

    #[test]
    fn test_extract_function_name_at_limit() {
        unsafe {
            let func = ddog_php_test_create_fake_zend_function_with_name_len(STR_LEN_LIMIT);
            assert!(!func.is_null());

            let name = extract_function_name(&*func).expect("should return large string marker");
            assert_eq!(name, COW_LARGE_STRING);

            ddog_php_test_free_fake_zend_function(func);
        }
    }

    #[test]
    fn test_extract_function_name_over_limit() {
        unsafe {
            let func = ddog_php_test_create_fake_zend_function_with_name_len(STR_LEN_LIMIT + 1000);
            assert!(!func.is_null());

            let name = extract_function_name(&*func).expect("should return large string marker");
            assert_eq!(name, COW_LARGE_STRING);

            ddog_php_test_free_fake_zend_function(func);
        }
    }
}
