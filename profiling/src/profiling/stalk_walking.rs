use crate::bindings::{
    ddog_php_prof_zend_string_view, zend_execute_data, zend_function, zend_string,
    ZEND_USER_FUNCTION,
};
use std::str::Utf8Error;

#[derive(Default, Debug)]
pub struct ZendFrame {
    // Most tools don't like frames that don't have function names, so use a
    // fake name if you need to like "<php>".
    pub function: String,
    pub file: Option<String>,
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
unsafe fn extract_function_name(func: &zend_function) -> Option<String> {
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

unsafe fn collect_call_frame(execute_data: &zend_execute_data) -> Option<ZendFrame> {
    if let Some(func) = execute_data.func.as_ref() {
        let function = extract_function_name(func);
        let (file, line) = extract_file_and_line(execute_data);

        // Only create a new frame if there's file or function info.
        if file.is_some() || function.is_some() {
            // If there's no function name, use a fake name.
            let function = function.unwrap_or_else(|| "<?php".to_owned());
            return Some(ZendFrame {
                function,
                file,
                line,
            });
        }
    }
    None
}

pub(super) unsafe fn collect_stack_sample(
    top_execute_data: *mut zend_execute_data,
) -> Result<Vec<ZendFrame>, Utf8Error> {
    let max_depth = 512;
    let mut samples = Vec::with_capacity(max_depth >> 3);
    let mut execute_data_ptr = top_execute_data;

    while let Some(execute_data) = execute_data_ptr.as_ref() {
        if let Some(frame) = collect_call_frame(execute_data) {
            samples.push(frame);

            /* -1 to reserve room for the [truncated] message. In case the
             * backend and/or frontend have the same limit, without the -1
             * then ironically the [truncated] message would be truncated.
             */
            if samples.len() == max_depth - 1 {
                samples.push(ZendFrame {
                    function: "[truncated]".to_string(),
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
            assert_eq!(stack[0].file, Some("filename-003.php".to_string()));
            assert_eq!(stack[0].line, 0);

            assert_eq!(stack[1].function, "function name 002");
            assert_eq!(stack[1].file, Some("filename-002.php".to_string()));
            assert_eq!(stack[1].line, 0);

            assert_eq!(stack[2].function, "function name 001");
            assert_eq!(stack[2].file, Some("filename-001.php".to_string()));
            assert_eq!(stack[2].line, 0);

            // Free the allocated memory
            zend::ddog_php_test_free_fake_zend_execute_data(fake_execute_data);
        }
    }
}
