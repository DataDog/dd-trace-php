use super::CallStack;
use crate::bindings::{
    zai_str_from_zstr, zend_execute_data, zend_function, zend_op, zend_op_array,
};
#[cfg(php_frameless)]
use crate::bindings::{
    zend_flf_functions, ZEND_FRAMELESS_ICALL_0, ZEND_FRAMELESS_ICALL_1, ZEND_FRAMELESS_ICALL_2,
    ZEND_FRAMELESS_ICALL_3,
};
use crate::profiling::dictionary;
use crate::vec_ext::VecExt;
use libdd_profiling::profiles::collections::{ArcOverflow, SetError};
use libdd_profiling::profiles::datatypes::{Function2, FunctionId2, ProfilesDictionary, StringId2};
use std::borrow::Cow;

/// The profiler is not meant to handle such large strings--if a file or
/// function name exceeds this size, it will fail in some manner, or be
/// replaced by a shorter string, etc.
const STR_LEN_LIMIT: usize = u16::MAX as usize;
const COW_LARGE_STRING: Cow<str> = Cow::Borrowed("[large string]");

#[derive(Clone, Debug)]
pub struct ZendFrame {
    pub function_id: Option<FunctionId2>,
    pub line: u32, // use 0 for no line info
}

/// SAFETY: `ZendFrame` is `Send` because frames are only sent inside messages that also
/// carry an owning `Arc` to the `ProfilesDictionary` for the batch, ensuring ids live.
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
    if !module_name.is_empty() {
        buffer.extend_from_slice(module_name);
        buffer.push(b'|');
    }

    let class_name = func.scope_name().unwrap_or(b"");
    if !class_name.is_empty() {
        buffer.extend_from_slice(class_name);
        buffer.extend_from_slice(b"::");
    }

    buffer.extend_from_slice(method_name);

    // Rather than fail, we use a short string to represent a long string.
    if buffer.len() <= STR_LEN_LIMIT {
        // When replacing the string to make it valid utf-8, it may get a bit
        // longer, but this usually doesn't happen. This limit is a soft-limit
        // at the moment anyway, so this is okay.
        let string = String::from_utf8_lossy(buffer.as_slice()).into_owned();
        Some(Cow::Owned(string))
    } else {
        Some(COW_LARGE_STRING)
    }
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
            let file = if bytes.len() <= STR_LEN_LIMIT {
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

fn build_frame(
    func: &zend_function,
    execute_data: &zend_execute_data,
    dict: &ProfilesDictionary,
) -> Result<Option<ZendFrame>, SetError> {
    let name_cow = extract_function_name(func);
    let (file_cow, line) = unsafe { extract_file_and_line(execute_data) };

    let name = match name_cow {
        Some(ref s) if !s.is_empty() => dict.try_insert_str2(s)?,
        _ => StringId2::EMPTY,
    };
    let file_name = match file_cow {
        Some(ref s) if !s.is_empty() => dict.try_insert_str2(s)?,
        _ => StringId2::EMPTY,
    };

    if !name.is_empty() || !file_name.is_empty() {
        let f2 = Function2 {
            name,
            system_name: StringId2::EMPTY,
            file_name,
        };
        let function_id = Some(dict.try_insert_function2(f2)?);
        Ok(Some(ZendFrame { function_id, line }))
    } else {
        Ok(None)
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

    /// Collects the stack trace, cached strings versions.
    ///
    /// # Errors
    /// Returns [`CollectStackSampleError::TryReserveError`] if the vec holding
    /// the frames is unable to allocate memory.
    /// todo: document more errors
    #[inline]
    fn collect_stack_sample_cached(
        top_execute_data: *mut zend_execute_data,
    ) -> Result<CallStack, CollectStackSampleError> {
        let max_depth = 512;
        let mut samples = Vec::new();
        let dict = dictionary::try_clone_tls_or_global()?;
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
                                if let Some(frame) =
                                    build_frame(func, execute_data, dict.dictionary())?
                                {
                                    samples.try_push(frame)?;
                                }
                            }
                            _ => {}
                        }
                    }
                }

                let maybe_frame = unsafe { collect_call_frame(execute_data, dict.dictionary()) }?;
                if let Some(frame) = maybe_frame {
                    samples.try_push(frame)?;

                    // -1 to reserve room for the [truncated] message. In case
                    // the backend and/or frontend have the same limit, without
                    // subtracting one, then the [truncated] message itself
                    // would be truncated!
                    if samples.len() == max_depth - 1 {
                        samples.try_push(ZendFrame {
                            function_id: Some(dict.known_funcs().truncated),
                            line: 0,
                        })?;
                        break;
                    }
                }
            }

            execute_data_ptr = execute_data.prev_execute_data;
        }
        Ok(CallStack {
            frames: samples,
            dictionary: dict,
        })
    }

    #[inline(never)]
    pub fn collect_stack_sample(
        execute_data: *mut zend_execute_data,
    ) -> Result<CallStack, CollectStackSampleError> {
        #[cfg(feature = "tracing")]
        let _span = tracing::trace_span!("collect_stack_sample").entered();
        collect_stack_sample_cached(execute_data)
    }

    unsafe fn collect_call_frame(
        execute_data: &zend_execute_data,
        dict: &ProfilesDictionary,
    ) -> Result<Option<ZendFrame>, SetError> {
        #[cfg(not(feature = "stack_walking_tests"))]
        use crate::bindings::ddog_php_prof_function_run_time_cache;
        #[cfg(feature = "stack_walking_tests")]
        use crate::bindings::ddog_test_php_prof_function_run_time_cache as ddog_php_prof_function_run_time_cache;

        let func = match execute_data.func.as_ref() {
            Some(f) => f,
            None => return Ok(None),
        };
        let frame = match ddog_php_prof_function_run_time_cache(func) {
            Some(cache) => {
                // If we cannot borrow the stats, then something has gone
                // wrong, but it's not that important.
                _ = FUNCTION_CACHE_STATS.try_with_borrow_mut(|stats| {
                    if cache.is_empty() {
                        stats.missed += 1;
                    } else {
                        stats.hit += 1;
                    }
                });

                handle_function_cache_slot(func, execute_data, cache, dict)?
            }
            None => {
                // If we cannot borrow the stats, then something has gone
                // wrong, but it's not that important.
                _ = FUNCTION_CACHE_STATS.try_with_borrow_mut(|stats| stats.not_applicable += 1);
                build_frame(func, execute_data, dict)?
            }
        };
        Ok(frame)
    }

    fn handle_function_cache_slot(
        func: &zend_function,
        execute_data: &zend_execute_data,
        cache: &mut FunctionId2,
        dict: &ProfilesDictionary,
    ) -> Result<Option<ZendFrame>, SetError> {
        if !cache.is_empty() {
            let function_id = Some(*cache);
            let line = match safely_get_opline(execute_data) {
                Some(opline) => opline.lineno,
                None => 0,
            };
            let frame = ZendFrame { function_id, line };
            return Ok(Some(frame));
        }
        let frame = build_frame(func, execute_data, dict)?;
        if let Some(frame) = &frame {
            *cache = frame.function_id.unwrap_or_default();
        }
        Ok(frame)
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
    ) -> Result<CallStack, CollectStackSampleError> {
        #[cfg(feature = "tracing")]
        let _span = tracing::trace_span!("collect_stack_sample").entered();

        let max_depth = 512;
        let mut samples = Vec::new();
        samples.try_reserve(max_depth >> 3)?;
        let dict = dictionary::try_clone_tls_or_global()?;
        let mut execute_data_ptr = top_execute_data;

        while let Some(execute_data) = unsafe { execute_data_ptr.as_ref() } {
            let maybe_frame = unsafe { collect_call_frame(execute_data, dict.dictionary())? };
            if let Some(frame) = maybe_frame {
                samples.try_push(frame)?;

                /* -1 to reserve room for the [truncated] message. In case the
                 * backend and/or frontend have the same limit, without the -1
                 * then ironically the [truncated] message would be truncated.
                 */
                if samples.len() == max_depth - 1 {
                    let trunc = dict.known_funcs().truncated;
                    samples.try_push(ZendFrame {
                        function_id: Some(trunc),
                        line: 0,
                    })?;
                    break;
                }
            }

            execute_data_ptr = execute_data.prev_execute_data;
        }
        Ok(CallStack {
            frames: samples,
            dictionary: dict,
        })
    }

    unsafe fn collect_call_frame(
        execute_data: &zend_execute_data,
        dict: &ProfilesDictionary,
    ) -> Result<Option<ZendFrame>, SetError> {
        if let Some(func) = execute_data.func.as_ref() {
            build_frame(func, execute_data, dict)
        } else {
            Ok(None)
        }
    }
}

pub use detail::*;

// todo: this should be feature = "stack_walking_tests" but it seemed to
//       cause a failure in CI to migrate it.
#[cfg(all(test, stack_walking_tests))]
mod tests {
    use super::*;
    use crate::bindings as zend;

    #[test]
    fn test_collect_stack_sample() {
        unsafe {
            let fake_execute_data = zend::ddog_php_test_create_fake_zend_execute_data(3);

            let stack = collect_stack_sample(fake_execute_data).unwrap();

            assert_eq!(stack.len(), 3);

            assert_eq!(stack[0].function, "function name 003");
            assert_eq!(stack[0].file, Some("filename-003.php".into()));
            assert_eq!(stack[0].line, 0);

            assert_eq!(stack[1].function, "function name 002");
            assert_eq!(stack[1].file, Some("filename-002.php".into()));
            assert_eq!(stack[1].line, 0);

            assert_eq!(stack[2].function, "function name 001");
            assert_eq!(stack[2].file, Some("filename-001.php".into()));
            assert_eq!(stack[2].line, 0);

            // Free the allocated memory
            zend::ddog_php_test_free_fake_zend_execute_data(fake_execute_data);
        }
    }
}
