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

extern "C" {
    fn ddog_free_zend_string(s: *mut ZendString);
    fn ddog_incr_refcount_zend_string(s: *mut ZendString);
}

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
        unsafe {
            ddog_free_zend_string(self.0);
        }
    }
}

impl UnderlyingBytes for ZendStringWrapper {}

fn convert_to_bytes(zend_str: *mut ZendString) -> Bytes {
    unsafe {
        ddog_incr_refcount_zend_string(zend_str); // Increment the reference count to prevent double free
        Bytes::from_underlying(ZendStringWrapper(zend_str))
    }
}

fn convert_zend_to_bytes_string(zend_str: *mut ZendString) -> BytesString {
    unsafe {
        ddog_incr_refcount_zend_string(zend_str); // Increment the reference count to prevent double free

        match String::from_utf8_lossy((*zend_str).as_ref()) {
            Cow::Owned(s) => s.into(),
            Cow::Borrowed(_) => BytesString::from_bytes_unchecked(Bytes::from_underlying(
                ZendStringWrapper(zend_str),
            )),
        }
    }
}

fn convert_char_slice_to_bytes_string(slice: CharSlice) -> BytesString {
    match String::from_utf8_lossy(slice.as_bytes().as_ref()) {
        Cow::Owned(s) => s.into(),
        Cow::Borrowed(_) => unsafe {
            BytesString::from_bytes_unchecked(Bytes::from_underlying(slice.as_bytes().to_vec()))
        },
    }
}

#[no_mangle]
pub extern "C" fn ddog_set_span_service_zstr(ptr: &mut SpanBytes, str: &mut ZendString) {
    ptr.service = convert_zend_to_bytes_string(str);
}

#[no_mangle]
pub extern "C" fn ddog_set_span_name_zstr(ptr: &mut SpanBytes, str: &mut ZendString) {
    ptr.name = convert_zend_to_bytes_string(str);
}

#[no_mangle]
pub extern "C" fn ddog_set_span_resource_zstr(ptr: &mut SpanBytes, str: &mut ZendString) {
    ptr.resource = convert_zend_to_bytes_string(str);
}

#[no_mangle]
pub extern "C" fn ddog_set_span_type_zstr(ptr: &mut SpanBytes, str: &mut ZendString) {
    ptr.r#type = convert_zend_to_bytes_string(str);
}

#[no_mangle]
pub extern "C" fn ddog_add_span_meta_zstr(
    ptr: &mut SpanBytes,
    key: &mut ZendString,
    val: &mut ZendString,
) {
    ptr.meta.insert(
        convert_zend_to_bytes_string(key),
        convert_zend_to_bytes_string(val),
    );
}

#[no_mangle]
pub extern "C" fn ddog_add_CharSlice_span_meta_zstr(
    ptr: &mut SpanBytes,
    key: CharSlice,
    val: &mut ZendString,
) {
    ptr.meta.insert(
        convert_char_slice_to_bytes_string(key),
        convert_zend_to_bytes_string(val),
    );
}

#[no_mangle]
pub extern "C" fn ddog_del_span_meta_zstr(ptr: &mut SpanBytes, key: &mut ZendString) {
    ptr.meta.remove(&convert_zend_to_bytes_string(key));
}

#[no_mangle]
pub extern "C" fn ddog_add_span_metrics_zstr(ptr: &mut SpanBytes, key: &mut ZendString, val: f64) {
    ptr.metrics.insert(convert_zend_to_bytes_string(key), val);
}

#[no_mangle]
pub extern "C" fn ddog_del_span_metrics_zstr(ptr: &mut SpanBytes, key: &mut ZendString) {
    ptr.metrics.remove(&convert_zend_to_bytes_string(key));
}

pub extern "C" fn ddog_add_span_meta_struct_zstr(
    ptr: &mut SpanBytes,
    key: &mut ZendString,
    val: &mut ZendString,
) {
    ptr.meta_struct
        .insert(convert_zend_to_bytes_string(key), convert_to_bytes(val));
}