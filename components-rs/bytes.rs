use libdd_common_ffi::slice::{AsBytes, CharSlice};
use libdd_tinybytes::{Bytes, BytesString, RefCountedCell, RefCountedCellVTable};
use libdd_trace_utils::span::SpanBytes;
use std::collections::TryReserveError;
use std::borrow::Cow;
use std::ffi::CStr;
use std::os::raw::c_char;
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

// This is copied from String::from_utf8_lossy and mutated to do fallible
// allocations, and then return a Result accordingly.
fn try_from_utf8_lossy(v: &[u8]) -> Result<Cow<'_, str>, TryReserveError> {
    let mut iter = v.utf8_chunks();

    let first_valid = if let Some(chunk) = iter.next() {
        let valid = chunk.valid();
        if chunk.invalid().is_empty() {
            debug_assert_eq!(valid.len(), v.len());
            return Ok(Cow::Borrowed(valid));
        }
        valid
    } else {
        return Ok(Cow::Borrowed(""));
    };

    const REPLACEMENT: &str = "\u{FFFD}";
    const REPLACEMENT_LEN: usize = REPLACEMENT.len();

    let mut res = String::new();
    res.try_reserve(v.len())?;
    res.push_str(first_valid);
    res.try_reserve(REPLACEMENT_LEN)?;
    res.push_str(REPLACEMENT);

    for chunk in iter {
        let valid = chunk.valid();
        res.try_reserve(valid.len())?;
        res.push_str(valid);
        if !chunk.invalid().is_empty() {
            res.try_reserve(REPLACEMENT_LEN)?;
            res.push_str(REPLACEMENT);
        }
    }

    Ok(Cow::Owned(res))
}

fn convert_zend_to_bytes_string(zend_str: &mut ZendString) -> BytesString {
    // We have had OOM reports from this function. Based on the crash reports
    // coming in, it's not a problem in this function itself, and most likely
    // the zend_string that's coming in is garbage.
    // So we're adding defensive checks to get a better idea of which things
    // are wrong.

    // This would cause UB.
    assert!(
        zend_str.len <= isize::MAX as usize,
        "Cannot convert zend_string of length {} to a Rust slice, as it is larger than isize::MAX {}",
        zend_str.len,
        isize::MAX
    );

    // SAFETY:
    //  1. zend_str.val cannot be a null pointer, it's embedded data inside a
    //     reference to a ZendString.
    //  2. It also cannot be mis-aligned, as it's just bytes.
    //  3. Checked above that the length is not above `isize::MAX`.
    let slice: &[u8] = unsafe {
        std::slice::from_raw_parts(
            std::ptr::addr_of!(zend_str.val).cast(),
            zend_str.len
        )
    };

    match try_from_utf8_lossy(slice) {
        Ok(Cow::Owned(s)) => s.into(),

        // SAFETY: the string is valid utf-8 because it hit the Borrowed case.
        Ok(Cow::Borrowed(_)) => unsafe {
            BytesString::from_bytes_unchecked(convert_to_bytes(zend_str))
        },

        // If the length is "reasonable" e.g. less than 2 GiB, we might be in
        // ordinary OOM territory.
        Err(err) if slice.len() <= (i32::MAX as usize) => panic!("failed to allocate memory for non-UTF8 zend_string"),

        // But if it's larger than that, its quite likely a use-after-free and
        // the string is nonsense, which is also why it's not UTF-8 (memory is
        // only allocated in try_from_utf8_lossy if it's not UTF-8).
        Err(err) => panic!("failed to allocate memory for non-UTF8 zend_string of length {}, zend_string was likely corrupted", slice.len()),
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
