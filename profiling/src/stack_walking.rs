use crate::bindings;
use crate::bindings::{
    zend_class_entry, zend_execute_data, zend_function, zend_object, ZEND_INTERNAL_FUNCTION,
    ZEND_USER_FUNCTION,
};
use crate::profiling::KNOWN;
use datadog_profiling::profile::v2;
use std::ffi::CStr;
use std::os::raw::c_char;
use std::sync::atomic::{AtomicU64, Ordering};
use std::sync::MutexGuard;

static NULL: &[u8] = b"\0";

pub static POLYMORPHIC_CACHE_MISSES: AtomicU64 = AtomicU64::new(0);
pub static POLYMORPHIC_CACHE_HITS: AtomicU64 = AtomicU64::new(0);

unsafe fn get_func_name(func: &zend_function) -> &[u8] {
    let ptr = if func.common.function_name.is_null() {
        NULL.as_ptr() as *const c_char
    } else {
        let zstr = &*func.common.function_name;
        if zstr.len == 0 {
            NULL.as_ptr() as *const c_char
        } else {
            zstr.val.as_ptr() as *const c_char
        }
    };

    // CStr::to_bytes does not contain the trailing null byte
    CStr::from_ptr(ptr).to_bytes()
}

unsafe fn get_class_name(class: &zend_class_entry) -> &[u8] {
    let ptr = if class.name.is_null() {
        NULL.as_ptr() as *const c_char
    } else {
        let zstr = &*class.name;
        if zstr.len == 0 {
            NULL.as_ptr() as *const c_char
        } else {
            zstr.val.as_ptr() as *const c_char
        }
    };

    // CStr::to_bytes does not contain the trailing null byte
    CStr::from_ptr(ptr).to_bytes()
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
    string_table: &mut MutexGuard<v2::StringTable>,
    func: &zend_function,
    class_entry: *const zend_class_entry,
) -> i64 {
    let method_name: &[u8] = get_func_name(func);

    /* The top of the stack seems to reasonably often not have a function, but
     * still has a scope. I don't know if this intentional, or if it's more of
     * a situation where scope is only valid if the func is present. So, I'm
     * erring on the side of caution and returning early.
     */
    if method_name.is_empty() {
        return 0;
    }

    let mut buffer = Vec::<u8>::new();

    // User functions do not have a "module". Maybe one day use composer info?
    if func.type_ == ZEND_INTERNAL_FUNCTION as u8
        && !func.internal_function.module.is_null()
        && !(*func.internal_function.module).name.is_null()
    {
        let ptr = (*func.internal_function.module).name as *const c_char;
        let bytes = CStr::from_ptr(ptr).to_bytes();
        if !bytes.is_empty() {
            buffer.extend_from_slice(bytes);
            buffer.push(b'|');
        }
    }

    if !class_entry.is_null() {
        let class_name = get_class_name(&*class_entry);
        if !class_name.is_empty() {
            buffer.extend_from_slice(class_name);
            buffer.extend_from_slice(b"::");
        }
    }

    buffer.extend_from_slice(method_name);

    let s = String::from_utf8_lossy(buffer.as_slice());
    string_table.intern(s)
}

unsafe fn extract_filename_and_start_line(
    storage: &mut MutexGuard<v2::StringTable>,
    execute_data: &zend_execute_data,
) -> (i64, i64) {
    // this is supposed to be verified by the caller
    if execute_data.func.is_null() {
        return (0, 0);
    }

    let func = &*execute_data.func;
    if func.type_ == ZEND_USER_FUNCTION as u8 {
        let op_array = &func.op_array;
        if op_array.filename.is_null() {
            (0, 0)
        } else {
            let zstr = &*op_array.filename;
            if zstr.len == 0 {
                (0, 0)
            } else {
                let cstr = CStr::from_ptr(zstr.val.as_ptr() as *const c_char).to_bytes();
                let filename = storage.intern(String::from_utf8_lossy(cstr));
                (filename, op_array.line_start.into())
            }
        }
    } else {
        (0, 0)
    }
}

unsafe fn extract_line_no(execute_data: &zend_execute_data) -> u32 {
    // this is supposed to be verified by the caller
    assert!(!execute_data.func.is_null());

    let func = &*execute_data.func;
    if func.type_ == ZEND_USER_FUNCTION as u8 && !execute_data.opline.is_null() {
        let opline = &*execute_data.opline;
        return opline.lineno;
    }
    0
}

unsafe fn cached_polymorphic_id(
    func: *mut zend_function,
    ce: *mut zend_class_entry,
) -> Option<u64> {
    let ptr = bindings::datadog_php_profiling_cached_polymorphic_ptr(func, ce);
    if ptr == 0 {
        None
    } else {
        Some(ptr.try_into().unwrap())
    }
}

unsafe fn cache_polymorphic_id(func: *mut zend_function, ce: *mut zend_class_entry, id: u64) {
    let ptr = id.try_into().unwrap();
    bindings::datadog_php_profiling_cache_polymorphic_ptr(func, ce, ptr)
}

/// Collects the stack into a vec. If the maximum depth is hit, it will add a
/// frame that indicates this. It skips unrecognized frames.
pub unsafe fn collect(top_execute_data: *mut zend_execute_data) -> Vec<v2::Line> {
    let max_depth = 512;
    let mut samples = Vec::with_capacity(max_depth >> 3);
    let mut execute_data = top_execute_data;

    let php_no_func = KNOWN.strings.php_no_func;

    while !execute_data.is_null() {
        /* -1 to reserve room for the [truncated] message. In case the backend
         * and/or frontend have the same limit, without the -1 we'd ironically
         * truncate our [truncated] message.
         */
        if samples.len() >= max_depth - 1 {
            samples.push(v2::Line {
                function_id: KNOWN.functions.truncated,
                line_number: 0,
            });
            break;
        }

        if !(*execute_data).func.is_null() {
            let func = (*execute_data).func;
            let this: Result<&mut zend_object, _> = (&mut (*execute_data).This).try_into();
            let ce = this.map(|obj| obj.ce).unwrap_or(std::ptr::null_mut());
            let cache = cached_polymorphic_id(func, ce);

            let function_id = match cache {
                None => {
                    POLYMORPHIC_CACHE_MISSES.fetch_add(1, Ordering::SeqCst);
                    // Failed to get a value from the polymorphic cache, so extract all the info.
                    let mut string_table = KNOWN.string_table.lock();
                    let mut name = extract_function_name(&mut string_table, &*func, ce);
                    let (filename, start_line) =
                        extract_filename_and_start_line(&mut string_table, &*execute_data);
                    drop(string_table);

                    // Only insert a new function if there's file or function info.
                    if name.is_positive() || filename.is_positive() {
                        // If there's no function name, use a fake name
                        if name == 0 {
                            name = php_no_func;
                        }
                        let id = KNOWN.symbol_table.add(v2::Function {
                            id: 0, // id will be overwritten by the symbol table
                            name,
                            system_name: 0,
                            filename,
                            start_line, // currently unused by any DD tooling
                        });
                        cache_polymorphic_id(func, ce, id);
                        id
                    } else {
                        0
                    }
                }
                Some(id) => {
                    POLYMORPHIC_CACHE_HITS.fetch_add(1, Ordering::SeqCst);
                    id
                }
            };

            // It would be pointless to add a line into "no function".
            if function_id > 0 {
                let line_number: i64 = extract_line_no(&*execute_data).into();
                let line = v2::Line {
                    function_id,
                    line_number,
                };
                samples.push(line);
            }
        }

        execute_data = (*execute_data).prev_execute_data;
    }

    // debug!("Samples: {:#?}", samples);
    samples
}
