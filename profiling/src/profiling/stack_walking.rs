use crate::bindings::{
    zai_str_from_zstr, zend_execute_data, zend_function, zend_op, zend_op_array,
};
use crate::vec_ext::VecExt;
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
const COW_LARGE_STRING: Cow<str> = Cow::Borrowed("[large string]");

#[derive(Default, Debug)]
pub struct ZendFrame {
    // Most tools don't like frames that don't have function names, so use a
    // fake name if you need to like "<?php".
    pub function: Cow<'static, str>,
    pub file: Option<Cow<'static, str>>,
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

#[cfg(php_run_time_cache)]
mod detail {
    use super::*;
    use crate::string_set::StringSet;
    use crate::thin_str::ThinStr;
    use crate::{RefCellExt, RefCellExtError};
    use log::{debug, trace};
    use std::cell::RefCell;
    use std::ptr::NonNull;

    struct StringCache<'a> {
        /// Refers to a function's run time cache reserved by this extension.
        cache_slots: &'a mut [usize; 2],

        /// Refers to the string set in the thread-local storage.
        string_set: &'a mut StringSet,
    }

    impl StringCache<'_> {
        /// Makes a copy of the string in the cache slot. If there isn't a
        /// string in the slot currently, then create one by calling the
        /// provided function, store it in the string cache and cache slot,
        /// and return it.
        fn get_or_insert<F>(&mut self, slot: usize, f: F) -> Option<String>
        where
            F: FnOnce() -> Option<String>,
        {
            debug_assert!(slot < self.cache_slots.len());
            let cached = unsafe { self.cache_slots.get_unchecked_mut(slot) };

            let ptr = *cached as *mut u8;
            match NonNull::new(ptr) {
                Some(non_null) => {
                    // SAFETY: transmuting ThinStr from its repr.
                    let thin_str: ThinStr = unsafe { core::mem::transmute(non_null) };
                    // SAFETY: the string set is only reset between requests,
                    // so this ThinStr points into the same string set that
                    // created it.
                    let str = unsafe { self.string_set.get_thin_str(thin_str) };
                    Some(str.to_string())
                }
                None => {
                    let string = f()?;
                    let thin_str = self.string_set.insert(&string);
                    // SAFETY: transmuting ThinStr into its repr.
                    let non_null: NonNull<u8> = unsafe { core::mem::transmute(thin_str) };
                    *cached = non_null.as_ptr() as usize;
                    Some(string)
                }
            }
        }
    }

    /// Used to help track the function run_time_cache hit rate. It glosses
    /// over the fact that there are two cache slots used, and they don't have
    /// to be in sync. However, they usually are, so we simplify.
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
        static CACHED_STRINGS: RefCell<StringSet> = RefCell::new(StringSet::new());
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

        let result = CACHED_STRINGS.try_with_borrow_mut(|string_set| {
            // A slow ramp up to 2 MiB is probably _not_ going to look like a
            // memory leak. A higher threshold may make a user suspect a leak.
            const THRESHOLD: usize = 2 * 1024 * 1024;

            let used_bytes = string_set.arena_used_bytes();
            if used_bytes > THRESHOLD {
                debug!("string cache arena is using {used_bytes} bytes which exceeds the {THRESHOLD} byte threshold, resetting");
                // Note that this cannot be done _during_ a request. The
                // ThinStrs inside the run time cache need to remain valid
                // during the request.
                *string_set = StringSet::new();
            } else {
                trace!("string cache arena is using {used_bytes} bytes which is less than the {THRESHOLD} byte threshold");
            }
        });

        if let Err(err) = result {
            // Debug level because rshutdown could be quite spammy.
            debug!("failed to borrow request locals in rshutdown: {err}");
        }
    }

    /// Collects the stack trace, cached strings versions.
    ///
    /// # Errors
    /// Returns [`CollectStackSampleError::TryReserveError`] if the vec holding the frames is
    /// unable to allocate memory.
    #[inline]
    fn collect_stack_sample_cached(
        top_execute_data: *mut zend_execute_data,
        string_set: &mut StringSet,
    ) -> Result<Vec<ZendFrame>, CollectStackSampleError> {
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
                                samples.try_push(ZendFrame {
                                    function: extract_function_name(func).unwrap(),
                                    file: None,
                                    line: 0,
                                })?;
                            }
                            _ => {}
                        }
                    }
                }

                let maybe_frame = unsafe { collect_call_frame(execute_data, string_set) };
                if let Some(frame) = maybe_frame {
                    samples.try_push(frame)?;

                    // -1 to reserve room for the [truncated] message. In case
                    // the backend and/or frontend have the same limit, without
                    // subtracting one, then the [truncated] message itself
                    // would be truncated!
                    if samples.len() == max_depth - 1 {
                        samples.try_push(ZendFrame {
                            function: COW_TRUNCATED,
                            file: None,
                            line: 0,
                        })?;
                        break;
                    }
                }
            }

            execute_data_ptr = execute_data.prev_execute_data;
        }
        Ok(samples)
    }

    #[inline(never)]
    pub fn collect_stack_sample(
        execute_data: *mut zend_execute_data,
    ) -> Result<Vec<ZendFrame>, CollectStackSampleError> {
        #[cfg(feature = "tracing")]
        let _span = tracing::trace_span!("collect_stack_sample").entered();
        CACHED_STRINGS
            .try_with_borrow_mut(|set| collect_stack_sample_cached(execute_data, set))
            .unwrap_or_else(|err| match err {
                RefCellExtError::AccessError(e) => Err(e.into()),
                RefCellExtError::BorrowError(e) => Err(e.into()),
                RefCellExtError::BorrowMutError(e) => Err(e.into()),
            })
    }

    unsafe fn collect_call_frame(
        execute_data: &zend_execute_data,
        string_set: &mut StringSet,
    ) -> Option<ZendFrame> {
        #[cfg(not(feature = "stack_walking_tests"))]
        use crate::bindings::ddog_php_prof_function_run_time_cache;
        #[cfg(feature = "stack_walking_tests")]
        use crate::bindings::ddog_test_php_prof_function_run_time_cache as ddog_php_prof_function_run_time_cache;

        let func = execute_data.func.as_ref()?;
        let (function, file, line) = match ddog_php_prof_function_run_time_cache(func) {
            Some(slots) => {
                let mut string_cache = StringCache {
                    cache_slots: slots,
                    string_set,
                };
                let function = handle_function_cache_slot(func, &mut string_cache);
                let (file, line) = handle_file_cache_slot(execute_data, &mut string_cache);

                let cache_slots = string_cache.cache_slots;
                // If we cannot borrow the stats, then something has gone
                // wrong, but it's not that important.
                _ = FUNCTION_CACHE_STATS.try_with_borrow_mut(|stats| {
                    if cache_slots[0] == 0 {
                        stats.missed += 1;
                    } else {
                        stats.hit += 1;
                    }
                });

                (function, file.map(Cow::Owned), line)
            }

            None => {
                // If we cannot borrow the stats, then something has gone
                // wrong, but it's not that important.
                _ = FUNCTION_CACHE_STATS.try_with_borrow_mut(|stats| stats.not_applicable += 1);
                let function = extract_function_name(func);
                let (file, line) = extract_file_and_line(execute_data);
                (function, file, line)
            }
        };

        if function.is_some() || file.is_some() {
            Some(ZendFrame {
                function: function.unwrap_or(COW_PHP_OPEN_TAG),
                file,
                line,
            })
        } else {
            None
        }
    }

    fn handle_function_cache_slot(
        func: &zend_function,
        string_cache: &mut StringCache,
    ) -> Option<Cow<'static, str>> {
        let fname =
            string_cache.get_or_insert(0, || extract_function_name(func).map(Cow::into_owned))?;
        Some(Cow::Owned(fname))
    }

    unsafe fn handle_file_cache_slot(
        execute_data: &zend_execute_data,
        string_cache: &mut StringCache,
    ) -> (Option<String>, u32) {
        let option = string_cache.get_or_insert(1, || -> Option<String> {
            unsafe {
                // Safety: if we have cache slots, we definitely have a func.
                let func = &*execute_data.func;
                if func.is_internal() {
                    return None;
                };

                // SAFETY: calling C function with correct args.
                let file = zai_str_from_zstr(func.op_array.filename.as_mut()).into_string();
                Some(file)
            }
        });
        match option {
            Some(filename) => {
                let lineno = match safely_get_opline(execute_data) {
                    Some(opline) => opline.lineno,
                    None => 0,
                };
                (Some(filename), lineno)
            }
            None => (None, 0),
        }
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
    ) -> Result<Vec<ZendFrame>, CollectStackSampleError> {
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
                    samples.try_push(ZendFrame {
                        function: COW_TRUNCATED,
                        file: None,
                        line: 0,
                    })?;
                    break;
                }
            }

            execute_data_ptr = execute_data.prev_execute_data;
        }
        Ok(samples)
    }

    unsafe fn collect_call_frame(execute_data: &zend_execute_data) -> Option<ZendFrame> {
        if let Some(func) = execute_data.func.as_ref() {
            let function = extract_function_name(func);
            let (file, line) = extract_file_and_line(execute_data);

            // Only create a new frame if there's file or function info.
            if file.is_some() || function.is_some() {
                // If there's no function name, use a fake name.
                let function = function.unwrap_or(COW_PHP_OPEN_TAG);
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
