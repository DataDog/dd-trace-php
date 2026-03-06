mod ffi;

pub use ffi::*;

pub use libdd_library_config_ffi::*;

use libc::{c_char, c_int, c_uchar, c_uint, c_ushort, c_void, size_t};
use std::borrow::Cow;
use std::ffi::CStr;
use std::marker::PhantomData;
use std::{ptr, str};

pub use crate::sapi::get_sapi_request_info;
pub use crate::universal::{install_handler, install_method_handler};

// Re-exports from their canonical homes so callers use `zend::name`.
pub use crate::allocation::{
    ddog_php_prof_zend_mm_get_custom_handlers_ex, ddog_php_prof_zend_mm_set_custom_handlers,
    ddog_php_prof_zend_mm_set_custom_handlers_ex,
};
pub use crate::capi::{
    datadog_php_profiling_get_process_tags_serialized, datadog_php_profiling_get_profiling_context,
    datadog_php_profiling_startup, ddog_php_prof_copy_long_into_zval, ddog_php_prof_functions,
    ddtrace_profiling_context, SyncFnTable,
};
pub use crate::config::{
    ddog_php_prof_config_first_rinit, ddog_php_prof_config_get,
    ddog_php_prof_config_is_set_by_user, ddog_php_prof_config_minit,
    ddog_php_prof_config_mshutdown, ddog_php_prof_config_rinit, ddog_php_prof_config_rshutdown,
};
pub use crate::exception::ddog_php_prof_zend_observer_error_register;
pub use crate::profiling::{
    ddog_php_prof_function_run_time_cache, ddog_php_prof_function_run_time_cache_init,
    ddog_php_prof_gc_get_status,
};

pub type VmInterruptFn = unsafe extern "C" fn(execute_data: *mut zend_execute_data);

pub type VmGcCollectCyclesFn = unsafe extern "C" fn() -> i32;
pub type VmZendCompileFile =
    unsafe extern "C" fn(*mut zend_file_handle, i32) -> *mut _zend_op_array;
pub type VmZendAccelScheduleRestartHook = unsafe extern "C" fn(i32);
/// Universal: PHP 8 style (zend_string, c_char) -> op_array
pub type VmZendCompileString =
    unsafe extern "C" fn(*mut zend_string, *const c_char) -> *mut _zend_op_array;

/// PHP 7: *mut zval, PHP 8: *mut zend_object. Use *mut c_void for universal.
pub type VmZendThrowExceptionHook = unsafe extern "C" fn(*mut c_void);

pub type VmMmCustomAllocFn = unsafe extern "C" fn(size_t) -> *mut c_void;
pub type VmMmCustomReallocFn = unsafe extern "C" fn(*mut c_void, size_t) -> *mut c_void;
pub type VmMmCustomFreeFn = unsafe extern "C" fn(*mut c_void);
pub type VmMmCustomGcFn = unsafe extern "C" fn() -> size_t;
pub type VmMmCustomShutdownFn = unsafe extern "C" fn(bool, bool);

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

/// The zend_string struct uses a technique that has undefined behaviour in
/// both C and C++, but it nonetheless very popular. One of the specific
/// problems for PHP is that its headers will be used by both C and C++
/// compilers. The official C remedy is called a flexible array member, but
/// C++ does not yet support this (there was a recent proposal, I do not know
/// the status).
///
/// Aside from the trickery above, Rust has some undefined behavior edges of
/// its own specifically with dynamically sized types (DSTs), which the
/// zend_string is.
///
/// Taking these two things into account, we treat this as an opaque type.
#[repr(C)]
pub struct ZendString {
    _opaque: [u8; 0],
}

impl _zend_object {
    pub fn class_name(&self) -> String {
        unsafe {
            crate::zend_string::zend_string_to_zai_str(
                (*self.ce).name.as_mut().map(|r| r as *mut _),
            )
            .into_string()
        }
    }
}

impl _zend_function {
    /// Returns a slice to the zend_string's data if it's present and not
    /// empty; otherwise returns None.
    fn zend_string_to_optional_bytes(zstr: Option<&mut zend_string>) -> Option<&[u8]> {
        let bytes = unsafe {
            crate::zend_string::zend_string_to_zai_str(zstr.map(|r| r as *mut _)).into_bytes()
        };
        if bytes.is_empty() {
            None
        } else {
            Some(bytes)
        }
    }

    /// Returns the function name, if there is one and it's not an empty string.
    pub fn name(&self) -> Option<&[u8]> {
        // Safety: function name is a valid mutable reference if not null.
        Self::zend_string_to_optional_bytes(unsafe { self.common.function_name.as_mut() })
    }

    /// Returns the name of the function's stored scope (not runtime scope).
    pub fn scope_name(&self) -> Option<&[u8]> {
        // Safety: common is always safe to access. Assume scope is a valid
        // reference if not null.
        unsafe { self.common.scope.as_ref() }.and_then(|scope| {
            // Safety: assume scope name is a valid mutable reference if not null.
            let scope_name = unsafe { scope.name.as_mut() };
            Self::zend_string_to_optional_bytes(scope_name)
        })
    }

    /// Returns the module name, if there is one. May return Some(b"\0").
    pub fn module_name(&self) -> Option<&[u8]> {
        if self.is_internal() {
            // Safety: union access is guarded by is_internal(), and assume
            // its module is valid.
            unsafe { self.u.internal_function.module.as_ref() }
                .filter(|module| !module.name.is_null())
                // Safety: assume module.name has a valid c string.
                .map(|module| unsafe { CStr::from_ptr(module.name as *const c_char) }.to_bytes())
        } else {
            None
        }
    }

    #[inline]
    pub fn is_internal(&self) -> bool {
        self.type_ == ZEND_INTERNAL_FUNCTION
    }

    /// Returns the op_array if this is a user function or eval code.
    #[inline]
    pub fn op_array(&self) -> Option<&zend_op_array> {
        if !self.is_internal() {
            // SAFETY: If it's not internal, then both user and eval types use
            // the op_array field.
            unsafe { Some(&self.u.op_array) }
        } else {
            None
        }
    }
}

// In general, modify definitions which return int to return ZendResult if
// they are suppose to be doing that already.
// Across PHP versions, some things switch from mut to const; use const.

/// ZTS uses globals_id_ptr, NTS uses globals_ptr. Same offset in C struct.
#[repr(C)]
#[derive(Clone, Copy)]
pub union ModuleGlobalsUnion {
    pub globals_id_ptr: *mut ts_rsrc_id,
    pub globals_ptr: *mut c_void,
}

#[repr(C)]
pub struct ModuleEntry {
    pub size: c_ushort,
    pub zend_api: c_uint,
    pub zend_debug: c_uchar,
    pub zts: c_uchar,
    pub ini_entry: *const _zend_ini_entry,
    pub deps: *const ModuleDep,
    pub name: *const c_char,
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
    /// Size of the module globals in bytes. In ZTS this will be the size TSRM will allocate per
    /// thread for module globals. The function pointers in [`ModuleEntry::globals_ctor`] and
    /// [`ModuleEntry::globals_dtor`] will only be called if this is a non-zero.
    pub globals_size: size_t,
    /// ZTS: globals_id_ptr. NTS: globals_ptr. Same offset in C; set the appropriate variant at runtime.
    pub globals: ModuleGlobalsUnion,
    /// Constructor for module globals.
    /// Be aware this will only be called in case [`ModuleEntry::globals_size`] is non-zero and for
    /// ZTS you need to make sure [`ModuleEntry::globals_id_ptr`] is a valid, non-null pointer.
    pub globals_ctor: Option<unsafe extern "C" fn(global: *mut c_void)>,
    /// Destructor for module globals.
    /// Be aware this will only be called in case [`ModuleEntry::globals_size`] is non-zero and for
    /// ZTS you need to make sure [`ModuleEntry::globals_id_ptr`] is a valid, non-null pointer.
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
    pub name: *const c_char,
    pub version: *const c_char,
    pub author: *const c_char,
    pub url: *const c_char,
    pub copyright: *const c_char,
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

/// Version info for zend_extension loading. Used when loading as zend_extension=.
#[repr(C)]
pub struct ZendExtensionVersionInfo {
    pub zend_extension_api_no: c_int,
    pub build_id: *const c_char,
}

impl ModuleEntry {
    /// Creates a new ModuleEntry with default values for const-compatible fields.
    /// Non-const fields (functions, build_id) are set to null and should be initialized separately.
    #[allow(clippy::new_without_default)]
    pub const fn new() -> Self {
        Self {
            size: core::mem::size_of::<Self>() as c_ushort,
            zend_api: ZEND_MODULE_API_NO,
            zend_debug: ZEND_DEBUG as c_uchar,
            zts: USING_ZTS as c_uchar,
            ini_entry: ptr::null(),
            deps: ptr::null(),
            name: c"".as_ptr(),
            functions: ptr::null(),
            module_startup_func: None,
            module_shutdown_func: None,
            request_startup_func: None,
            request_shutdown_func: None,
            info_func: None,
            version: ptr::null(),
            globals_size: 0,
            globals: ModuleGlobalsUnion {
                globals_ptr: ptr::null_mut(),
            },
            globals_ctor: None,
            globals_dtor: None,
            post_deactivate_func: None,
            module_started: 0,
            type_: MODULE_PERSISTENT as c_uchar,
            handle: ptr::null_mut(),
            module_number: -1,
            build_id: ptr::null(),
        }
    }
}

impl Default for ZendExtension {
    fn default() -> Self {
        Self {
            name: c"".as_ptr(),
            version: c"".as_ptr(),
            author: c"".as_ptr(),
            url: c"".as_ptr(),
            copyright: c"".as_ptr(),
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
            reserved5: ptr::null_mut(),
            reserved6: ptr::null_mut(),
            reserved7: ptr::null_mut(),
            reserved8: ptr::null_mut(),
            handle: ptr::null_mut(),
            resource_number: -1,
        }
    }
}

extern "C" {
    /// Registers an internal module with the engine. Used when loading as
    /// zend_extension= to register our module from build_id_check.
    pub fn zend_register_internal_module(module_entry: *mut ModuleEntry) -> *mut ModuleEntry;

    /// Registers the extension. Note that it's kept in a zend_llist and gets
    /// pemalloc'd + memcpy'd into place. The engine says this is a mutable
    /// pointer, but in practice it's const.
    pub fn zend_register_extension(extension: &ZendExtension, handle: *mut c_void);

    /// Writes a string to the output.
    pub static zend_write: Option<unsafe extern "C" fn(*const c_char, usize) -> usize>;

    /// Default VM internal-call handler. Use as the fallback when zend_execute_internal is null.
    pub fn execute_internal(execute_data: *mut zend_execute_data, return_value: *mut zval);
    pub static mut zend_execute_internal:
        Option<unsafe extern "C" fn(*mut zend_execute_data, *mut zval)>;
    pub static mut zend_interrupt_function: Option<unsafe extern "C" fn(*mut zend_execute_data)>;
    pub static mut sapi_module: sapi_module_struct;
    pub static mut zend_throw_exception_hook: Option<VmZendThrowExceptionHook>;
    pub static mut gc_collect_cycles: Option<VmGcCollectCyclesFn>;
    pub static mut zend_compile_file: Option<VmZendCompileFile>;
    pub static mut zend_compile_string: Option<VmZendCompileString>;
    pub fn zend_get_executed_filename_ex(flags: i32) -> *mut zend_string;
    pub fn zend_get_executed_lineno() -> u32;
    pub fn datadog_extension_build_id() -> *const c_char;
    pub fn is_zend_mm() -> c_int;
    pub fn zend_mm_get_heap() -> *mut zend_mm_heap;
    pub fn zend_mm_gc(heap: *mut zend_mm_heap) -> size_t;
    pub fn zend_mm_shutdown(heap: *mut zend_mm_heap, full: bool, silent: bool);
    pub fn zend_mm_get_custom_handlers(
        heap: *mut zend_mm_heap,
        malloc: *mut *mut c_void,
        free: *mut *mut c_void,
        realloc: *mut *mut c_void,
    );
    pub fn _zend_mm_alloc(heap: *mut zend_mm_heap, size: usize) -> *mut c_void;
    pub fn _zend_mm_free(heap: *mut zend_mm_heap, ptr: *mut c_void);
    pub fn _zend_mm_realloc(heap: *mut zend_mm_heap, ptr: *mut c_void, size: usize) -> *mut c_void;
    pub fn zend_get_extension(name: *const c_char) -> *mut ZendExtension;
    pub fn php_info_print_table_start();
    pub fn php_info_print_table_row(cols: c_int, a: *const c_char, b: *const c_char);
    pub fn php_info_print_table_end();
    pub fn display_ini_entries(module: *mut ModuleEntry);
}

pub use zend_module_dep as ModuleDep;

impl ModuleDep {
    pub const fn required(name: &CStr) -> Self {
        Self {
            name: name.as_ptr(),
            rel: ptr::null(),
            version: ptr::null(),
            type_: MODULE_DEP_REQUIRED as c_uchar,
        }
    }

    pub const fn optional(name: &CStr) -> Self {
        Self {
            name: name.as_ptr(),
            rel: ptr::null(),
            version: ptr::null(),
            type_: MODULE_DEP_OPTIONAL as c_uchar,
        }
    }

    pub const fn end() -> Self {
        Self {
            name: ptr::null(),
            rel: ptr::null(),
            version: ptr::null(),
            type_: 0,
        }
    }
}

/// Note that it's only _actually_ safe if all pointers are 'static
unsafe impl Sync for ModuleDep {}

pub type InternalFunctionHandler =
    Option<unsafe extern "C" fn(execute_data: *mut zend_execute_data, return_value: *mut zval)>;

impl datadog_php_zim_handler {
    pub fn new(
        class_name: &'static CStr,
        name: &'static CStr,
        old_handler: *mut InternalFunctionHandler,
        new_handler: InternalFunctionHandler,
    ) -> Self {
        let class_name = class_name.to_bytes();
        Self {
            class_name: class_name.as_ptr() as *const c_char,
            class_name_len: class_name.len(),
            zif: datadog_php_zif_handler::new(name, old_handler, new_handler),
        }
    }
}

impl datadog_php_zif_handler {
    pub fn new(
        name: &'static CStr,
        old_handler: *mut InternalFunctionHandler,
        new_handler: InternalFunctionHandler,
    ) -> Self {
        let name = name.to_bytes();
        Self {
            name: name.as_ptr() as *const c_char,
            name_len: name.len(),
            old_handler,
            new_handler,
        }
    }
}

impl zval {
    /// Returns the PHP type tag (IS_LONG, IS_STRING, …).
    /// The type byte is the first byte of `u1` in little-endian order,
    /// matching PHP's `Z_TYPE()` macro on all supported platforms.
    #[inline]
    pub fn get_type(&self) -> u8 {
        self.u1.to_le_bytes()[0]
    }

    /// Sets the PHP type tag, preserving the remaining bytes of `u1`.
    #[inline]
    pub fn set_type(&mut self, type_: u8) {
        let mut bytes = self.u1.to_le_bytes();
        bytes[0] = type_;
        self.u1 = u32::from_le_bytes(bytes);
    }
}

impl<'a> TryFrom<&'a mut zval> for &'a mut zend_long {
    type Error = u8;

    fn try_from(zval: &'a mut zval) -> Result<Self, Self::Error> {
        let r#type = zval.get_type();
        if r#type == IS_LONG {
            Ok(unsafe { &mut zval.value.lval })
        } else {
            Err(r#type)
        }
    }
}

impl TryFrom<&mut zval> for zend_long {
    type Error = u8;

    fn try_from(zval: &mut zval) -> Result<Self, Self::Error> {
        let r#type = zval.get_type();
        if r#type == IS_LONG {
            Ok(unsafe { zval.value.lval })
        } else {
            Err(r#type)
        }
    }
}

impl TryFrom<&mut zval> for u32 {
    type Error = u8;

    fn try_from(zval: &mut zval) -> Result<Self, Self::Error> {
        let r#type = zval.get_type();
        if r#type == IS_LONG {
            match u32::try_from(unsafe { zval.value.lval }) {
                Err(_) => Err(r#type),
                Ok(val) => Ok(val),
            }
        } else {
            Err(r#type)
        }
    }
}

impl TryFrom<zval> for u32 {
    type Error = u8;

    fn try_from(mut zval: zval) -> Result<Self, Self::Error> {
        u32::try_from(&mut zval)
    }
}

impl TryFrom<zval> for zend_long {
    type Error = u8;

    fn try_from(mut zval: zval) -> Result<Self, Self::Error> {
        zend_long::try_from(&mut zval)
    }
}

impl TryFrom<&mut zval> for bool {
    type Error = u8;

    fn try_from(zval: &mut zval) -> Result<Self, Self::Error> {
        let r#type = zval.get_type();
        if r#type == IS_FALSE {
            Ok(false)
        } else if r#type == IS_TRUE {
            Ok(true)
        } else {
            Err(r#type)
        }
    }
}

pub enum StringError {
    Null,     // zval.value.str_ pointer was null, very bad.
    Type(u8), // Type didn't match.
}

/// Since we're making a String, do lossy-conversion as necessary.
impl TryFrom<&mut zval> for String {
    type Error = StringError;

    fn try_from(zval: &mut zval) -> Result<Self, Self::Error> {
        Cow::try_from(zval).map(Cow::into_owned)
    }
}

impl<'a> TryFrom<&'a mut zval> for Cow<'a, str> {
    type Error = StringError;

    fn try_from(zval: &'a mut zval) -> Result<Self, Self::Error> {
        let r#type = zval.get_type();
        if r#type == IS_STRING {
            // This shouldn't happen, very bad, something screwed up.
            if unsafe { zval.value.str_.is_null() } {
                return Err(StringError::Null);
            }
            // SAFETY: checked the pointer wasn't null above.
            let reference: Option<&'a mut zend_string> = unsafe { zval.value.str_.as_mut() };

            let str = unsafe {
                crate::zend_string::zend_string_to_zai_str(reference.map(|r| r as *mut _))
                    .into_string_lossy()
            };
            Ok(str)
        } else {
            Err(StringError::Type(r#type))
        }
    }
}

/// A non-owning, not necessarily null terminated, not necessarily utf-8
/// encoded, borrowed string.
/// It must satisfy the requirements of [core::slice::from_raw_parts], notably
/// it must not use the null pointer even when the length is 0.
/// Keep this representation in sync with zai_str.
#[repr(C)]
pub struct ZaiStr<'a> {
    ptr: *const c_char,
    len: size_t,
    _marker: PhantomData<&'a [c_char]>,
}

impl<'a> From<&'a [u8]> for ZaiStr<'a> {
    fn from(val: &'a [u8]) -> Self {
        Self {
            len: val.len(),
            ptr: val.as_ptr() as *const c_char,
            _marker: PhantomData,
        }
    }
}

impl<'a> From<&'a str> for ZaiStr<'a> {
    fn from(value: &'a str) -> Self {
        Self::from(value.as_bytes())
    }
}

impl Default for ZaiStr<'_> {
    fn default() -> Self {
        Self::new()
    }
}

impl<'a> ZaiStr<'a> {
    pub const fn new() -> ZaiStr<'a> {
        const NULL: &[u8] = b"\0";
        Self {
            len: 0,
            ptr: NULL.as_ptr() as *const c_char,
            _marker: PhantomData,
        }
    }

    /// Create from raw pointer and length. Replaces zai_str_from_zstr for zend_string.
    ///
    /// # Safety
    /// - ptr must be valid for reads of len bytes, or be non-null for len 0.
    /// - For len 0, ptr may be null or point to any byte.
    #[inline]
    pub const unsafe fn from_raw_parts(ptr: *const c_char, len: size_t) -> ZaiStr<'a> {
        let ptr = if len == 0 && ptr.is_null() {
            b"\0".as_ptr() as *const c_char
        } else {
            ptr
        };
        Self {
            ptr,
            len,
            _marker: PhantomData,
        }
    }

    pub fn is_empty(&self) -> bool {
        // Note: ptr shouldn't be null!
        self.len == 0 || self.ptr.is_null()
    }

    /// # Safety
    /// `str` must be valid for [CStr::from_bytes_with_nul_unchecked]
    pub const unsafe fn literal(bytes: &'static [u8]) -> Self {
        // Chance at catching some UB at runtime with debug builds.
        debug_assert!(!bytes.is_empty() && bytes[bytes.len() - 1] == 0);

        let mut i: usize = 0;
        while bytes[i] != b'\0' {
            i += 1;
        }
        Self {
            len: i as size_t,
            ptr: bytes.as_ptr() as *const c_char,
            _marker: PhantomData,
        }
    }

    #[inline]
    pub fn as_bytes(&self) -> &'a [u8] {
        debug_assert!(!self.ptr.is_null());
        let len = self.len;
        // Safety: the ZaiStr is supposed to uphold all the invariants, and
        // the pointer has been debug_asserted to not be null, so 🤞🏻.
        unsafe { std::slice::from_raw_parts(self.ptr as *const u8, len) }
    }

    #[inline]
    pub fn into_bytes(self) -> &'a [u8] {
        self.as_bytes()
    }

    #[inline]
    pub fn into_utf8(self) -> Result<&'a str, str::Utf8Error> {
        let bytes = self.into_bytes();
        str::from_utf8(bytes)
    }

    #[inline]
    pub fn into_string(self) -> String {
        self.to_string_lossy().into_owned()
    }

    #[inline]
    pub fn to_string_lossy(&self) -> Cow<'a, str> {
        String::from_utf8_lossy(self.as_bytes())
    }

    #[inline]
    pub fn into_string_lossy(self) -> Cow<'a, str> {
        let bytes = self.as_bytes();
        String::from_utf8_lossy(bytes)
    }
}

#[cfg(test)]
mod tests {
    use core::mem;

    // If this fails, then ddog_php_prof_function_run_time_cache needs to be
    // adjusted accordingly.
    #[test]
    fn test_sizeof_fixed_size_slice_is_same_as_pointer() {
        assert_eq!(mem::size_of::<&[usize; 2]>(), mem::size_of::<*mut usize>());
        assert_eq!(
            mem::align_of::<&[usize; 2]>(),
            mem::align_of::<*mut usize>()
        );
    }
}
