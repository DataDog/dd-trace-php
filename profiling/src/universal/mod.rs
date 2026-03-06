//! Universal PHP version support via offset matrix and runtime symbol resolution.
//! Supports both extension= and zend_extension= load paths.

mod exception_message;
pub(crate) mod features;
mod matrix;
pub(crate) mod runtime;

pub use exception_message::exception_message;
pub use features::{
    has_fibers, has_gc_status, has_opcache_restart_hook, has_zend_error_observer,
    has_zend_mm_set_custom_handlers_ex, is_zts, zend_error_observer_has_cstr_filename,
};

#[cfg(test)]
pub use matrix::first_nts_entry;
pub use matrix::{find_entry, MatrixEntry};
pub use runtime::{
    build_executor_globals_cache, get_compiler_globals, get_sapi_globals, ExecutorGlobalsCache,
};

use crate::bindings::{
    datadog_php_zif_handler, datadog_php_zim_handler, zend_execute_data, zend_function, zval,
};
use core::ffi::{c_char, c_void};
use std::ffi::CStr;
use std::sync::atomic::AtomicBool;

/// VM interrupt address for the current thread. Universal-only; no fallback.
///
/// Returns the per-thread `&EG(vm_interrupt)` pointer cached during `ginit`.
/// Only call after ginit has run for this thread.
pub fn profiling_vm_interrupt_addr(php_thread: crate::OnPhpThread) -> *const AtomicBool {
    let globals = crate::module_globals::get_profiler_globals(php_thread);
    if globals.is_null() {
        return core::ptr::null();
    }
    unsafe { (*globals).vm_interrupt_ptr }
}

/// Active fiber for the current thread (EG(active_fiber)), or null if none / PHP < 8.1.
/// Only meaningful when has_fibers() returns true; checked by the caller to avoid
/// null deref on older PHP versions where the slot doesn't exist.
pub fn get_active_fiber(php_thread: crate::OnPhpThread) -> *mut core::ffi::c_void {
    let globals = crate::module_globals::get_profiler_globals(php_thread);
    if globals.is_null() {
        return core::ptr::null_mut();
    }
    let af_ptr = unsafe { (*globals).active_fiber_ptr };
    if af_ptr.is_null() {
        return core::ptr::null_mut();
    }
    unsafe { *af_ptr }
}

/// Current execute_data for this thread. Universal-only; no fallback.
/// Returns null when not in a request context.
pub fn profiling_current_execute_data(php_thread: crate::OnPhpThread) -> *mut zend_execute_data {
    let globals = crate::module_globals::get_profiler_globals(php_thread);
    if globals.is_null() {
        return core::ptr::null_mut();
    }
    let ced_ptr = unsafe { (*globals).current_execute_data_ptr };
    if ced_ptr.is_null() {
        return core::ptr::null_mut();
    }
    unsafe { *ced_ptr }
}

/// Parse build_id string to extract zts. "API420240924,TS" -> true, "API420240924,NTS" -> false.
pub fn build_id_is_zts(build_id: *const libc::c_char) -> bool {
    if build_id.is_null() {
        return false;
    }
    let s = unsafe { CStr::from_ptr(build_id as *const _) };
    s.to_bytes().windows(3).any(|w| w == b",TS")
}

/// Convert module build_id (API20240924,NTS) to extension build_id (API420240924,NTS)
/// and extension api_no. Returns (api_no, extension_build_id).
pub fn module_to_extension_api_and_build_id(module_build_id: &[u8]) -> Option<(i32, String)> {
    if module_build_id.len() < 12 || !module_build_id.starts_with(b"API") {
        return None;
    }
    let rest = &module_build_id[3..];
    let (digit, mult) = if rest.starts_with(b"202") || rest.starts_with(b"203") {
        (b'4', 400_000_000i32) // PHP 8.x
    } else if rest.starts_with(b"201") || rest.starts_with(b"200") {
        (b'3', 300_000_000i32) // PHP 7.x
    } else {
        return None;
    };
    let num_str = rest
        .iter()
        .take_while(|c| c.is_ascii_digit())
        .map(|&c| c as char)
        .collect::<String>();
    let module_api_no: i32 = num_str.parse().ok()?;
    let api_no = mult + module_api_no;

    let mut out = Vec::with_capacity(module_build_id.len() + 1);
    out.extend_from_slice(b"API");
    out.push(digit);
    out.extend_from_slice(rest);
    let ext_build_id = String::from_utf8(out).ok()?;
    Some((api_no, ext_build_id))
}

/// Installs a function handler into the global function table.
/// Ported from ext/handlers_api.c. Works across PHP 7.1–8.5.
pub fn install_handler(handler: datadog_php_zif_handler) {
    let entry = crate::matrix_entry();
    let cg = get_compiler_globals(entry);
    if cg.is_null() {
        return;
    }
    let ft_off = entry.offsets.cg_function_table;
    if ft_off < 0 {
        return;
    }
    let ft_ptr = unsafe { *(cg.add(ft_off as usize) as *const *mut c_void) };
    if ft_ptr.is_null() {
        return;
    }
    install_table_handler(ft_ptr, &handler, entry);
}

/// Installs a method handler into a class's function table.
/// Ported from ext/handlers_api.c. Works across PHP 7.1–8.5.
pub fn install_method_handler(handler: datadog_php_zim_handler) {
    let entry = crate::matrix_entry();
    let cg = get_compiler_globals(entry);
    if cg.is_null() {
        return;
    }
    let ct_off = entry.offsets.cg_class_table;
    if ct_off < 0 {
        return;
    }
    let ct_ptr = unsafe { *(cg.add(ct_off as usize) as *const *mut c_void) };
    if ct_ptr.is_null() {
        return;
    }
    let hash_find_sym = runtime::symbol_addr("zend_hash_str_find");
    if hash_find_sym.is_null() {
        return;
    }
    type ZendHashStrFindFn = unsafe extern "C" fn(*const c_void, *const c_char, usize) -> *mut zval;
    let hash_find: ZendHashStrFindFn = unsafe { core::mem::transmute(hash_find_sym) };
    let zv = unsafe { hash_find(ct_ptr, handler.class_name, handler.class_name_len) };
    if zv.is_null() {
        return;
    }
    let ce = unsafe { (*zv).value.ptr as *mut u8 };
    if ce.is_null() {
        return;
    }
    let ce_ft_off = entry.offsets.ce_function_table;
    if ce_ft_off < 0 {
        return;
    }
    let ce_ft = unsafe { ce.add(ce_ft_off as usize) as *mut c_void };
    install_table_handler(ce_ft, &handler.zif, entry);
}

fn install_table_handler(
    table: *mut c_void,
    handler: &datadog_php_zif_handler,
    entry: &MatrixEntry,
) {
    let hash_find_sym = runtime::symbol_addr("zend_hash_str_find");
    if hash_find_sym.is_null() {
        return;
    }
    type ZendHashStrFindFn = unsafe extern "C" fn(*const c_void, *const c_char, usize) -> *mut zval;
    let hash_find: ZendHashStrFindFn = unsafe { core::mem::transmute(hash_find_sym) };
    let zv = unsafe { hash_find(table, handler.name, handler.name_len) };
    if zv.is_null() {
        return;
    }
    let old_func = unsafe { (*zv).value.ptr as *mut zend_function };
    if old_func.is_null() {
        return;
    }
    let handler_off = entry.offsets.func_internal_handler;
    if handler_off < 0 {
        return;
    }
    let handler_ptr = unsafe {
        (old_func as *mut u8).add(handler_off as usize)
            as *mut Option<unsafe extern "C" fn(*mut zend_execute_data, *mut zval)>
    };
    if !handler.old_handler.is_null() {
        unsafe {
            *handler.old_handler = *handler_ptr;
        }
    }
    unsafe {
        *handler_ptr = handler.new_handler;
    }
}
