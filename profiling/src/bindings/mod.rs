mod ffi;

pub use ffi::*;
use libc::{c_char, c_int, c_uchar, c_uint, c_ushort, c_void, size_t};
use std::ffi::{CStr, CString};
use std::sync::atomic::AtomicBool;

pub type VmInterruptFn = unsafe extern "C" fn(execute_data: *mut zend_execute_data);

pub type VmMmCustomAllocFn = unsafe extern "C" fn(u64) -> *mut libc::c_void;
pub type VmMmCustomReallocFn = unsafe extern "C" fn(*mut libc::c_void, u64) -> *mut libc::c_void;
pub type VmMmCustomFreeFn = unsafe extern "C" fn(*mut libc::c_void);

// todo: this a lie on some PHP versions; is it a problem even though zend_bool
//       was always supposed to be 0 or 1 anyway?
pub type ZendBool = bool;
pub use ZendBool as zend_bool;

#[repr(C)]
#[derive(Copy, Clone, Eq, PartialEq)]
pub enum ZendResult {
    Success = 0,
    Failure = -1,
}

pub use ZendResult as zend_result;

impl From<c_int> for ZendResult {
    fn from(value: c_int) -> Self {
        match value {
            0 => Self::Success,
            _ => Self::Failure,
        }
    }
}

// In general, modify definitions which return int to return ZendResult if
// they are suppose to be doing that already.
// Across PHP versions, some things switch from mut to const; use const.

#[repr(C)]
pub struct ModuleEntry {
    pub size: c_ushort,
    pub zend_api: c_uint,
    pub zend_debug: c_uchar,
    pub zts: c_uchar,
    pub ini_entry: *const _zend_ini_entry,
    pub deps: *const ModuleDep,
    pub name: *const u8,
    pub functions: *const _zend_function_entry,
    pub module_startup_func:
        Option<unsafe extern "C" fn(type_: c_int, module_number: c_int) -> ZendResult>,
    pub module_shutdown_func:
        Option<unsafe extern "C" fn(type_: c_int, module_number: c_int) -> ZendResult>,
    pub request_startup_func:
        Option<unsafe extern "C" fn(type_: c_int, module_number: c_int) -> ZendResult>,
    pub request_shutdown_func:
        Option<unsafe extern "C" fn(type_: c_int, module_number: c_int) -> ZendResult>,
    pub info_func: Option<unsafe extern "C" fn(zend_module: *mut ModuleEntry)>,
    pub version: *const u8,
    pub globals_size: size_t,
    #[cfg(php_zts)]
    pub globals_id_ptr: *mut ts_rsrc_id,
    #[cfg(not(php_zts))]
    pub globals_ptr: *mut c_void,
    pub globals_ctor: Option<unsafe extern "C" fn(global: *mut c_void)>,
    pub globals_dtor: Option<unsafe extern "C" fn(global: *mut c_void)>,
    pub post_deactivate_func: Option<unsafe extern "C" fn() -> ZendResult>,
    pub module_started: c_int,
    pub type_: c_uchar,
    pub handle: *mut c_void,
    pub module_number: c_int,
    pub build_id: *const c_char,
}
pub use ModuleEntry as _zend_module_entry;
pub use ModuleEntry as zend_module_entry;

#[repr(C)]
pub struct ZendExtension {
    pub name: *const u8,
    pub version: *const u8,
    pub author: *const u8,
    pub url: *const u8,
    pub copyright: *const u8,
    pub startup: Option<unsafe extern "C" fn(extension: *mut ZendExtension) -> ZendResult>,
    pub shutdown: shutdown_func_t,
    pub activate: activate_func_t,
    pub deactivate: deactivate_func_t,
    pub message_handler: message_handler_func_t,
    pub op_array_handler: op_array_handler_func_t,
    pub statement_handler: statement_handler_func_t,
    pub fcall_begin_handler: fcall_begin_handler_func_t,
    pub fcall_end_handler: fcall_end_handler_func_t,
    pub op_array_ctor: op_array_ctor_func_t,
    pub op_array_dtor: op_array_dtor_func_t,
    pub api_no_check: Option<unsafe extern "C" fn(api_no: c_int) -> ZendResult>,
    pub build_id_check: Option<unsafe extern "C" fn(build_id: *const c_char) -> ZendResult>,
    pub op_array_persist_calc: op_array_persist_calc_func_t,
    pub op_array_persist: op_array_persist_func_t,
    pub reserved5: *mut c_void,
    pub reserved6: *mut c_void,
    pub reserved7: *mut c_void,
    pub reserved8: *mut c_void,
    pub handle: *mut c_void,
    pub resource_number: c_int,
}

pub use ZendExtension as zend_extension;

impl Default for ModuleEntry {
    fn default() -> Self {
        Self {
            size: std::mem::size_of::<Self>() as c_ushort,
            zend_api: ZEND_MODULE_API_NO,
            zend_debug: ZEND_DEBUG as u8,
            zts: USING_ZTS as u8,
            ini_entry: std::ptr::null(),
            deps: std::ptr::null(),
            name: b"\0".as_ptr(),
            functions: std::ptr::null(),
            module_startup_func: None,
            module_shutdown_func: None,
            request_startup_func: None,
            request_shutdown_func: None,
            info_func: None,
            version: std::ptr::null(),
            globals_size: 0,

            #[cfg(php_zts)]
            globals_id_ptr: std::ptr::null_mut(),
            #[cfg(not(php_zts))]
            globals_ptr: std::ptr::null_mut(),

            globals_ctor: None,
            globals_dtor: None,
            post_deactivate_func: None,
            module_started: 0,
            type_: MODULE_PERSISTENT as c_uchar,
            handle: std::ptr::null_mut(),
            module_number: -1,
            build_id: unsafe { datadog_module_build_id() },
        }
    }
}

impl Default for ZendExtension {
    fn default() -> Self {
        Self {
            name: b"\0".as_ptr(),
            version: b"\0".as_ptr(),
            author: b"\0".as_ptr(),
            url: b"\0".as_ptr(),
            copyright: b"\0".as_ptr(),
            startup: None,
            shutdown: None,
            activate: None,
            deactivate: None,
            message_handler: None,
            op_array_handler: None,
            statement_handler: None,
            fcall_begin_handler: None,
            fcall_end_handler: None,
            op_array_ctor: None,
            op_array_dtor: None,
            api_no_check: None,
            build_id_check: None,
            op_array_persist_calc: None,
            op_array_persist: None,
            reserved5: std::ptr::null_mut(),
            reserved6: std::ptr::null_mut(),
            reserved7: std::ptr::null_mut(),
            reserved8: std::ptr::null_mut(),
            handle: std::ptr::null_mut(),
            resource_number: -1,
        }
    }
}

#[repr(C)]
pub struct EfreePtr<T> {
    ptr: *mut T,
}

impl EfreePtr<c_char> {
    /// Converts the possibly-null string into an Option<CString>, treating an
    /// empty string as a None.
    pub fn into_c_string(self) -> Option<CString> {
        if !self.ptr.is_null() {
            /* Safety: If this is invalid when non-null, then someone else has
             * messed up already, nothing we can do really.
             */
            let cstr = unsafe { CStr::from_ptr(self.ptr) };

            // treat empty strings the same as no string
            if cstr.to_bytes().is_empty() {
                return None;
            }

            Some(cstr.to_owned())
        } else {
            None
        }
    }
}

impl<T> Drop for EfreePtr<T> {
    fn drop(&mut self) {
        if !self.ptr.is_null() {
            unsafe { _efree(self.ptr as *mut c_void) }
        }
    }
}

extern "C" {
    /// Get the env var from the SAPI. May be NULL. If non-null, it must be
    /// efree'd, hence custom definition.
    pub fn sapi_getenv(name: *const c_char, name_len: size_t) -> EfreePtr<c_char>;

    /// Retrieves the VM interrupt address of the calling PHP thread.
    /// # Safety
    /// Must be called from a PHP thread during a request.
    pub fn datadog_php_profiling_vm_interrupt_addr() -> *const AtomicBool;

    /// Registers the extension. Note that it's kept in a zend_llist and gets
    /// pemalloc'd + memcpy'd into place. The engine says this is a mutable
    /// pointer, but in practice it's const.
    #[cfg(php8)]
    pub fn zend_register_extension(extension: &ZendExtension, handle: *mut c_void);

    #[cfg(php7)]
    pub fn zend_register_extension(extension: &ZendExtension, handle: *mut c_void) -> ZendResult;
}

pub use zend_module_dep as ModuleDep;

impl ModuleDep {
    pub const fn optional(name: &CStr) -> Self {
        Self {
            name: name.as_ptr(),
            rel: std::ptr::null(),
            version: std::ptr::null(),
            type_: MODULE_DEP_OPTIONAL as c_uchar,
        }
    }

    pub const fn end() -> Self {
        Self {
            name: std::ptr::null(),
            rel: std::ptr::null(),
            version: std::ptr::null(),
            type_: 0,
        }
    }
}

/// Note that it's only _actually_ safe if all pointers are 'static
unsafe impl Sync for ModuleDep {}

pub type InternalFunctionHandler =
    Option<unsafe extern "C" fn(execute_data: *mut zend_execute_data, return_value: *mut zval)>;

impl datadog_php_zif_handler {
    pub fn new(
        name: &'static CStr,
        old_handler: &'static mut InternalFunctionHandler,
        new_handler: InternalFunctionHandler,
    ) -> Self {
        let name = name.to_bytes();
        Self {
            name: name.as_ptr() as *const c_char,
            name_len: name.len().try_into().expect("usize to fit"),
            old_handler,
            new_handler,
        }
    }
}

impl<'a> TryFrom<&'a mut zval> for &'a mut zend_long {
    type Error = u8;

    fn try_from(zval: &'a mut zval) -> Result<Self, Self::Error> {
        let r#type = unsafe { zval.u1.v.type_ };
        if r#type as u32 == IS_LONG {
            Ok(unsafe { &mut zval.value.lval })
        } else {
            Err(r#type)
        }
    }
}

impl TryFrom<&mut zval> for zend_long {
    type Error = u8;

    fn try_from(zval: &mut zval) -> Result<Self, Self::Error> {
        let r#type = unsafe { zval.u1.v.type_ };
        if r#type as u32 == IS_LONG {
            Ok(unsafe { zval.value.lval })
        } else {
            Err(r#type)
        }
    }
}

impl TryFrom<zval> for zend_long {
    type Error = u8;

    fn try_from(mut zval: zval) -> Result<Self, Self::Error> {
        zend_long::try_from(&mut zval)
    }
}
