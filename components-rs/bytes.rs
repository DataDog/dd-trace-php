use datadog_trace_utils::span::SpanBytes;
use std::borrow::Cow;
use tinybytes::{Bytes, BytesString, UnderlyingBytes};

use std::ffi::CString;
use std::os::raw::c_char;

#[repr(C)]
pub struct ZendString {
    pub refcount: u32,
    pub type_info: u32,
    pub h: u64,
    pub len: usize,
    pub val: [u8; 1],
}

struct ZendStringWrapper(*mut ZendString);

unsafe impl Send for ZendStringWrapper {}
unsafe impl Sync for ZendStringWrapper {}

impl AsRef<[u8]> for ZendStringWrapper {
    fn as_ref(&self) -> &[u8] {
        unsafe { (*self.0).as_ref() }
    }
}

impl AsRef<[u8]> for ZendString {
    fn as_ref(&self) -> &[u8] {
        unsafe { std::slice::from_raw_parts((*self).val.as_ptr(), (*self).len) }
    }
}

impl Drop for ZendStringWrapper {
    fn drop(&mut self) {
        extern "C" {
            fn ddog_zend_string_release(s: *mut ZendString);
        }

        unsafe {
            ddog_zend_string_release(self.0);
        }
    }
}

impl UnderlyingBytes for ZendStringWrapper {}

unsafe fn convert_to_bytes(zend_str: *mut ZendString) -> Bytes {
    (*zend_str).refcount += 1; // Increment the reference count to prevent double free

    match String::from_utf8_lossy((*zend_str).as_ref()) {
        Cow::Owned(s) => s.into(),
        Cow::Borrowed(_) => Bytes::from_underlying(ZendStringWrapper(zend_str)),
    }
}

unsafe fn convert_to_bytes_string(zend_str: *mut ZendString) -> BytesString {
    (*zend_str).refcount += 1; // Increment the reference count to prevent double free

    match String::from_utf8_lossy((*zend_str).as_ref()) {
        Cow::Owned(s) => s.into(),
        Cow::Borrowed(_) => {
            BytesString::from_bytes_unchecked(Bytes::from_underlying(ZendStringWrapper(zend_str)))
        }
    }
}

macro_rules! set_string_field {
    ($ptr:expr, $str:expr, $field:ident) => {{
        if $ptr.is_null() || $str.is_null() {
            return;
        }

        let object = &mut *$ptr;
        object.service = convert_to_bytes_string($str);
    }};
}

// Insert an element in the given hashmap field.
macro_rules! insert_hashmap {
    ($ptr:expr, $key:expr, $value:expr, $field:ident) => {{
        if $ptr.is_null() {
            return;
        }
        let object = &mut *$ptr;
        let key = convert_to_bytes_string($key);
        object.$field.insert(key, $value);
    }};
}

// Remove an element from the given hashmap field.
macro_rules! remove_hashmap {
    ($ptr:expr, $key:expr, $field:ident) => {{
        if $ptr.is_null() {
            return;
        }
        let object = &mut *$ptr;
        let key = convert_to_bytes_string($key);
        object.$field.remove(&key);
    }};
}

// Check if an element exists in the given hashmap field.
macro_rules! exists_hashmap {
    ($ptr:expr, $key:expr, $field:ident) => {{
        if $ptr.is_null() {
            return false;
        }
        let object = &mut *$ptr;
        let key = convert_to_bytes_string($key);
        return object.$field.contains_key(&key);
    }};
}

#[no_mangle]
pub unsafe extern "C" fn ddog_set_span_service_(ptr: *mut SpanBytes, str: *mut ZendString) {
    set_string_field!(ptr, str, service);
}

#[no_mangle]
pub unsafe extern "C" fn ddog_set_span_name_(ptr: *mut SpanBytes, slice: *mut ZendString) {
    set_string_field!(ptr, slice, name);
}

#[no_mangle]
pub unsafe extern "C" fn ddog_set_span_resource_(ptr: *mut SpanBytes, slice: *mut ZendString) {
    set_string_field!(ptr, slice, resource);
}

#[no_mangle]
pub unsafe extern "C" fn ddog_set_span_type_(ptr: *mut SpanBytes, slice: *mut ZendString) {
    set_string_field!(ptr, slice, r#type);
}

#[no_mangle]
pub unsafe extern "C" fn ddog_add_span_meta_(
    ptr: *mut SpanBytes,
    key: *mut ZendString,
    val: *mut ZendString,
) {
    insert_hashmap!(ptr, key, convert_to_bytes_string(val), meta);
}

#[no_mangle]
pub unsafe extern "C" fn ddog_del_span_meta_(ptr: *mut SpanBytes, key: *mut ZendString) {
    remove_hashmap!(ptr, key, meta);
}

#[no_mangle]
pub unsafe extern "C" fn ddog_get_span_meta_(
    ptr: *mut SpanBytes,
    key: *mut ZendString,
) -> *mut c_char {
    if ptr.is_null() {
        return std::ptr::null_mut();
    }

    let span = &mut *ptr;

    let key = convert_to_bytes_string(key);

    match span.meta.get(&key) {
        Some(value) => {
            let cstring = match CString::new(value.as_str()) {
                Ok(s) => s,
                Err(_) => CString::new("").unwrap_or_default(),
            };
            cstring.into_raw()
        }
        None => std::ptr::null_mut(),
    }
}

#[no_mangle]
pub unsafe extern "C" fn ddog_has_span_meta_(ptr: *mut SpanBytes, key: *mut ZendString) -> bool {
    exists_hashmap!(ptr, key, meta);
}

#[no_mangle]
pub unsafe extern "C" fn ddog_add_span_metrics_(
    ptr: *mut SpanBytes,
    key: *mut ZendString,
    val: f64,
) {
    insert_hashmap!(ptr, key, val, metrics);
}

#[no_mangle]
pub unsafe extern "C" fn ddog_del_span_metrics_(ptr: *mut SpanBytes, key: *mut ZendString) {
    remove_hashmap!(ptr, key, metrics);
}

#[no_mangle]
pub unsafe extern "C" fn ddog_get_span_metrics_(
    ptr: *mut SpanBytes,
    key: *mut ZendString,
    result: *mut f64,
) -> bool {
    if ptr.is_null() {
        return false;
    }

    let span = &mut *ptr;

    let key = convert_to_bytes_string(key);

    match span.metrics.get(&key) {
        Some(&value) => {
            *result = value;
            true
        }
        None => false,
    }
}

#[no_mangle]
pub unsafe extern "C" fn ddog_has_span_metrics_(ptr: *mut SpanBytes, key: *mut ZendString) -> bool {
    exists_hashmap!(ptr, key, metrics);
}

pub unsafe extern "C" fn ddog_add_span_meta_struct_(
    ptr: *mut SpanBytes,
    key: *mut ZendString,
    val: *mut ZendString,
) {
    insert_hashmap!(ptr, key, convert_to_bytes(val), meta_struct);
}
