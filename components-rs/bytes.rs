use datadog_trace_utils::span::SpanBytes;
use ddcommon_ffi::slice::{AsBytes, CharSlice};
use std::borrow::Cow;
use tinybytes::{Bytes, BytesString, UnderlyingBytes};

/// cbindgen:no-export

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
    Bytes::from_underlying(ZendStringWrapper(zend_str))
}

unsafe fn convert_zend_to_bytes_string(zend_str: *mut ZendString) -> BytesString {
    (*zend_str).refcount += 1; // Increment the reference count to prevent double free

    match String::from_utf8_lossy((*zend_str).as_ref()) {
        Cow::Owned(s) => s.into(),
        Cow::Borrowed(_) => {
            BytesString::from_bytes_unchecked(Bytes::from_underlying(ZendStringWrapper(zend_str)))
        }
    }
}

unsafe fn convert_char_slice_to_bytes_string(slice: CharSlice) -> BytesString {
    match String::from_utf8_lossy(slice.as_bytes().as_ref()) {
        Cow::Owned(s) => s.into(),
        Cow::Borrowed(_) => {
            BytesString::from_bytes_unchecked(Bytes::from_underlying(slice.as_bytes().to_vec()))
        }
    }
}

macro_rules! set_string_field {
    ($ptr:expr, $str:expr, $field:ident) => {{
        if $ptr.is_null() || $str.is_null() {
            return;
        }

        let object = &mut *$ptr;
        object.service = convert_zend_to_bytes_string($str);
    }};
}

// Insert an element in the given hashmap field.
macro_rules! insert_hashmap {
    ($ptr:expr, $key:expr, $value:expr, $field:ident) => {{
        if $ptr.is_null() {
            return;
        }
        let object = &mut *$ptr;
        object.$field.insert($key, $value);
    }};
}

// Remove an element from the given hashmap field.
macro_rules! remove_hashmap {
    ($ptr:expr, $key:expr, $field:ident) => {{
        if $ptr.is_null() {
            return;
        }
        let object = &mut *$ptr;
        object.$field.remove(&$key);
    }};
}

#[no_mangle]
pub unsafe extern "C" fn ddog_set_span_service_zstr(ptr: *mut SpanBytes, str: *mut ZendString) {
    set_string_field!(ptr, str, service);
}

#[no_mangle]
pub unsafe extern "C" fn ddog_set_span_name_zstr(ptr: *mut SpanBytes, slice: *mut ZendString) {
    set_string_field!(ptr, slice, name);
}

#[no_mangle]
pub unsafe extern "C" fn ddog_set_span_resource_zstr(ptr: *mut SpanBytes, slice: *mut ZendString) {
    set_string_field!(ptr, slice, resource);
}

#[no_mangle]
pub unsafe extern "C" fn ddog_set_span_type_zstr(ptr: *mut SpanBytes, slice: *mut ZendString) {
    set_string_field!(ptr, slice, r#type);
}

#[no_mangle]
pub unsafe extern "C" fn ddog_add_span_meta_zstr(
    ptr: *mut SpanBytes,
    key: *mut ZendString,
    val: *mut ZendString,
) {
    insert_hashmap!(
        ptr,
        convert_zend_to_bytes_string(key),
        convert_zend_to_bytes_string(val),
        meta
    );
}

#[no_mangle]
pub unsafe extern "C" fn ddog_add_CharSlice_span_meta_zstr(
    ptr: *mut SpanBytes,
    key: CharSlice,
    val: *mut ZendString,
) {
    insert_hashmap!(
        ptr,
        convert_char_slice_to_bytes_string(key),
        convert_zend_to_bytes_string(val),
        meta
    );
}

#[no_mangle]
pub unsafe extern "C" fn ddog_del_span_meta_zstr(ptr: *mut SpanBytes, key: *mut ZendString) {
    remove_hashmap!(ptr, convert_zend_to_bytes_string(key), meta);
}

#[no_mangle]
pub unsafe extern "C" fn ddog_add_span_metrics_zstr(
    ptr: *mut SpanBytes,
    key: *mut ZendString,
    val: f64,
) {
    insert_hashmap!(ptr, convert_zend_to_bytes_string(key), val, metrics);
}

#[no_mangle]
pub unsafe extern "C" fn ddog_del_span_metrics_zstr(ptr: *mut SpanBytes, key: *mut ZendString) {
    remove_hashmap!(ptr, convert_zend_to_bytes_string(key), metrics);
}

pub unsafe extern "C" fn ddog_add_span_meta_struct_zstr(
    ptr: *mut SpanBytes,
    key: *mut ZendString,
    val: *mut ZendString,
) {
    insert_hashmap!(
        ptr,
        convert_zend_to_bytes_string(key),
        convert_to_bytes(val),
        meta_struct
    );
}
