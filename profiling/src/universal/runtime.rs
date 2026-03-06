//! Runtime symbol resolution and offset-based struct access for universal PHP support.
//! Used when a matrix entry is found; falls back to C/FFI when not.

use crate::universal::matrix::MatrixEntry;
use libc::{c_void, dlsym, RTLD_DEFAULT};
use std::ffi::CString;
use std::ptr;

type TsrmGetLsCacheFn = unsafe extern "C" fn() -> *mut c_void;

/// Resolve a symbol from the PHP engine (or main executable).
pub fn symbol_addr(name: &str) -> *mut c_void {
    let c = match CString::new(name) {
        Ok(s) => s,
        Err(_) => return ptr::null_mut(),
    };
    // SAFETY: dlsym with RTLD_DEFAULT and NUL-terminated name.
    unsafe { dlsym(RTLD_DEFAULT, c.as_ptr()) }
}

/// Cached symbol resolution for executor_globals. Built once at module load.
pub enum ExecutorGlobalsCache {
    NtsDirect(*mut u8),
    ZtsFastOffset {
        tsrm_get_ls_cache: TsrmGetLsCacheFn,
        executor_globals_offset: usize,
    },
    ZtsId {
        tsrm_get_ls_cache: TsrmGetLsCacheFn,
        executor_globals_id: i32,
    },
}

/// Build cache for executor_globals. Resolves symbols once; returns None if any symbol is missing.
pub fn build_executor_globals_cache(entry: &MatrixEntry) -> Option<ExecutorGlobalsCache> {
    match entry.globals_mode {
        "nts_direct" => {
            let eg = symbol_addr("executor_globals") as *mut u8;
            if eg.is_null() {
                return None;
            }
            Some(ExecutorGlobalsCache::NtsDirect(eg))
        }
        "zts_fast_offset" => {
            let tsrm_sym = symbol_addr("tsrm_get_ls_cache");
            let offset_sym = symbol_addr("executor_globals_offset");
            if tsrm_sym.is_null() || offset_sym.is_null() {
                return None;
            }
            let tsrm_get_ls_cache: TsrmGetLsCacheFn = unsafe { std::mem::transmute(tsrm_sym) };
            let executor_globals_offset = unsafe { *(offset_sym as *const usize) };
            Some(ExecutorGlobalsCache::ZtsFastOffset {
                tsrm_get_ls_cache,
                executor_globals_offset,
            })
        }
        "zts_id" => {
            let tsrm_sym = symbol_addr("tsrm_get_ls_cache");
            let id_sym = symbol_addr("executor_globals_id");
            if tsrm_sym.is_null() || id_sym.is_null() {
                return None;
            }
            let tsrm_get_ls_cache: TsrmGetLsCacheFn = unsafe { std::mem::transmute(tsrm_sym) };
            let executor_globals_id = unsafe { *(id_sym as *const i32) };
            Some(ExecutorGlobalsCache::ZtsId {
                tsrm_get_ls_cache,
                executor_globals_id,
            })
        }
        _ => None,
    }
}

impl ExecutorGlobalsCache {
    /// Get executor_globals pointer using the cached symbols.
    pub fn get(&self) -> *mut u8 {
        match self {
            ExecutorGlobalsCache::NtsDirect(eg) => *eg,
            ExecutorGlobalsCache::ZtsFastOffset {
                tsrm_get_ls_cache,
                executor_globals_offset,
            } => unsafe {
                let ls = tsrm_get_ls_cache();
                if ls.is_null() {
                    return ptr::null_mut();
                }
                (ls as *mut u8).add(*executor_globals_offset)
            },
            ExecutorGlobalsCache::ZtsId {
                tsrm_get_ls_cache,
                executor_globals_id,
            } => unsafe {
                let ls = tsrm_get_ls_cache();
                if ls.is_null() {
                    return ptr::null_mut();
                }
                let idx = executor_globals_id - 1; // TSRM_UNSHUFFLE_RSRC_ID
                if idx < 0 {
                    return ptr::null_mut();
                }
                let storage = ls as *mut *mut *mut c_void;
                let array = *storage;
                *array.add(idx as usize) as *mut u8
            },
        }
    }
}

/// Get sapi_globals pointer. Uses same TSRM pattern as executor_globals.
pub fn get_sapi_globals(entry: &MatrixEntry) -> *mut u8 {
    match entry.globals_mode {
        "nts_direct" => {
            let sg = symbol_addr("sapi_globals") as *mut u8;
            if sg.is_null() {
                return ptr::null_mut();
            }
            sg
        }
        "zts_fast_offset" | "zts_id" => {
            let tsrm_sym = symbol_addr("tsrm_get_ls_cache");
            let offset_sym = symbol_addr("sapi_globals_offset");
            if tsrm_sym.is_null() || offset_sym.is_null() {
                return ptr::null_mut();
            }
            let tsrm_get_ls_cache: TsrmGetLsCacheFn = unsafe { std::mem::transmute(tsrm_sym) };
            let sapi_globals_offset = unsafe { *(offset_sym as *const usize) };
            let ls = unsafe { tsrm_get_ls_cache() };
            if ls.is_null() {
                return ptr::null_mut();
            }
            unsafe { (ls as *mut u8).add(sapi_globals_offset) }
        }
        _ => ptr::null_mut(),
    }
}

/// Get compiler_globals pointer. Uses same TSRM pattern as executor_globals.
pub fn get_compiler_globals(entry: &MatrixEntry) -> *mut u8 {
    match entry.globals_mode {
        "nts_direct" => {
            let cg = symbol_addr("compiler_globals") as *mut u8;
            if cg.is_null() {
                return ptr::null_mut();
            }
            cg
        }
        "zts_fast_offset" | "zts_id" => {
            let tsrm_sym = symbol_addr("tsrm_get_ls_cache");
            let offset_sym = symbol_addr("compiler_globals_offset");
            if tsrm_sym.is_null() || offset_sym.is_null() {
                return ptr::null_mut();
            }
            let tsrm_get_ls_cache: TsrmGetLsCacheFn = unsafe { std::mem::transmute(tsrm_sym) };
            let compiler_globals_offset = unsafe { *(offset_sym as *const usize) };
            let ls = unsafe { tsrm_get_ls_cache() };
            if ls.is_null() {
                return ptr::null_mut();
            }
            unsafe { (ls as *mut u8).add(compiler_globals_offset) }
        }
        _ => ptr::null_mut(),
    }
}
