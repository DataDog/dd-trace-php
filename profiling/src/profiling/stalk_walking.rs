use crate::bindings::{
    ddog_php_prof_zend_call_arg, ddog_php_prof_zend_string_view, zend_execute_data, zend_function,
    zend_string, zval, StringError, ZEND_USER_FUNCTION,
};
use crate::string_table::{OwnedStringTable, StringTable};
use std::borrow::Cow;
use std::cell::{RefCell, RefMut};
use std::mem::transmute;
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
    static CACHED_STRINGS: RefCell<OwnedStringTable> = RefCell::new(OwnedStringTable::new());
    pub static FUNCTION_CACHE_STATS: RefCell<FunctionRunTimeCacheStats> = RefCell::new(Default::default())
}

/// # Safety
/// Must be called in Zend Extension activate.
#[inline]
pub unsafe fn activate_run_time_cache() {
    #[cfg(php_run_time_cache)]
    CACHED_STRINGS.with(|cell| cell.replace(OwnedStringTable::new()));
}

#[derive(Default, Debug)]
pub struct ZendFrame {
    // Most tools don't like frames that don't have function names, so use a
    // fake name if you need to like "<?php".
    pub function: Cow<'static, str>,
    pub file: Option<Cow<'static, str>>,
    pub line: u32, // use 0 for no line info
}

// todo: dedup
unsafe fn zend_string_to_bytes(zstr: Option<&mut zend_string>) -> &[u8] {
    ddog_php_prof_zend_string_view(zstr).into_bytes()
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
unsafe fn extract_function_name(
    execute_data: &zend_execute_data,
    func: &zend_function,
) -> Option<String> {
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

    add_parameters(
        &mut buffer,
        execute_data,
        func,
        module_name,
        class_name,
        method_name,
    );

    Some(String::from_utf8_lossy(buffer.as_slice()).into_owned())
}

unsafe fn arg0(execute_data: &zend_execute_data) -> Option<&mut zval> {
    ddog_php_prof_zend_call_arg(execute_data, 0).as_mut()
}

unsafe fn interesting_arg0(
    func: &zend_function,
    module_name: &[u8],
    class_name: &[u8],
    method_name: &[u8],
) -> Option<(&'static str, &'static str)> {
    // Short-circuit since none of these match any cases currently
    if !class_name.is_empty() || !module_name.is_empty() || func.common.required_num_args < 1 {
        return None;
    }

    const DO_ACTION: &'static str = "do_action";
    const DO_ACTION_REF_ARRAY: &'static str = "do_action_ref_array";
    const HOOK_NAME: &'static str = "hook_name";

    match std::str::from_utf8(method_name) {
        Ok(method_name) => {
            // convert it into the known static string
            let method_name = match method_name {
                DO_ACTION => DO_ACTION,
                DO_ACTION_REF_ARRAY => DO_ACTION_REF_ARRAY,
                _ => return None,
            };
            let param0_name = func
                .common
                .arg_info
                .as_ref()
                .map(|arg_info| ddog_php_prof_zend_string_view(arg_info.name.as_mut()).into_bytes())
                .unwrap_or(b"");

            match std::str::from_utf8(param0_name) {
                Ok(HOOK_NAME) => return Some((method_name, HOOK_NAME)),
                Ok(name) => log::trace!("'{method_name}' is likely not WordPress's: parameter 0 has name '{name}' instead of 'hook_name'"),
                Err(err) => log::warn!("encountered invalid utf-8 string in parameter 0 of {method_name}: {err}"),
            }
        }
        _ => {}
    }

    return None;
}

unsafe fn add_parameters(
    buffer: &mut Vec<u8>,
    execute_data: &zend_execute_data,
    func: &zend_function,
    module_name: &[u8],
    class_name: &[u8],
    method_name: &[u8],
) {
    if let Some((method_name, param0)) =
        interesting_arg0(func, module_name, class_name, method_name)
    {
        match arg0(execute_data) {
            Some(arg0) => {
                let arg0_str: Result<String, _> = String::try_from(arg0);
                match arg0_str {
                    Ok(arg0_str) => {
                        use std::io::Write;
                        // writes to Vecs always succeed
                        _ = write!(buffer, "({param0}: '{arg0_str}')");
                    }
                    Err(err) => {
                        let error = match err {
                            StringError::Null => Cow::Borrowed(
                                "zval type was string, but the string pointer was null",
                            ),
                            StringError::Type(t) => {
                                Cow::Owned(format!("expected zval type string, found {t}"))
                            }
                        };
                        log::warn!("failed to get arg 0 of '{method_name}': {error}");
                    }
                }
            }
            None => {
                log::warn!("failed to get arg 0 of '{method_name}': couldn't locate zval")
            }
        }
    }
}

unsafe fn handle_file_cache_slot_helper(
    execute_data: &zend_execute_data,
    string_table: &mut RefMut<OwnedStringTable>,
    cache_slots: &mut [usize; 2],
) -> Option<Cow<'static, str>> {
    let offset = if cache_slots[1] > 0 {
        cache_slots[1]
    } else {
        // Safety: if we have cache slots, we definitely have a func.
        let func = &*execute_data.func;
        let file = if func.type_ == ZEND_USER_FUNCTION as u8 {
            let bytes = zend_string_to_bytes(func.op_array.filename.as_mut());
            String::from_utf8_lossy(bytes)
        } else {
            return None;
        };
        let offset = string_table.insert(file.as_ref());
        cache_slots[1] = offset;
        offset
    };
    let str = string_table.get_offset(offset);

    // Safety: changing the lifetime to 'static is safe because
    // the other threads using it are joined before this thread
    // ever dies.
    // todo: this is _not_ ZTS safe.
    Some(Cow::Borrowed(transmute(str)))
}

unsafe fn handle_file_cache_slot(
    execute_data: &zend_execute_data,
    string_table: &mut RefMut<OwnedStringTable>,
    cache_slots: &mut [usize; 2],
) -> (Option<Cow<'static, str>>, u32) {
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

unsafe fn handle_function_cache_slot(
    execute_data: &zend_execute_data,
    func: &zend_function,
    string_table: &mut RefMut<OwnedStringTable>,
    cache_slots: &mut [usize; 2],
) -> Option<Cow<'static, str>> {
    if cache_slots[0] > 0 {
        let offset = cache_slots[0];
        let str = string_table.get_offset(offset);
        Some(Cow::Owned(str.to_string()))
    } else {
        let name = extract_function_name(execute_data, func)?;
        let offset = string_table.insert(name.as_ref());
        cache_slots[0] = offset;
        Some(Cow::Owned(name))
    }
}

unsafe fn extract_file_and_line(execute_data: &zend_execute_data) -> (Option<String>, u32) {
    // This should be Some, just being cautious.
    match execute_data.func.as_ref() {
        Some(func) if func.type_ == ZEND_USER_FUNCTION as u8 => {
            let bytes = zend_string_to_bytes(func.op_array.filename.as_mut());
            let file = String::from_utf8_lossy(bytes).to_string();
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
                let function =
                    handle_function_cache_slot(execute_data, func, &mut string_table, cache_slots);
                let (file, line) =
                    handle_file_cache_slot(execute_data, &mut string_table, cache_slots);

                (function, file, line)
            }

            None => {
                FUNCTION_CACHE_STATS.with(|cell| {
                    let mut stats = cell.borrow_mut();
                    stats.not_applicable += 1;
                });
                let function = extract_function_name(execute_data, func).map(Cow::Owned);
                let (file, line) = extract_file_and_line(execute_data);
                let file = file.map(Cow::Owned);
                (function, file, line)
            }
        };

        if function.is_some() || file.is_some() {
            Some(ZendFrame {
                function: function.unwrap_or(Cow::Borrowed("<?php")),
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
        let function = extract_function_name(execute_data, func);
        let (file, line) = extract_file_and_line(execute_data);

        // Only create a new frame if there's file or function info.
        if file.is_some() || function.is_some() {
            // If there's no function name, use a fake name.
            let function = function.map(Cow::Owned).unwrap_or_else(|| "<?php".into());
            return Some(ZendFrame {
                function,
                file: file.map(Cow::Owned),
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
                    function: "[truncated]".into(),
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
