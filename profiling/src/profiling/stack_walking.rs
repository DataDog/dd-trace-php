use crate::bindings::{zend_execute_data, zend_function, zend_op, zend_op_array};
use crate::profiling::Backtrace;
use crate::vec_ext::VecExt;
use profiling_shm::{
    FunctionIndex, InternError, ShmRegion, StrRope5, FUNCTION_TRUNCATED, MAX_STR_LEN, STRING_EMPTY,
    STRING_OOM, STRING_PHP_OPEN_TAG, STRING_SUSPICIOUSLY_LONG_FILE, STRING_SUSPICIOUSLY_LONG_FN,
};

#[cfg(php_frameless)]
use crate::bindings::zend_flf_functions;

#[cfg(php_frameless)]
use crate::bindings::{
    ZEND_FRAMELESS_ICALL_0, ZEND_FRAMELESS_ICALL_1, ZEND_FRAMELESS_ICALL_2, ZEND_FRAMELESS_ICALL_3,
};

use crate::bindings as zend;

#[derive(Debug, Clone, Copy)]
pub struct ZendFrame {
    pub function: FunctionIndex,
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

/// Intern a zend_function into the SHM and store the FunctionIndex in
/// func->common.reserved[slot].
///
/// Function name format: `{module}|{class}::{method}` for internal methods,
/// `{class}::{method}` for user methods, `{module}|{fn}` for internal functions,
/// or just `{fn}` for user functions.
pub fn intern_function(shm: &ShmRegion, func: &zend_function) {
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
    let fn_idx = match shm.intern_function(name_idx, file_idx) {
        Ok(idx) => idx,
        Err(_) => return, // OOM or WouldBlock — leave slot NULL, fallback at walk time
    };

    // Store in reserved[slot].
    unsafe {
        zend::ddog_php_prof_set_function_index(
            func as *const zend_function as *mut zend_function,
            fn_idx.0,
        );
    }
}

/// Called from `op_array_handler` for freshly compiled op_arrays.
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

/// Read the FunctionIndex from func->reserved[slot].
/// A NULL slot (0) returns FunctionIndex(0) = FUNCTION_EMPTY, which is the correct
/// fallback for any function not yet interned into the SHM.
#[inline]
fn read_or_fallback(func: &zend_function) -> FunctionIndex {
    let mut idx: u32 = 0;
    unsafe { zend::ddog_php_prof_get_function_index(func as *const _, &mut idx) };
    FunctionIndex(idx)
}

unsafe fn collect_call_frame(execute_data: &zend_execute_data) -> Option<ZendFrame> {
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
                    return Some(ZendFrame { function, line: 0 });
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

    Some(ZendFrame { function, line })
}

/// # Safety
/// Must be called in Zend Extension activate.
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
                    samples.try_push(ZendFrame {
                        function: FUNCTION_TRUNCATED,
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

/// Legacy accessor for backwards compat with code that still uses
/// `extract_function_name`. This keeps the public API stable while the
/// migration to FunctionIndex-based frames is in progress.
pub fn extract_function_name(func: &zend_function) -> Option<std::borrow::Cow<'static, str>> {
    match extract_function_name_bytes(func) {
        FunctionNameBytes::TopLevel => None,
        FunctionNameBytes::TooLong => {
            Some(std::borrow::Cow::Borrowed("[suspiciously large string]"))
        }
        FunctionNameBytes::Bytes(bytes) => {
            let string = if core::str::from_utf8(&bytes).is_ok() {
                unsafe { String::from_utf8_unchecked(bytes) }
            } else {
                String::from_utf8_lossy(&bytes).into_owned()
            };
            Some(std::borrow::Cow::Owned(string))
        }
    }
}

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
    fn test_extract_function_name_at_limit() {
        unsafe {
            let func = ddog_php_test_create_fake_zend_function_with_name_len(MAX_STR_LEN);
            assert!(!func.is_null());

            let name = extract_function_name(&*func).expect("should extract name");
            assert_eq!(name.len(), MAX_STR_LEN);
            assert_ne!(name, "[suspiciously large string]");

            ddog_php_test_free_fake_zend_function(func);
        }
    }

    #[test]
    fn test_extract_function_name_over_limit() {
        unsafe {
            let func = ddog_php_test_create_fake_zend_function_with_name_len(MAX_STR_LEN + 1);
            assert!(!func.is_null());

            let name = extract_function_name(&*func).expect("should return large string marker");
            assert_eq!(name, "[suspiciously large string]");

            ddog_php_test_free_fake_zend_function(func);
        }
    }
}
