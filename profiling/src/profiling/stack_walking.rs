use crate::bindings::{
    zai_str_from_zstr, zend_execute_data, zend_function, zend_op, zend_op_array,
};
use crate::profiling::profiles_dictionary as get_profiles_dictionary;
use crate::profiling::Backtrace;
use crate::vec_ext::VecExt;
use libdd_profiling::profiles::collections::{ArcOverflow, SetError};
use libdd_profiling::profiles::datatypes::{ProfilesDictionary, StringId2};
use std::borrow::Cow;

#[cfg(php_frameless)]
use crate::bindings::zend_flf_functions;

#[cfg(php_frameless)]
use crate::bindings::{
    ZEND_FRAMELESS_ICALL_0, ZEND_FRAMELESS_ICALL_1, ZEND_FRAMELESS_ICALL_2, ZEND_FRAMELESS_ICALL_3,
};

const COW_PHP_OPEN_TAG: Cow<str> = Cow::Borrowed("<?php");
const COW_TRUNCATED: Cow<str> = Cow::Borrowed("[truncated]");

/// The profiler is not meant to handle such large strings--if a file or
/// function name exceeds this size, it will fail in some manner, or be
/// replaced by a shorter string, etc.
const STR_LEN_LIMIT: usize = u16::MAX as usize;
const COW_LARGE_STRING: Cow<str> = Cow::Borrowed("[suspiciously large string]");

fn string_ids_from_name(
    dict: &ProfilesDictionary,
    name: &str,
    file: Option<&str>,
) -> Result<(StringId2, StringId2), CollectStackSampleError> {
    let name_id = dict.try_insert_str2(name)?;
    let file_name_id = match file {
        Some(file) => dict.try_insert_str2(file)?,
        None => StringId2::EMPTY,
    };
    Ok((name_id, file_name_id))
}

fn string_id_to_usize(value: StringId2) -> usize {
    // SAFETY: StringId2 is repr(transparent) over a pointer. The
    // ProfilesDictionary is kept alive for the lifetime of the request, so
    // the ID remains valid while cached.
    unsafe { core::mem::transmute::<StringId2, usize>(value) }
}

fn usize_to_string_id(value: usize) -> StringId2 {
    // SAFETY: StringId2 is repr(transparent) over a pointer. The
    // ProfilesDictionary is kept alive for the lifetime of the request, so
    // the ID remains valid while cached.
    unsafe { core::mem::transmute::<usize, StringId2>(value) }
}

#[derive(Clone, Copy, Debug)]
pub struct ZendFrame {
    pub function: StringId2,
    pub file: StringId2,
    pub line: u32, // use 0 for no line info
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
    #[error(transparent)]
    SetError(#[from] SetError),
    #[error(transparent)]
    ArcOverflow(#[from] ArcOverflow),
}

/// Extract the "function name" component for the frame. This is a string which
/// looks like this for methods:
///     {module}|{class_name}::{method_name}
/// And this for functions:
///     {module}|{function_name}
/// Where the "{module}|" is present only if it's an internal function.
/// Namespaces are part of the class_name or function_name respectively.
/// Closures and anonymous classes get reformatted by the backend (or maybe
/// frontend, either way it's not our concern, at least not right now).
pub fn extract_function_name(func: &zend_function) -> Option<Cow<'static, str>> {
    let method_name: &[u8] = func.name().unwrap_or(b"");

    /* The top of the stack seems to reasonably often not have a function, but
     * still has a scope. I don't know if this intentional, or if it's more of
     * a situation where scope is only valid if the func is present. So, I'm
     * erring on the side of caution and returning early.
     */
    if method_name.is_empty() {
        return None;
    }

    let mut buffer = Vec::<u8>::new();

    // User functions do not have a "module". Maybe one day use composer info?
    let module_name = func.module_name().unwrap_or(b"");
    let class_name = func.scope_name().unwrap_or(b"");

    // Pre-reserving here avoids growing the vec in practice, observed with
    // whole-host profiler.
    let (has_module, has_class) = (!module_name.is_empty(), !class_name.is_empty());
    let module_len = has_module as usize * "|".len() + module_name.len();
    let class_name_len = has_class as usize * "::".len() + class_name.len();
    let len = module_len + class_name_len + method_name.len();

    // Rather than fail, we use a short string to represent a long string.
    if len >= STR_LEN_LIMIT {
        return Some(COW_LARGE_STRING);
    }

    // When refactoring, make sure large str len is checked before allocating.
    buffer.reserve_exact(len);

    if has_module {
        buffer.extend_from_slice(module_name);
        buffer.push(b'|');
    }

    if has_class {
        buffer.extend_from_slice(class_name);
        buffer.extend_from_slice(b"::");
    }

    buffer.extend_from_slice(method_name);

    // When replacing the string to make it valid utf-8, it may get a bit
    // longer, but this usually doesn't happen. This limit is a soft-limit
    // at the moment anyway, so this is okay.
    let string = String::from_utf8_lossy(buffer.as_slice()).into_owned();
    Some(Cow::Owned(string))
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

unsafe fn extract_file_and_line(
    execute_data: &zend_execute_data,
) -> (Option<Cow<'static, str>>, u32) {
    // This should be Some, just being cautious.
    match execute_data.func.as_ref() {
        Some(func) if !func.is_internal() => {
            // Safety: zai_str_from_zstr will return a valid ZaiStr.
            let bytes = zai_str_from_zstr(func.op_array.filename.as_mut()).as_bytes();
            let file = if bytes.len() < STR_LEN_LIMIT {
                Cow::Owned(String::from_utf8_lossy(bytes).into_owned())
            } else {
                COW_LARGE_STRING
            };
            let lineno = match safely_get_opline(execute_data) {
                Some(opline) => opline.lineno,
                None => 0,
            };
            (Some(file), lineno)
        }
        _ => (None, 0),
    }
}

#[cfg(php_run_time_cache)]
mod detail {
    use super::*;
    use crate::RefCellExt;
    use log::debug;
    use std::cell::RefCell;

    /// Used to help track the function run_time_cache hit rate.
    #[derive(Debug, Default)]
    struct FunctionRunTimeCacheStats {
        hit: usize,
        missed: usize,
        not_applicable: usize,
    }

    impl FunctionRunTimeCacheStats {
        const fn new() -> Self {
            Self {
                hit: 0,
                missed: 0,
                not_applicable: 0,
            }
        }
    }

    impl FunctionRunTimeCacheStats {
        fn hit_rate(&self) -> f64 {
            let denominator = (self.hit + self.missed + self.not_applicable) as f64;
            self.hit as f64 / denominator
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

    /// Collects the stack trace with run_time_cache support.
    ///
    /// # Errors
    /// Returns [`CollectStackSampleError::TryReserveError`] if the vec holding the frames is
    /// unable to allocate memory.
    #[inline]
    fn collect_stack_sample_cached(
        top_execute_data: *mut zend_execute_data,
    ) -> Result<Backtrace, CollectStackSampleError> {
        let max_depth = 512;
        let mut samples = Vec::new();
        let mut execute_data_ptr = top_execute_data;

        samples.try_reserve(max_depth >> 3)?;

        while let Some(execute_data) = unsafe { execute_data_ptr.as_ref() } {
            // allowed because it's only used on the frameless path
            #[allow(unused_variables)]
            if let Some(func) = unsafe { execute_data.func.as_ref() } {
                // It's possible that this is a fake frame put there by the
                // engine, see accel_preload on PHP 8.4 and the local variable
                // `fake_execute_data`. The frame is zeroed in this case, so
                // we can check for null.
                #[cfg(php_frameless)]
                if !func.is_internal() {
                    if let Some(opline) = safely_get_opline(execute_data) {
                        match opline.opcode as u32 {
                            ZEND_FRAMELESS_ICALL_0
                            | ZEND_FRAMELESS_ICALL_1
                            | ZEND_FRAMELESS_ICALL_2
                            | ZEND_FRAMELESS_ICALL_3 => {
                                let func = unsafe {
                                    &**zend_flf_functions.offset(opline.extended_value as isize)
                                };
                                let (function, file) = string_ids_from_name(
                                    get_profiles_dictionary(),
                                    extract_function_name(func).unwrap().as_ref(),
                                    None,
                                )?;
                                samples.try_push(ZendFrame {
                                    function,
                                    file,
                                    line: 0,
                                })?;
                            }
                            _ => {}
                        }
                    }
                }

                let maybe_frame = unsafe { collect_call_frame(execute_data) };
                if let Some(frame) = maybe_frame {
                    samples.try_push(frame)?;

                    // -1 to reserve room for the [truncated] message. In case
                    // the backend and/or frontend have the same limit, without
                    // subtracting one, then the [truncated] message itself
                    // would be truncated!
                    if samples.len() == max_depth - 1 {
                        let (function, file) = string_ids_from_name(
                            get_profiles_dictionary(),
                            COW_TRUNCATED.as_ref(),
                            None,
                        )?;
                        samples.try_push(ZendFrame {
                            function,
                            file,
                            line: 0,
                        })?;
                        break;
                    }
                }
            }

            execute_data_ptr = execute_data.prev_execute_data;
        }
        let dict = get_profiles_dictionary().try_clone()?;
        Ok(Backtrace::new(samples, dict))
    }

    #[inline(never)]
    pub fn collect_stack_sample(
        execute_data: *mut zend_execute_data,
    ) -> Result<Backtrace, CollectStackSampleError> {
        #[cfg(feature = "tracing")]
        let _span = tracing::trace_span!("collect_stack_sample").entered();
        collect_stack_sample_cached(execute_data)
    }

    unsafe fn collect_call_frame(execute_data: &zend_execute_data) -> Option<ZendFrame> {
        #[cfg(not(feature = "stack_walking_tests"))]
        use crate::bindings::ddog_php_prof_function_run_time_cache;
        #[cfg(feature = "stack_walking_tests")]
        use crate::bindings::ddog_test_php_prof_function_run_time_cache as ddog_php_prof_function_run_time_cache;

        let func = execute_data.func.as_ref()?;
        let dict = get_profiles_dictionary();
        let cache = ddog_php_prof_function_run_time_cache(func);
        let cache_applicable = cache.is_some();
        let (function, file, line, cache_hit) = match cache {
            Some(slots) => {
                let cached_name = usize_to_string_id(slots[0]);
                let cached_file = usize_to_string_id(slots[1]);
                if !cached_name.is_empty() {
                    let line = unsafe { extract_file_and_line(execute_data).1 };
                    (cached_name, cached_file, line, true)
                } else {
                    let (function, file, line) =
                        build_function_ids(dict, func, execute_data).ok()??;
                    slots[0] = string_id_to_usize(function);
                    slots[1] = string_id_to_usize(file);
                    (function, file, line, false)
                }
            }

            None => {
                let (function, file, line) =
                    build_function_ids(dict, func, execute_data).ok()??;
                (function, file, line, false)
            }
        };

        // If we cannot borrow the stats, then something has gone wrong, but
        // it's not that important.
        _ = FUNCTION_CACHE_STATS.try_with_borrow_mut(|stats| {
            if cache_applicable {
                if cache_hit {
                    stats.hit += 1;
                } else {
                    stats.missed += 1;
                }
            } else {
                stats.not_applicable += 1;
            }
        });

        Some(ZendFrame {
            function,
            file,
            line,
        })
    }

    fn build_function_ids(
        dict: &ProfilesDictionary,
        func: &zend_function,
        execute_data: &zend_execute_data,
    ) -> Result<Option<(StringId2, StringId2, u32)>, CollectStackSampleError> {
        let function = extract_function_name(func);
        let (file, line) = unsafe { extract_file_and_line(execute_data) };
        let function = match function {
            Some(function) => function,
            None => {
                if file.is_none() {
                    return Ok(None);
                }
                COW_PHP_OPEN_TAG
            }
        };
        let (name_id, file_id) = string_ids_from_name(dict, function.as_ref(), file.as_deref())?;
        Ok(Some((name_id, file_id, line)))
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

                /* -1 to reserve room for the [truncated] message. In case the
                 * backend and/or frontend have the same limit, without the -1
                 * then ironically the [truncated] message would be truncated.
                 */
                if samples.len() == max_depth - 1 {
                    let (function, file) = string_ids_from_name(
                        get_profiles_dictionary(),
                        COW_TRUNCATED.as_ref(),
                        None,
                    )?;
                    samples.try_push(ZendFrame {
                        function,
                        file,
                        line: 0,
                    })?;
                    break;
                }
            }

            execute_data_ptr = execute_data.prev_execute_data;
        }
        let dict = get_profiles_dictionary().try_clone()?;
        Ok(Backtrace::new(samples, dict))
    }

    unsafe fn collect_call_frame(execute_data: &zend_execute_data) -> Option<ZendFrame> {
        if let Some(func) = execute_data.func.as_ref() {
            let function = extract_function_name(func);
            let (file, line) = unsafe { extract_file_and_line(execute_data) };

            // Only create a new frame if there's file or function info.
            if file.is_some() || function.is_some() {
                // If there's no function name, use a fake name.
                let function = function.unwrap_or(COW_PHP_OPEN_TAG);
                let (function, file) = string_ids_from_name(
                    get_profiles_dictionary(),
                    function.as_ref(),
                    file.as_deref(),
                )
                .ok()?;
                return Some(ZendFrame {
                    function,
                    file,
                    line,
                });
            }
        }
        None
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

    #[test]
    #[cfg(stack_walking_tests)]
    fn test_collect_stack_sample() {
        unsafe {
            crate::profiling::profiles_dictionary::init_profiles_dictionary();
            let fake_execute_data = zend::ddog_php_test_create_fake_zend_execute_data(3);

            let stack = collect_stack_sample(fake_execute_data).unwrap();

            assert_eq!(stack.len(), 3);

            let frames = &stack;
            let dict = stack.profiles_dictionary();

            let fn_003 = dict.try_insert_str2("function name 003").unwrap();
            let file_003 = dict.try_insert_str2("filename-003.php").unwrap();
            let fn_002 = dict.try_insert_str2("function name 002").unwrap();
            let file_002 = dict.try_insert_str2("filename-002.php").unwrap();
            let fn_001 = dict.try_insert_str2("function name 001").unwrap();
            let file_001 = dict.try_insert_str2("filename-001.php").unwrap();

            assert_eq!(frames[0].function, fn_003);
            assert_eq!(frames[0].file, file_003);
            assert_eq!(frames[0].line, 0);

            assert_eq!(frames[1].function, fn_002);
            assert_eq!(frames[1].file, file_002);
            assert_eq!(frames[1].line, 0);

            assert_eq!(frames[2].function, fn_001);
            assert_eq!(frames[2].file, file_001);
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
