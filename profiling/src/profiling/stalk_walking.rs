use crate::bindings::{
    ddog_php_prof_zend_string_view, zend_execute_data, zend_function, ZEND_USER_FUNCTION,
};
use crate::string_table::StringTable;
use std::borrow::Cow;
use std::cell::{RefCell, RefMut};
use std::str::Utf8Error;

/// Used to help track the function run_time_cache hit rate. It glosses over
/// the fact that there are two cache slots used, and they don't have to be in
/// sync. However, they usually are, so we simplify.
#[cfg(php_run_time_cache)]
#[derive(Debug, Default)]
pub struct FunctionRunTimeCacheStats {
    hit: usize,
    missed: usize,
    not_applicable: usize,
}

#[cfg(php_run_time_cache)]
impl FunctionRunTimeCacheStats {
    pub fn hit_rate(&self) -> f64 {
        let denominator = (self.hit + self.missed + self.not_applicable) as f64;
        self.hit as f64 / denominator
    }
}

#[cfg(php_run_time_cache)]
thread_local! {
    static CACHED_STRINGS: RefCell<StringTable> = RefCell::new(StringTable::new());
    pub static FUNCTION_CACHE_STATS: RefCell<FunctionRunTimeCacheStats> = RefCell::new(Default::default())
}

/// # Safety
/// Must be called in Zend Extension activate.
#[inline]
pub unsafe fn activate_run_time_cache() {
    #[cfg(php_run_time_cache)]
    CACHED_STRINGS.with(|cell| cell.replace(StringTable::new()));
}

const COW_PHP_OPEN_TAG: Cow<str> = Cow::Borrowed("<?php");
const COW_TRUNCATED: Cow<str> = Cow::Borrowed("[truncated]");

#[cfg(feature = "timeline")]
pub const COW_EVAL: Cow<str> = Cow::Borrowed("[eval]");

#[derive(Default, Debug)]
pub struct ZendFrame {
    // Most tools don't like frames that don't have function names, so use a
    // fake name if you need to like "<?php".
    pub function: Cow<'static, str>,
    pub file: Option<String>,
    pub line: u32, // use 0 for no line info
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
pub unsafe fn extract_function_name(func: &zend_function) -> Option<String> {
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

#[cfg(php_run_time_cache)]
unsafe fn handle_file_cache_slot_helper(
    execute_data: &zend_execute_data,
    string_table: &mut RefMut<StringTable>,
    cache_slots: &mut [usize; 2],
) -> Option<String> {
    let file = if cache_slots[1] > 0 {
        let offset = cache_slots[1];
        let str = string_table.get_offset(offset);
        String::from(str)
    } else {
        // Safety: if we have cache slots, we definitely have a func.
        let func = &*execute_data.func;
        if func.type_ != ZEND_USER_FUNCTION as u8 {
            return None;
        };

        let file = ddog_php_prof_zend_string_view(func.op_array.filename.as_mut()).to_string();
        let offset = string_table.insert(file.as_ref());
        cache_slots[1] = offset;
        file
    };

    Some(file)
}

#[cfg(php_run_time_cache)]
unsafe fn handle_file_cache_slot(
    execute_data: &zend_execute_data,
    string_table: &mut RefMut<StringTable>,
    cache_slots: &mut [usize; 2],
) -> (Option<String>, u32) {
    match handle_file_cache_slot_helper(execute_data, string_table, cache_slots) {
        Some(filename) => {
            let lineno = match execute_data.opline.as_ref() {
                Some(opline) => opline.lineno,
                None => 0,
            };
            (Some(filename), lineno)
        }
        None => (None, 0),
    }
}

#[cfg(php_run_time_cache)]
unsafe fn handle_function_cache_slot(
    func: &zend_function,
    string_table: &mut RefMut<StringTable>,
    cache_slots: &mut [usize; 2],
) -> Option<Cow<'static, str>> {
    let fname = if cache_slots[0] > 0 {
        let offset = cache_slots[0];
        let str = string_table.get_offset(offset);
        str.to_string()
    } else {
        let name = extract_function_name(func)?;
        let offset = string_table.insert(name.as_ref());
        cache_slots[0] = offset;
        name
    };
    Some(Cow::Owned(fname))
}

unsafe fn extract_file_and_line(execute_data: &zend_execute_data) -> (Option<String>, u32) {
    // This should be Some, just being cautious.
    match execute_data.func.as_ref() {
        Some(func) if func.type_ == ZEND_USER_FUNCTION as u8 => {
            // Safety: ddog_php_prof_zend_string_view will return a valid ZaiStringView.
            let file = ddog_php_prof_zend_string_view(func.op_array.filename.as_mut()).to_string();
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
unsafe fn collect_call_frame(execute_data: &zend_execute_data) -> Option<ZendFrame> {
    #[cfg(not(feature = "stack_walking_tests"))]
    use crate::bindings::ddog_php_prof_function_run_time_cache;
    #[cfg(feature = "stack_walking_tests")]
    use crate::bindings::ddog_test_php_prof_function_run_time_cache as ddog_php_prof_function_run_time_cache;

    let func = execute_data.func.as_ref()?;
    CACHED_STRINGS.with(|cell| {
        let mut string_table = cell.borrow_mut();
        let (function, file, line) = match ddog_php_prof_function_run_time_cache(func) {
            Some(cache_slots) => {
                FUNCTION_CACHE_STATS.with(|cell| {
                    let mut stats = cell.borrow_mut();
                    if cache_slots[0] == 0 {
                        stats.missed += 1;
                    } else {
                        stats.hit += 1;
                    }
                });
                let function = handle_function_cache_slot(func, &mut string_table, cache_slots);
                let (file, line) =
                    handle_file_cache_slot(execute_data, &mut string_table, cache_slots);

                (function, file, line)
            }

            None => {
                FUNCTION_CACHE_STATS.with(|cell| {
                    let mut stats = cell.borrow_mut();
                    stats.not_applicable += 1;
                });
                let function = extract_function_name(func).map(Cow::Owned);
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
    })
}

#[cfg(not(php_run_time_cache))]
unsafe fn collect_call_frame(execute_data: &zend_execute_data) -> Option<ZendFrame> {
    if let Some(func) = execute_data.func.as_ref() {
        let function = extract_function_name(func);
        let (file, line) = extract_file_and_line(execute_data);

        // Only create a new frame if there's file or function info.
        if file.is_some() || function.is_some() {
            // If there's no function name, use a fake name.
            let function = function.map(Cow::Owned).unwrap_or(COW_PHP_OPEN_TAG);
            return Some(ZendFrame {
                function,
                file,
                line,
            });
        }
    }
    None
}

pub fn collect_stack_sample(
    top_execute_data: *mut zend_execute_data,
) -> Result<Vec<ZendFrame>, Utf8Error> {
    let max_depth = 512;
    let mut samples = Vec::with_capacity(max_depth >> 3);
    let mut execute_data_ptr = top_execute_data;

    while let Some(execute_data) = unsafe { execute_data_ptr.as_ref() } {
        if let Some(frame) = unsafe { collect_call_frame(execute_data) } {
            samples.push(frame);

            /* -1 to reserve room for the [truncated] message. In case the
             * backend and/or frontend have the same limit, without the -1
             * then ironically the [truncated] message would be truncated.
             */
            if samples.len() == max_depth - 1 {
                samples.push(ZendFrame {
                    function: COW_TRUNCATED,
                    file: None,
                    line: 0,
                });
                break;
            }
        }

        execute_data_ptr = execute_data.prev_execute_data;
    }
    Ok(samples)
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::bindings as zend;

    #[test]
    #[cfg(feature = "stack_walking_tests")]
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
