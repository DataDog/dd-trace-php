use datadog_trace_utils::span::SpanBytes;
use ddcommon_ffi::slice::{AsBytes, CharSlice};
use std::borrow::Cow;
use std::ptr::NonNull;
use tinybytes::{Bytes, BytesString, RefCountedCell, RefCountedCellVTable};

/// cbindgen:no-export
#[repr(C)]
pub struct ZendString {
    pub refcount: u32,
    pub type_info: u32,
    pub h: u64,
    pub len: usize,
    pub val: [u8; 1],
}

static mut DDOG_ADDREF_ZEND_STRING: Option<extern "C" fn(&mut ZendString)> = None;

static mut REFCOUNTED_CELL_VTABLE: Option<RefCountedCellVTable> = None;

#[no_mangle]
pub unsafe extern "C" fn ddog_init_span_func(
    free_func: extern "C" fn(&mut ZendString),
    addref_func: extern "C" fn(&mut ZendString),
) {
    DDOG_ADDREF_ZEND_STRING = Some(addref_func);

    REFCOUNTED_CELL_VTABLE = Some(RefCountedCellVTable {
        clone,
        drop: unsafe { std::mem::transmute(free_func as *const fn(s: NonNull<()>)) },
    });

    unsafe fn clone(data: NonNull<()>) -> RefCountedCell {
        DDOG_ADDREF_ZEND_STRING.unwrap_unchecked()(data.cast().as_mut());
        RefCountedCell::from_raw(data, REFCOUNTED_CELL_VTABLE.as_ref().unwrap_unchecked())
    }
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

fn convert_char_slice_to_bytes_string(slice: CharSlice) -> BytesString {
    match String::from_utf8_lossy(slice.as_bytes().as_ref()) {
        Cow::Owned(s) => s.into(),
        Cow::Borrowed(_) => unsafe {
            BytesString::from_bytes_unchecked(slice.as_bytes().to_vec().into())
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
