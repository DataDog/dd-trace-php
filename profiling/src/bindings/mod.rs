mod ffi;

pub use ffi::*;
use libc::{c_char, c_int, c_uchar, c_uint, c_ushort, c_void, size_t};
use log::LevelFilter;
use std::ffi::CStr;
use std::str::Utf8Error;
use std::sync::atomic::{AtomicBool, AtomicU32};

pub type VmInterruptFn = unsafe extern "C" fn(execute_data: *mut zend_execute_data);

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

impl<'a> TryFrom<&'a datadog_php_str> for &'a str {
    type Error = Utf8Error;

    fn try_from(value: &'a datadog_php_str) -> Result<Self, Self::Error> {
        let slice =
            unsafe { std::slice::from_raw_parts(value.ptr as *const u8, value.size as usize) };
        std::str::from_utf8(slice)
    }
}

impl Default for datadog_php_str {
    fn default() -> Self {
        Self {
            ptr: b"\0".as_ptr() as *const c_char,
            size: 0,
        }
    }
}

#[repr(C)]
pub struct EfreePtr<T> {
    ptr: *mut T,
}

impl EfreePtr<c_char> {
    pub fn into_string(self) -> String {
        if !self.ptr.is_null() {
            /* Safety: If this is invalid when non-null, then someone else has
             * messed up already, nothing we can do really.
             */
            let cstr = unsafe { CStr::from_ptr(self.ptr) };
            String::from_utf8_lossy(cstr.to_bytes()).into_owned()
        } else {
            String::new()
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

    /// This is just here for IDE completion; the bindgen'd code doesn't
    /// autocomplete and I use this function quite a bit.
    pub fn datadog_php_profiling_globals_get<'a>() -> &'a mut DatadogPhpProfilingGlobals;

    /// Registers the extension. Note that it's kept in a zend_llist and gets
    /// pemalloc'd + memcpy'd into place. The engine says this is a mutable
    /// pointer, but in practice it's const.
    #[cfg(php8)]
    pub fn zend_register_extension(extension: &ZendExtension, handle: *mut c_void);

    #[cfg(php7)]
    pub fn zend_register_extension(extension: &ZendExtension, handle: *mut c_void) -> ZendResult;
}

#[repr(C)]
pub struct DatadogPhpProfilingGlobals {
    pub profiling_enabled: bool,
    pub profiling_experimental_cpu_time_enabled: bool,
    pub interrupt_count: AtomicU32,
    pub profiling_log_level: LevelFilter,
    pub vm_interrupt_addr: *const AtomicBool,
    pub env: datadog_php_str,
    pub service: datadog_php_str,
    pub version: datadog_php_str,
    pub agent_host: datadog_php_str,
    pub trace_agent_port: datadog_php_str,
    pub trace_agent_url: datadog_php_str,
}

pub use DatadogPhpProfilingGlobals as zend_datadog_php_profiling_globals;

impl Default for DatadogPhpProfilingGlobals {
    fn default() -> Self {
        Self {
            profiling_enabled: false,
            profiling_experimental_cpu_time_enabled: true,
            profiling_log_level: LevelFilter::Off,
            vm_interrupt_addr: std::ptr::null(),
            interrupt_count: AtomicU32::new(0),
            env: datadog_php_str::default(),
            service: datadog_php_str::default(),
            version: datadog_php_str::default(),
            agent_host: datadog_php_str::default(),
            trace_agent_port: datadog_php_str::default(),
            trace_agent_url: datadog_php_str::default(),
        }
    }
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
