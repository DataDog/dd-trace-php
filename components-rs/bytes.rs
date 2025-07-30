use datadog_trace_utils::span::SpanBytes;
use ddcommon_ffi::slice::{AsBytes, CharSlice};
use std::borrow::Cow;
use std::ffi::CStr;
use std::os::raw::c_char;
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

fn convert_literal_to_bytes(string: *const c_char) -> Bytes {
    unsafe {
        let cstring = CStr::from_ptr(string);

        Bytes::from_static(cstring.to_bytes())
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

fn convert_literal_to_bytes_string(string: *const c_char) -> BytesString {
    unsafe {
        let cstring = CStr::from_ptr(string);

        match String::from_utf8_lossy(cstring.to_bytes()) {
            Cow::Owned(s) => s.into(),
            Cow::Borrowed(s) => BytesString::from_static(s),
        }
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
pub extern "C" fn ddog_add_zstr_span_meta_str(
    ptr: &mut SpanBytes,
    key: &mut ZendString,
    val: *const c_char,
) {
    ptr.meta.insert(
        convert_zend_to_bytes_string(key),
        convert_literal_to_bytes_string(val),
    );
}

#[no_mangle]
pub extern "C" fn ddog_add_str_span_meta_str(
    ptr: &mut SpanBytes,
    key: *const c_char,
    val: *const c_char,
) {
    ptr.meta.insert(
        convert_literal_to_bytes_string(key),
        convert_literal_to_bytes_string(val),
    );
}

#[no_mangle]
pub extern "C" fn ddog_add_str_span_meta_zstr(
    ptr: &mut SpanBytes,
    key: *const c_char,
    val: &mut ZendString,
) {
    ptr.meta.insert(
        convert_literal_to_bytes_string(key),
        convert_zend_to_bytes_string(val),
    );
}

#[no_mangle]
pub extern "C" fn ddog_add_str_span_meta_CharSlice(
    ptr: &mut SpanBytes,
    key: *const c_char,
    val: CharSlice,
) {
    ptr.meta.insert(
        convert_literal_to_bytes_string(key),
        convert_char_slice_to_bytes_string(val),
    );
}

#[no_mangle]
pub extern "C" fn ddog_del_span_meta_zstr(ptr: &mut SpanBytes, key: &mut ZendString) {
    ptr.meta.remove(&convert_zend_to_bytes_string(key));
}

#[no_mangle]
pub extern "C" fn ddog_del_span_meta_str(ptr: &mut SpanBytes, key: *const c_char) {
    ptr.meta.remove(&convert_literal_to_bytes_string(key));
}

#[no_mangle]
pub extern "C" fn ddog_has_span_meta_zstr(ptr: &mut SpanBytes, key: &mut ZendString) -> bool {
    ptr.meta.contains_key(&convert_zend_to_bytes_string(key))
}

#[no_mangle]
pub extern "C" fn ddog_has_span_meta_str(ptr: &mut SpanBytes, key: *const c_char) -> bool {
    ptr.meta.contains_key(&convert_literal_to_bytes_string(key))
}

#[no_mangle]
pub extern "C" fn ddog_get_span_meta_str(
    span: &mut SpanBytes,
    key: *const c_char,
) -> CharSlice<'static> {
    match span.meta.get(&convert_literal_to_bytes_string(key)) {
        Some(value) => unsafe {
            let string_value = value.as_str();
            CharSlice::from_raw_parts(string_value.as_ptr().cast(), string_value.len())
        },
        None => CharSlice::empty(),
    }
}

#[no_mangle]
pub extern "C" fn ddog_add_span_metrics_zstr(ptr: &mut SpanBytes, key: &mut ZendString, val: f64) {
    ptr.metrics.insert(convert_zend_to_bytes_string(key), val);
}

#[no_mangle]
pub extern "C" fn ddog_has_span_metrics_zstr(ptr: &mut SpanBytes, key: &mut ZendString) -> bool {
    ptr.metrics.contains_key(&convert_zend_to_bytes_string(key))
}

#[no_mangle]
pub extern "C" fn ddog_del_span_metrics_zstr(ptr: &mut SpanBytes, key: &mut ZendString) {
    ptr.metrics.remove(&convert_zend_to_bytes_string(key));
}

#[no_mangle]
pub extern "C" fn ddog_add_span_metrics_str(ptr: &mut SpanBytes, key: *const c_char, val: f64) {
    ptr.metrics
        .insert(convert_literal_to_bytes_string(key), val);
}

#[no_mangle]
pub extern "C" fn ddog_get_span_metrics_str(
    ptr: &mut SpanBytes,
    key: *const c_char,
    result: &mut f64,
) -> bool {
    match ptr.metrics.get(&convert_literal_to_bytes_string(key)) {
        Some(&value) => {
            *result = value;
            true
        }
        None => false,
    }
}

#[no_mangle]
pub extern "C" fn ddog_del_span_metrics_str(ptr: &mut SpanBytes, key: *const c_char) {
    ptr.metrics.remove(&convert_literal_to_bytes_string(key));
}

#[no_mangle]
pub extern "C" fn ddog_add_span_meta_struct_zstr(
    ptr: &mut SpanBytes,
    key: &mut ZendString,
    val: &mut ZendString,
) {
    ptr.meta_struct
        .insert(convert_zend_to_bytes_string(key), convert_to_bytes(val));
}

#[no_mangle]
pub extern "C" fn ddog_add_zstr_span_meta_struct_CharSlice(
    ptr: &mut SpanBytes,
    key: &mut ZendString,
    val: CharSlice,
) {
    ptr.meta_struct.insert(
        convert_zend_to_bytes_string(key),
        Bytes::copy_from_slice(val.as_bytes()),
    );
}
