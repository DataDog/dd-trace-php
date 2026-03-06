//! Pure Rust bindings. No bindgen, no C headers.
//! Types match PHP engine layout; offsets vary by version (see universal::matrix).

#![allow(clippy::all)]
#![allow(warnings)]

use libc::{c_char, c_int, c_uchar, c_uint, c_void, size_t};

use crate::bindings::{_zend_module_entry, zend_extension, ZaiStr, ZendString};

// -----------------------------------------------------------------------------
// Type aliases (from bindgen)
// -----------------------------------------------------------------------------

pub type _zend_string = ZendString;
pub type zai_str_s<'a> = ZaiStr<'a>;
pub type zai_str<'a> = zai_str_s<'a>;

pub type zend_string = ZendString;

// -----------------------------------------------------------------------------
// Primitive types
// -----------------------------------------------------------------------------

pub type zend_long = isize;
pub type zend_ulong = usize;

// -----------------------------------------------------------------------------
// Constants
// -----------------------------------------------------------------------------

pub const ZEND_MODULE_API_NO: c_uint = 20230920; // PHP 8.3
pub const ZEND_DEBUG: c_int = 0;
pub const USING_ZTS: c_int = 0; // Runtime: check build_id for ,TS
pub const MODULE_DEP_REQUIRED: c_uchar = 1;
pub const MODULE_DEP_OPTIONAL: c_uchar = 3;
pub const MODULE_PERSISTENT: c_uchar = 1;

pub const IS_UNDEF: u8 = 0;
pub const IS_NULL: u8 = 1;
pub const IS_FALSE: u8 = 2;
pub const IS_TRUE: u8 = 3;
pub const IS_LONG: u8 = 4;
pub const IS_DOUBLE: u8 = 5;
pub const IS_STRING: u8 = 6;
pub const IS_ARRAY: u8 = 7;
pub const IS_OBJECT: u8 = 8;
pub const IS_RESOURCE: u8 = 9;
pub const IS_REFERENCE: u8 = 10;

pub const ZEND_INTERNAL_FUNCTION: u8 = 1;
pub const ZEND_USER_FUNCTION: u8 = 2;
pub const ZEND_EVAL_CODE: u8 = 4;

pub const ZEND_INCLUDE: u32 = 138;
pub const ZEND_REQUIRE: u32 = 139;

pub const E_FATAL_ERRORS: i32 = 0x10000; // ZEND_HANDLE_FATAL_ERRORS

pub const ZEND_MM_CUSTOM_HEAP_NONE: i32 = 0;

// -----------------------------------------------------------------------------
// Opaque / minimal structs (layout from matrix at runtime)
// -----------------------------------------------------------------------------

#[repr(C)]
pub struct zend_class_entry {
    _pad: [u8; 8],
    pub name: *mut zend_string,
}

#[repr(C)]
pub struct zend_object {
    pub ce: *mut zend_class_entry,
    // ... more fields; use matrix for class.name at offset 8
}

pub type _zend_object = zend_object;

#[repr(C)]
pub struct zend_op {
    _pad: [u8; 20],
    pub extended_value: u32,
    pub lineno: u32,
    pub opcode: u8,
}

#[repr(C)]
pub struct zend_execute_data {
    pub opline: *const zend_op,
    _call: *mut zend_execute_data,
    _return_value: *mut zval,
    pub func: *const zend_function,
    pub This: zval,
    pub prev_execute_data: *const zend_execute_data,
}

#[repr(C)]
pub struct zend_function_common {
    pub type_: u8,
    pub arg_flags: [u8; 3],
    pub fn_flags: u32,
    pub function_name: *mut zend_string,
    pub scope: *mut zend_class_entry,
    pub run_time_cache: *mut c_void,
}

#[repr(C)]
pub struct zend_internal_function {
    pub _pad: [u8; 24],
    pub handler: Option<unsafe extern "C" fn(*mut zend_execute_data, *mut zval)>,
    pub module: *const _zend_module_entry,
}

#[repr(C)]
pub struct zend_op_array {
    pub _pad: [u8; 56],
    pub filename: *mut zend_string,
    pub _pad2: [u8; 20],
    pub opcodes: *const zend_op,
    pub last: u32,
    pub _pad3: [u8; 4],
    pub run_time_cache: *mut c_void,
}

#[repr(C)]
pub union zend_function_union {
    pub internal_function: std::mem::ManuallyDrop<zend_internal_function>,
    pub op_array: std::mem::ManuallyDrop<zend_op_array>,
}

#[repr(C)]
pub struct zend_function {
    pub common: zend_function_common,
    pub u: zend_function_union,
    pub type_: u8,
}

pub type _zend_function = zend_function;

#[repr(C)]
pub struct _zend_op_array {
    _opaque: [u8; 0],
}

#[repr(C)]
pub struct zend_compile_position {
    pub start_line: u32,
    pub start_column: u32,
    pub end_line: u32,
    pub end_column: u32,
}

#[repr(C)]
pub struct zend_file_handle {
    _opaque: [u8; 0],
}

#[repr(C)]
pub struct _zend_ini_entry {
    _opaque: [u8; 0],
}

pub type zend_ini_entry = _zend_ini_entry;

#[repr(C)]
pub struct _zend_internal_arg_info {
    _opaque: [u8; 0],
}

#[repr(C)]
pub struct _zend_function_entry {
    pub fname: *const c_char,
    pub handler: Option<unsafe extern "C" fn(*mut zend_execute_data, *mut zval)>,
    pub arg_info: *const _zend_internal_arg_info,
    pub num_args: c_uint,
    pub flags: c_uint,
}

// Raw pointers in FFI types; safe for our use (C engine, single-threaded request).
unsafe impl Send for _zend_function_entry {}
unsafe impl Sync for _zend_function_entry {}

pub type zend_function_entry = _zend_function_entry;

#[repr(C)]
pub struct zend_module_dep {
    pub name: *const c_char,
    pub rel: *const c_char,
    pub version: *const c_char,
    pub type_: c_uchar,
}

pub type ModuleDep = zend_module_dep;

pub type ts_rsrc_id = c_int;

// -----------------------------------------------------------------------------
// zval
// -----------------------------------------------------------------------------

#[repr(C)]
pub union zend_value {
    pub lval: zend_long,
    pub dval: f64,
    pub counted: *mut c_void,
    pub str_: *mut zend_string,
    pub arr: *mut c_void,
    pub obj: *mut zend_object,
    pub res: *mut c_void,
    pub ref_: *mut c_void,
    pub ast: *mut c_void,
    pub zv: *mut zval,
    pub ptr: *mut c_void,
    pub ce: *mut zend_class_entry,
    pub func: *mut zend_function,
}

#[repr(C)]
pub struct _zval_struct {
    pub value: zend_value,
    /// Type tag word. On little-endian targets the low byte is the PHP type
    /// constant (IS_LONG, IS_STRING, …). Use `zval::get_type()` to read it.
    pub u1: u32,
    pub u2: c_uint,
}

pub type zval = _zval_struct;

// -----------------------------------------------------------------------------
// zend_generator, zend_gc_status, sapi_module
// -----------------------------------------------------------------------------

#[repr(C)]
#[repr(C)]
pub struct zend_generator {
    pub std: zend_object,
    pub execute_data: *mut zend_execute_data,
}

#[repr(C)]
pub struct zend_gc_status {
    pub runs: usize,
    pub collected: usize,
}

/// Minimal struct for reading function_handler from active fiber.
/// Layout matches PHP's zend_fcall_info_cache.
#[repr(C)]
pub struct zend_fcall_info_cache {
    pub function_handler: *mut zend_function,
    pub calling_scope: *mut c_void,
    pub called_scope: *mut c_void,
    pub object: *mut c_void,
    pub closure: *mut c_void,
}

/// Minimal struct for reading fci_cache from active fiber.
/// Layout matches PHP's zend_fiber; only fci_cache is accessed.
#[repr(C)]
pub struct zend_fiber {
    pub std: zend_object,
    pub flags: u8,
    _pad1: [u8; 7],
    _context: [u8; 88], // zend_fiber_context
    pub caller: *mut c_void,
    pub previous: *mut c_void,
    _fci: [u8; 48], // zend_fcall_info
    pub fci_cache: zend_fcall_info_cache,
}

#[repr(C)]
pub struct sapi_module_struct {
    pub name: *const c_char,
    pub pretty_name: *const c_char,
    pub activate: Option<unsafe extern "C" fn() -> c_int>,
    pub deactivate: Option<unsafe extern "C" fn() -> c_int>,
    // ... more fields; minimal for sapi_module.name/pretty_name/activate/deactivate
}

#[repr(C)]
pub struct sapi_request_info {
    pub request_method: *const c_char,
    pub query_string: *const c_char,
    pub content_type: *const c_char,
    pub content_length: usize,
    pub argv: *mut *mut c_char,
    pub argc: c_int,
    // ... more fields
}

// -----------------------------------------------------------------------------
// Handler types
// -----------------------------------------------------------------------------

pub type shutdown_func_t = Option<unsafe extern "C" fn(*mut zend_extension)>;
pub type activate_func_t = Option<unsafe extern "C" fn() -> c_int>;
pub type deactivate_func_t = Option<unsafe extern "C" fn() -> c_int>;
pub type message_handler_func_t = Option<unsafe extern "C" fn(c_int, *mut c_void)>;
pub type op_array_handler_func_t = Option<unsafe extern "C" fn(*mut c_void)>;
pub type statement_handler_func_t = Option<unsafe extern "C" fn(*mut zend_execute_data)>;
pub type fcall_begin_handler_func_t = Option<unsafe extern "C" fn(*mut zend_execute_data)>;
pub type fcall_end_handler_func_t = Option<unsafe extern "C" fn(*mut zend_execute_data)>;
pub type op_array_ctor_func_t = Option<unsafe extern "C" fn(*mut c_void)>;
pub type op_array_dtor_func_t = Option<unsafe extern "C" fn(*mut c_void)>;
pub type op_array_persist_calc_func_t = Option<unsafe extern "C" fn(*mut c_void) -> size_t>;
pub type op_array_persist_func_t = Option<unsafe extern "C" fn(*mut c_void, *mut c_void) -> size_t>;

#[repr(C)]
pub struct datadog_php_zif_handler {
    pub name: *const c_char,
    pub name_len: usize,
    pub old_handler: *mut Option<unsafe extern "C" fn(*mut zend_execute_data, *mut zval)>,
    pub new_handler: Option<unsafe extern "C" fn(*mut zend_execute_data, *mut zval)>,
}

#[repr(C)]
pub struct _zend_mm_heap {
    _opaque: [u8; 0],
}
pub type zend_mm_heap = _zend_mm_heap;

#[repr(C)]
pub struct datadog_php_zim_handler {
    pub class_name: *const c_char,
    pub class_name_len: usize,
    pub zif: datadog_php_zif_handler,
}
