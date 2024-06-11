use crate::bindings::{zai_str_from_zstr, zend_execute_data, zend_function, ZEND_USER_FUNCTION};
use std::borrow::Cow;
use std::str::Utf8Error;

#[derive(Clone, Copy, Debug)]
pub enum StaticNames {
    Empty,
    Eval,
    Gc,
    Idle,
    Include,
    PhpOpenTag,
    Require,
    Truncated,
    UnknownIncludeType,
}

impl AsRef<str> for StaticNames {
    fn as_ref(&self) -> &str {
        match self {
            StaticNames::Empty => "",
            StaticNames::Eval => "[eval]",
            StaticNames::Gc => "[gc]",
            StaticNames::Idle => "[idle]",
            StaticNames::Include => "[include]",
            StaticNames::PhpOpenTag => "<?php",
            StaticNames::Require => "[require]",
            StaticNames::Truncated => "[truncated]",
            StaticNames::UnknownIncludeType => "[unknown include type]",
        }
    }
}

impl Default for StaticNames {
    fn default() -> Self {
        Self::Empty
    }
}

#[derive(Default, Debug)]
pub struct ZendFrame {
    // This field is logically required. If it's None, look at extra.
    pub function: Option<ThinString>,
    pub file: Option<ThinString>,
    pub line: u32, // use 0 for no line info
    pub extra: StaticNames,
}

impl ZendFrame {
    fn new_from_stack_collection(
        function: Option<ThinString>,
        file: Option<ThinString>,
        line: u32,
    ) -> Option<Self> {
        if function.is_some() || file.is_some() {
            let extra = if function.is_none() {
                StaticNames::PhpOpenTag
            } else {
                StaticNames::Empty
            };
            Some(Self {
                function,
                file,
                line,
                extra,
            })
        } else {
            None
        }
    }
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
pub fn extract_function_name(func: &zend_function) -> Option<String> {
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

    Some(String::from_utf8_lossy(buffer.as_slice()).into_owned())
}

unsafe fn extract_file_and_line(execute_data: &zend_execute_data) -> (Option<Cow<str>>, u32) {
    // This should be Some, just being cautious.
    match execute_data.func.as_ref() {
        Some(func) if func.type_ == ZEND_USER_FUNCTION as u8 => {
            // Safety: zai_str_from_zstr will return a valid ZaiStr.
            let file = zai_str_from_zstr(func.op_array.filename.as_mut()).into_string_lossy();
            let lineno = match execute_data.opline.as_ref() {
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
    use log::{debug, trace};
    use std::cell::RefCell;
    use std::ptr::NonNull;

    struct StringCache<'a> {
        /// The frame in question.
        frame: &'a zend_execute_data,

        /// Frame's run time cache reserved by this extension.
        cache_slots: &'a mut [usize; 2],

        /// Refers to the string set in the thread-local storage.
        string_set: &'a mut StringSet,
    }

    impl<'a> StringCache<'a> {
        /// Makes a copy of the string in the cache slot. If there isn't a
        /// string in the slot currently, then create one by calling the
        /// provided function, store it in the string cache and cache slot,
        /// and return it.
        fn get_or_insert<F>(&mut self, slot: usize, f: F) -> Option<Cow<'a, str>>
        where
            F: FnOnce(&'a zend_execute_data) -> Option<Cow<'a, str>>,
        {
            debug_assert!(slot < self.cache_slots.len());
            let cached = unsafe { self.cache_slots.get_unchecked_mut(slot) };

            let ptr = *cached as *mut u8;
            match NonNull::new(ptr) {
                Some(non_null) => {
                    // SAFETY: transmuting ThinStr from its repr.
                    let thin_str: ThinStr<'a> = unsafe { core::mem::transmute(non_null) };
                    let str: &'a str = thin_str.into();
                    Some(Cow::Borrowed(str))
                }
                None => {
                    let string = f(self.frame)?;
                    let thin_str = self.string_set.insert(&string);
                    // SAFETY: transmuting ThinStr into its repr.
                    let non_null: NonNull<u8> = unsafe { core::mem::transmute(thin_str) };
                    *cached = non_null.as_ptr() as usize;
                    Some(string)
                }
            }
        }
    }

    /// Used to help track the function run_time_cache hit rate. It glosses over
    /// the fact that there are two cache slots used, and they don't have to be in
    /// sync. However, they usually are, so we simplify.
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
        FUNCTION_CACHE_STATS.with(|cell| {
            let stats = cell.borrow();
            let hit_rate = stats.hit_rate();
            debug!("Process cumulative {stats:?} hit_rate: {hit_rate}");
        });

        CACHED_STRINGS.with(|cell| {
            let set: &StringSet = &cell.borrow();
            let arena_used_bytes = set.arena_used_bytes();
            // A slow ramp up to 2 MiB is probably _not_ going to look like
            // a memory leak, whereas a higher threshold could make a user
            // suspect a leak.
            let threshold = 2 * 1024 * 1024;
            if arena_used_bytes > threshold {
                debug!("string cache arena is using {arena_used_bytes} bytes which exceeds the {threshold} byte threshold, resetting");
                // Note that this cannot be done _during_ a request. The
                // ThinStrs inside the run time cache need to remain valid
                // during the request.
                cell.replace(StringSet::new());
            } else {
                trace!("string cache arena is using {arena_used_bytes} bytes which is less than the {threshold} byte threshold");
            }
        });
    }

    #[inline(never)]
    pub fn collect_stack_sample(
        top_execute_data: *mut zend_execute_data,
    ) -> Result<Vec<ZendFrame>, Utf8Error> {
        CACHED_STRINGS.with(|cell| {
            let string_set: &mut StringSet = &mut cell.borrow_mut();
            let max_depth = 512;
            let mut samples = Vec::with_capacity(max_depth >> 3);
            let mut execute_data_ptr = top_execute_data;

            while let Some(execute_data) = unsafe { execute_data_ptr.as_ref() } {
                let maybe_frame = unsafe { collect_call_frame(execute_data, string_set) };
                if let Some(frame) = maybe_frame {
                    samples.push(frame);

                    /* -1 to reserve room for the [truncated] message. In case the
                     * backend and/or frontend have the same limit, without the -1
                     * then ironically the [truncated] message would be truncated.
                     */
                    if samples.len() == max_depth - 1 {
                        samples.push(ZendFrame {
                            function: None,
                            file: None,
                            line: 0,
                            extra: StaticNames::Truncated,
                        });
                        break;
                    }
                }

                execute_data_ptr = execute_data.prev_execute_data;
            }
            Ok(samples)
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
            Some(cache_slots) => {
                let mut string_cache = StringCache {
                    frame: execute_data,
                    cache_slots,
                    string_set,
                };
                let function = handle_function_cache_slot(&mut string_cache);
                let (file, line) = handle_file_cache_slot(&mut string_cache);
                let cache_slots = string_cache.cache_slots;
                FUNCTION_CACHE_STATS.with(|cell| {
                    let mut stats = cell.borrow_mut();
                    if cache_slots[0] == 0 {
                        stats.missed += 1;
                    } else {
                        stats.hit += 1;
                    }
                });

                (function, file, line)
            }

            None => {
                FUNCTION_CACHE_STATS.with(|cell| {
                    let mut stats = cell.borrow_mut();
                    stats.not_applicable += 1;
                });
                let function = extract_function_name(func).as_deref().map(ThinString::from);
                let (file, line) = extract_file_and_line(execute_data);
                let file = file.as_deref().map(ThinString::from);
                (function, file, line)
            }
        };

        ZendFrame::new_from_stack_collection(function, file, line)
    }

    fn handle_function_cache_slot(string_cache: &mut StringCache) -> Option<ThinString> {
        string_cache
            .get_or_insert(0, |frame| {
                let name = unsafe { extract_function_name(&*frame.func) };
                name.map(Cow::Owned)
            })
            .as_deref()
            .map(ThinString::from)
    }

    unsafe fn handle_file_cache_slot(string_cache: &mut StringCache) -> (Option<ThinString>, u32) {
        let option = string_cache.get_or_insert(1, |frame| -> Option<Cow<str>> {
            unsafe {
                // Safety: if we have cache slots, we definitely have a func.
                let func = &*frame.func;
                // Safety: this union member is always valid.
                if func.type_ != ZEND_USER_FUNCTION as u8 {
                    return None;
                };

                // SAFETY: calling C function with correct args.
                let file = zai_str_from_zstr(func.op_array.filename.as_mut()).into_string_lossy();
                Some(file)
            }
        });
        match option {
            Some(filename) => {
                // SAFETY: if there's a file, then there should be an opline.
                let lineno = match string_cache.frame.opline.as_ref() {
                    Some(opline) => opline.lineno,
                    None => 0,
                };
                (Some(ThinString::from(filename.as_ref())), lineno)
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
    ) -> Result<Vec<ZendFrame>, Utf8Error> {
        let max_depth = 512;
        let mut samples = Vec::with_capacity(max_depth >> 3);
        let mut execute_data_ptr = top_execute_data;

        while let Some(execute_data) = unsafe { execute_data_ptr.as_ref() } {
            let maybe_frame = unsafe { collect_call_frame(execute_data) };
            if let Some(frame) = maybe_frame {
                samples.push(frame);

                /* -1 to reserve room for the [truncated] message. In case the
                 * backend and/or frontend have the same limit, without the -1
                 * then ironically the [truncated] message would be truncated.
                 */
                if samples.len() == max_depth - 1 {
                    samples.push(ZendFrame {
                        function: None,
                        file: None,
                        line: 0,
                        extra: StaticNames::Truncated,
                    });
                    break;
                }
            }

            execute_data_ptr = execute_data.prev_execute_data;
        }
        Ok(samples)
    }

    unsafe fn collect_call_frame(execute_data: &zend_execute_data) -> Option<ZendFrame> {
        if let Some(func) = execute_data.func.as_ref() {
            let function = extract_function_name(func).as_deref().map(ThinString::from);
            let (file, line) = extract_file_and_line(execute_data);
            let file = file.as_deref().map(ThinString::from);
            ZendFrame::new_from_stack_collection(function, file, line)
        } else {
            None
        }
    }
}

use crate::thin_str::ThinString;
pub use detail::*;

#[cfg(test)]
mod tests {
    use super::*;
    use core::mem::size_of;

    #[test]
    fn test_frame_size() {
        let word_size = size_of::<*mut ()>();
        assert_eq!(size_of::<ZendFrame>(), word_size * 3);
    }
}

#[cfg(all(test, stack_walking_tests))]
mod stack_walking_tests {
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
