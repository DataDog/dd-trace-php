mod ffi;

pub use ffi::*;

use libc::{c_char, c_int, c_uchar, c_uint, c_ushort, c_void, size_t};
use std::borrow::Cow;
use std::ffi::CStr;
use std::marker::PhantomData;
use std::str::Utf8Error;
use std::sync::atomic::AtomicBool;

extern "C" {
    pub static ddog_php_prof_functions: *const zend_function_entry;
}

pub type VmInterruptFn = unsafe extern "C" fn(execute_data: *mut zend_execute_data);

#[cfg(feature = "timeline")]
pub type VmGcCollectCyclesFn = unsafe extern "C" fn() -> i32;
#[cfg(feature = "timeline")]
pub type VmZendCompileFile =
    unsafe extern "C" fn(*mut zend_file_handle, i32) -> *mut _zend_op_array;
#[cfg(all(feature = "timeline", php_zend_compile_string_has_position))]
pub type VmZendCompileString = unsafe extern "C" fn(
    *mut zend_string,
    *const c_char,
    zend_compile_position,
) -> *mut _zend_op_array;
#[cfg(all(feature = "timeline", not(php_zend_compile_string_has_position), php8))]
pub type VmZendCompileString =
    unsafe extern "C" fn(*mut zend_string, *const c_char) -> *mut _zend_op_array;
#[cfg(all(feature = "timeline", not(php_zend_compile_string_has_position), php7))]
pub type VmZendCompileString =
    unsafe extern "C" fn(*mut _zval_struct, *mut c_char) -> *mut _zend_op_array;

#[cfg(all(feature = "exception_profiling", php7))]
pub type VmZendThrowExceptionHook = unsafe extern "C" fn(*mut zval);
#[cfg(all(feature = "exception_profiling", php8))]
pub type VmZendThrowExceptionHook = unsafe extern "C" fn(*mut zend_object);

#[cfg(feature = "allocation_profiling")]
pub type VmMmCustomAllocFn = unsafe extern "C" fn(size_t) -> *mut c_void;
#[cfg(feature = "allocation_profiling")]
pub type VmMmCustomReallocFn = unsafe extern "C" fn(*mut c_void, size_t) -> *mut c_void;
#[cfg(feature = "allocation_profiling")]
pub type VmMmCustomFreeFn = unsafe extern "C" fn(*mut c_void);

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
        unsafe { zai_str_from_zstr((*self.ce).name.as_mut()).into_string() }
    }
}

impl _zend_function {
    /// Returns a slice to the zend_string's data if it's present and not
    /// empty; otherwise returns None.
    fn zend_string_to_optional_bytes(zstr: Option<&mut zend_string>) -> Option<&[u8]> {
        /* Safety: zai_str_from_zstr can be called with any valid zend_string
         * pointer, and the tailing .into_bytes() will be safe as the former
         * will always return a view with a non-null pointer.
         */
        let bytes = unsafe { zai_str_from_zstr(zstr) }.into_bytes();
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
        // Safety: the function's type field is always safe to access.
        if unsafe { self.type_ } == ZEND_INTERNAL_FUNCTION as u8 {
            // Safety: union access is guarded by ZEND_INTERNAL_FUNCTION, and
            // assume its module is valid.
            unsafe { self.internal_function.module.as_ref() }
                .filter(|module| !module.name.is_null())
                // Safety: assume module.name has a valid c string.
                .map(|module| unsafe { CStr::from_ptr(module.name as *const c_char) }.to_bytes())
        } else {
            None
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

extern "C" {
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

    /// Converts the `zstr` into a `zai_str`. A None as well as empty
    /// strings will be converted into a string view to a static empty string
    /// (single byte of null, len of 0).
    pub fn zai_str_from_zstr(zstr: Option<&mut zend_string>) -> zai_str;

    /// Returns the configuration item for the given config id. Note that the
    /// lifetime is roughly static, but technically it is from first rinit
    /// until mshutdown.
    pub(crate) fn ddog_php_prof_get_memoized_config(config_id: ConfigId) -> *mut zval;

    /// Registers the run_time_cache slot with the engine. Must be done in
    /// module init or extension startup.
    pub fn ddog_php_prof_function_run_time_cache_init(module_name: *const c_char);

    /// Gets the address of a function's run_time_cache slot. May return None
    /// if it detects incomplete initialization, which is always a bug but
    /// none-the-less has been seen in the wild. It may also return None if
    /// the run_time_cache is not available on this function type.
    #[cfg(not(feature = "stack_walking_tests"))]
    pub fn ddog_php_prof_function_run_time_cache(func: &zend_function) -> Option<&mut [usize; 2]>;

    /// mock for testing
    #[cfg(feature = "stack_walking_tests")]
    pub fn ddog_test_php_prof_function_run_time_cache(
        func: &zend_function,
    ) -> Option<&mut [usize; 2]>;

    /// Returns the PHP_VERSION_ID of the engine at run-time, not the version
    /// the extension was built against at compile-time.
    pub fn ddog_php_prof_php_version_id() -> u32;
}

#[cfg(php_post_startup_cb)]
extern "C" {
    /// Returns true after zend_post_startup_cb has been called for the current
    /// startup/shutdown cycle. This is useful to know. For example,
    /// preloading occurs while this is false.
    pub fn ddog_php_prof_is_post_startup() -> bool;
}

use crate::config::ConfigId;
pub use zend_module_dep as ModuleDep;

impl ModuleDep {
    pub const fn required(name: &CStr) -> Self {
        Self {
            name: name.as_ptr(),
            rel: std::ptr::null(),
            version: std::ptr::null(),
            type_: MODULE_DEP_REQUIRED as c_uchar,
        }
    }

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
            name_len: name.len(),
            old_handler,
            new_handler,
        }
    }
}

impl<'a> TryFrom<&'a mut zval> for &'a mut zend_long {
    type Error = u8;

    fn try_from(zval: &'a mut zval) -> Result<Self, Self::Error> {
        let r#type = unsafe { zval.u1.v.type_ };
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
        let r#type = unsafe { zval.u1.v.type_ };
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
        let r#type = unsafe { zval.u1.v.type_ };
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
        let r#type = unsafe { zval.u1.v.type_ };
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
        let r#type = unsafe { zval.u1.v.type_ };
        if r#type == IS_STRING {
            // This shouldn't happen, very bad, something screwed up.
            if unsafe { zval.value.str_.is_null() } {
                return Err(StringError::Null);
            }
            // SAFETY: checked the pointer wasn't null above.
            let reference: Option<&'a mut zend_string> = unsafe { zval.value.str_.as_mut() };

            // SAFETY: calling extern "C" with correct params.
            let str = unsafe { zai_str_from_zstr(reference) }.into_string_lossy();
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

impl<'a> ZaiStr<'a> {
    pub const fn new() -> ZaiStr<'a> {
        const NULL: &[u8] = b"\0";
        Self {
            len: 0,
            ptr: NULL.as_ptr() as *const c_char,
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
    pub fn into_utf8(self) -> Result<&'a str, Utf8Error> {
        let bytes = self.into_bytes();
        std::str::from_utf8(bytes)
    }

    #[inline]
    pub fn into_string(self) -> String {
        self.to_string_lossy().into_owned()
    }

    #[inline]
    pub fn to_string_lossy(&self) -> Cow<str> {
        String::from_utf8_lossy(self.as_bytes())
    }

    #[inline]
    pub fn into_string_lossy(self) -> Cow<'a, str> {
        let bytes = self.as_bytes();
        String::from_utf8_lossy(bytes)
    }
}

#[repr(C)]
pub struct ZaiConfigEntry {
    pub id: zai_config_id,
    pub name: zai_str<'static>,
    pub type_: zai_config_type,
    pub default_encoded_value: zai_str<'static>,
    pub aliases: *const zai_str<'static>,
    pub aliases_count: u8,
    pub ini_change: zai_config_apply_ini_change,
    pub parser: zai_custom_parse,
}

#[repr(C)]
pub struct ZaiConfigMemoizedEntry {
    pub names: [zai_config_name; 4usize],
    pub ini_entries: [*mut zend_ini_entry; 4usize],
    pub names_count: u8,
    pub type_: zai_config_type,
    pub decoded_value: zval,
    pub default_encoded_value: zai_str<'static>,
    pub name_index: i16,
    pub ini_change: zai_config_apply_ini_change,
    pub parser: zai_custom_parse,
    pub original_on_modify: Option<
        unsafe extern "C" fn(
            entry: *mut zend_ini_entry,
            new_value: *mut zend_string,
            mh_arg1: *mut c_void,
            mh_arg2: *mut c_void,
            mh_arg3: *mut c_void,
            stage: c_int,
        ) -> c_int,
    >,
}

#[cfg(test)]
mod tests {

    // If this fails, then ddog_php_prof_function_run_time_cache needs to be
    // adjusted accordingly.
    #[test]
    fn test_sizeof_fixed_size_slice_is_same_as_pointer() {
        assert_eq!(
            std::mem::size_of::<&[usize; 2]>(),
            std::mem::size_of::<*mut usize>()
        );
        assert_eq!(
            std::mem::align_of::<&[usize; 2]>(),
            std::mem::align_of::<*mut usize>()
        );
    }
}
