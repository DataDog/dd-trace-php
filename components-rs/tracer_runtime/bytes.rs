use datadog_sidecar_ffi::span::Attributes;
use libdd_common_ffi::slice::CharSlice;
use libdd_tinybytes::{Bytes, BytesString, RefCountedCell, RefCountedCellVTable};
use libdd_trace_utils::span::v1::{AttributeValueBytes, SpanBytes};
use std::borrow::Cow;
use std::ptr::NonNull;

/// cbindgen:no-export
#[repr(C)]
pub struct ZendString {
    pub refcount: u32,
    pub type_info: u32,
    pub h: u64,
    pub len: usize,
    pub val: [u8; 1],
}

#[repr(transparent)]
pub struct OwnedZendString(pub NonNull<ZendString>);

/// cbindgen:ignore
pub type MaybeOwnedZendString = Option<OwnedZendString>;

impl OwnedZendString {
    pub fn from_copy(mut ptr: NonNull<ZendString>) -> Self {
        unsafe { DDOG_ADDREF_ZEND_STRING.unwrap_unchecked()(ptr.as_mut()) };
        OwnedZendString(ptr)
    }
}

impl Drop for OwnedZendString {
    fn drop(&mut self) {
        unsafe {
            DDOG_FREE_ZEND_STRING.unwrap_unchecked()(OwnedZendString(self.0));
        }
    }
}

impl Clone for OwnedZendString {
    fn clone(&self) -> Self {
        OwnedZendString::from_copy(self.0)
    }
}

static mut DDOG_ADDREF_ZEND_STRING: Option<extern "C" fn(&mut ZendString)> = None;
static mut DDOG_INIT_ZEND_STRING: Option<extern "C" fn(CharSlice) -> OwnedZendString> = None;
static mut DDOG_FREE_ZEND_STRING: Option<extern "C" fn(OwnedZendString)> = None;

static mut REFCOUNTED_CELL_VTABLE: Option<RefCountedCellVTable> = None;

#[no_mangle]
pub unsafe extern "C" fn ddog_init_span_func(
    free_func: extern "C" fn(OwnedZendString),
    addref_func: extern "C" fn(&mut ZendString),
    init_func: extern "C" fn(CharSlice) -> OwnedZendString,
) {
    DDOG_ADDREF_ZEND_STRING = Some(addref_func);
    DDOG_INIT_ZEND_STRING = Some(init_func);
    DDOG_FREE_ZEND_STRING = Some(free_func);

    REFCOUNTED_CELL_VTABLE = Some(RefCountedCellVTable {
        clone,
        drop: unsafe { std::mem::transmute(free_func as *const fn(s: NonNull<()>)) },
    });

    unsafe fn clone(data: NonNull<()>) -> RefCountedCell {
        DDOG_ADDREF_ZEND_STRING.unwrap_unchecked()(data.cast().as_mut());
        RefCountedCell::from_raw(data, REFCOUNTED_CELL_VTABLE.as_ref().unwrap_unchecked())
    }
}

pub fn u8_from_zend_string(str: &ZendString) -> &[u8] {
    unsafe { std::slice::from_raw_parts(str.val.as_ptr(), str.len) }
}

impl AsRef<[u8]> for OwnedZendString {
    fn as_ref(&self) -> &[u8] {
        unsafe { self.0.as_ref() }.as_ref()
    }
}

impl AsRef<[u8]> for ZendString {
    fn as_ref(&self) -> &[u8] {
        u8_from_zend_string(self)
    }
}

impl Into<OwnedZendString> for &str {
    fn into(self) -> OwnedZendString {
        init_zend_string(self.as_bytes())
    }
}

impl Into<OwnedZendString> for &[u8] {
    fn into(self) -> OwnedZendString {
        init_zend_string(self)
    }
}

pub fn init_zend_string(str: &[u8]) -> OwnedZendString {
    unsafe { DDOG_INIT_ZEND_STRING.unwrap_unchecked()(CharSlice::from_bytes(str)) }
}

pub unsafe fn dangling_zend_string() -> OwnedZendString {
    OwnedZendString(NonNull::dangling())
}

fn convert_to_bytes(zend_str: &mut ZendString) -> Bytes {
    unsafe {
        DDOG_ADDREF_ZEND_STRING.unwrap_unchecked()(zend_str); // Increment the reference count to prevent double free
        Bytes::from_raw_refcount(
            (&zend_str.val.as_slice()[0]).into(),
            zend_str.len,
            RefCountedCell::from_raw(
                NonNull::from(zend_str).cast(),
                REFCOUNTED_CELL_VTABLE.as_ref().unwrap_unchecked(),
            ),
        )
    }
}

fn convert_zend_to_bytes_string(zend_str: &mut ZendString) -> BytesString {
    unsafe {
        match String::from_utf8_lossy(std::slice::from_raw_parts(
            zend_str.val.as_ptr(),
            zend_str.len,
        )) {
            Cow::Owned(s) => s.into(),
            Cow::Borrowed(_) => BytesString::from_bytes_unchecked(convert_to_bytes(zend_str)),
        }
    }
}

// PHP-specific additions to the V1 builder FFI (`datadog_sidecar_ffi::span`): zero-copy
// zend_string fields.

#[no_mangle]
pub extern "C" fn ddog_set_span_service_zstr(span: &mut SpanBytes, str: &mut ZendString) {
    span.service = convert_zend_to_bytes_string(str);
}

#[no_mangle]
pub extern "C" fn ddog_set_span_name_zstr(span: &mut SpanBytes, str: &mut ZendString) {
    span.name = convert_zend_to_bytes_string(str);
}

#[no_mangle]
pub extern "C" fn ddog_set_span_resource_zstr(span: &mut SpanBytes, str: &mut ZendString) {
    span.resource = convert_zend_to_bytes_string(str);
}

#[no_mangle]
pub extern "C" fn ddog_set_span_type_zstr(span: &mut SpanBytes, str: &mut ZendString) {
    span.r#type = convert_zend_to_bytes_string(str);
}

/// String attribute sharing both zend strings (zero-copy).
#[no_mangle]
pub extern "C" fn ddog_attributes_add_zstr(
    attrs: &mut Attributes,
    key: &mut ZendString,
    value: &mut ZendString,
) {
    attrs.0.insert(
        convert_zend_to_bytes_string(key),
        AttributeValueBytes::String(convert_zend_to_bytes_string(value)),
    );
}

/// Double attribute under a shared zend string key (zero-copy).
#[no_mangle]
pub extern "C" fn ddog_attributes_add_double_zstr(
    attrs: &mut Attributes,
    key: &mut ZendString,
    value: f64,
) {
    attrs.0.insert(convert_zend_to_bytes_string(key), AttributeValueBytes::Float(value));
}
