use std::alloc::{AllocError, Allocator, Layout};
use datadog_trace_utils::span::{SpanBytes as AllocSpanBytes, AttributeAnyValueBytes as AllocAttributeAnyValueBytes, AttributeArrayValueBytes, SpanEventBytes as AllocSpanEventBytes, SpanLinkBytes as AllocSpanLinkBytes};
use ddcommon_ffi::slice::{AsBytes, CharSlice};
use std::borrow::Cow;
use std::ffi::CStr;
use std::mem;
use hashbrown::{DefaultHashBuilder, HashMap};
use std::os::raw::c_char;
use std::ptr::NonNull;
use serde::Serialize;
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

#[derive(Debug, Default, Serialize)]
pub struct ZendAlloc {
}

unsafe impl Allocator for ZendAlloc {
    #[inline]
    fn allocate(&self, layout: Layout) -> Result<NonNull<[u8]>, AllocError> {
        unsafe {
            // Assuming alignment is not going to be > 4k, all power of two alignments will be properly aligned thanks to the binning of zend_alloc
            // Thus we only care about size
            Ok(NonNull::slice_from_raw_parts(EMALLOC.unwrap_unchecked()(layout.size()), layout.size()))
        }
    }
    
    #[inline]
    unsafe fn grow(&self, ptr: NonNull<u8>, _old_layout: Layout, new_layout: Layout) -> Result<NonNull<[u8]>, AllocError> {
        Ok(NonNull::slice_from_raw_parts(EREALLOC.unwrap_unchecked()(ptr, new_layout.size()), new_layout.size()))
    }
    
    #[inline]
    unsafe fn shrink(&self, ptr: NonNull<u8>, _old_layout: Layout, new_layout: Layout) -> Result<NonNull<[u8]>, AllocError> {
        Ok(NonNull::slice_from_raw_parts(ptr, new_layout.size()))
    }

    #[inline]
    unsafe fn deallocate(&self, ptr: NonNull<u8>, _layout: Layout) {
        unsafe {
            EFREE.unwrap_unchecked()(ptr)
        }
    }
}


#[derive(Default)]
pub struct SpanBytes(AllocSpanBytes<ZendAlloc>);
pub struct AttributeAnyValueBytes(AllocAttributeAnyValueBytes<ZendAlloc>);
pub struct SpanEventBytes(AllocSpanEventBytes<ZendAlloc>);
pub struct SpanLinkBytes(AllocSpanLinkBytes<ZendAlloc>);


static mut DDOG_ADDREF_ZEND_STRING: Option<extern "C" fn(&mut ZendString)> = None;

static mut REFCOUNTED_CELL_VTABLE: Option<RefCountedCellVTable> = None;

static mut EMALLOC: Option<extern "C" fn(usize) -> NonNull<u8>> = None;
static mut EREALLOC: Option<extern "C" fn(NonNull<u8>, usize) -> NonNull<u8>> = None;
static mut EFREE: Option<extern "C" fn(NonNull<u8>)> = None;

#[no_mangle]
pub unsafe extern "C" fn ddog_init_span_func(
    free_func: extern "C" fn(&mut ZendString),
    addref_func: extern "C" fn(&mut ZendString),
    // check fastcall convention
    emalloc: extern "C" fn(usize) -> NonNull<u8>,
    erealloc: extern "C" fn(NonNull<u8>, usize) -> NonNull<u8>,
    efree: extern "C" fn(NonNull<u8>),
) {
    EMALLOC = Some(emalloc);
    EREALLOC = Some(erealloc);
    EFREE = Some(efree);

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
    ptr.0.service = convert_zend_to_bytes_string(str);
}

#[no_mangle]
pub extern "C" fn ddog_set_span_name_zstr(ptr: &mut SpanBytes, str: &mut ZendString) {
    ptr.0.name = convert_zend_to_bytes_string(str);
}

#[no_mangle]
pub extern "C" fn ddog_set_span_resource_zstr(ptr: &mut SpanBytes, str: &mut ZendString) {
    ptr.0.resource = convert_zend_to_bytes_string(str);
}

#[no_mangle]
pub extern "C" fn ddog_set_span_type_zstr(ptr: &mut SpanBytes, str: &mut ZendString) {
    ptr.0.r#type = convert_zend_to_bytes_string(str);
}

#[no_mangle]
pub extern "C" fn ddog_add_span_meta_zstr(
    ptr: &mut SpanBytes,
    key: &mut ZendString,
    val: &mut ZendString,
) {
    ptr.0.meta.insert(
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
    ptr.0.meta.insert(
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
    ptr.0.meta.insert(
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
    ptr.0.meta.insert(
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
    ptr.0.meta.insert(
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
    ptr.0.meta.insert(
        convert_literal_to_bytes_string(key),
        convert_char_slice_to_bytes_string(val),
    );
}

#[no_mangle]
pub extern "C" fn ddog_del_span_meta_zstr(ptr: &mut SpanBytes, key: &mut ZendString) {
    ptr.0.meta.remove(&convert_zend_to_bytes_string(key));
}

#[no_mangle]
pub extern "C" fn ddog_del_span_meta_str(ptr: &mut SpanBytes, key: *const c_char) {
    ptr.0.meta.remove(&convert_literal_to_bytes_string(key));
}

#[no_mangle]
pub extern "C" fn ddog_has_span_meta_zstr(ptr: &mut SpanBytes, key: &mut ZendString) -> bool {
    ptr.0.meta.contains_key(&convert_zend_to_bytes_string(key))
}

#[no_mangle]
pub extern "C" fn ddog_has_span_meta_str(ptr: &mut SpanBytes, key: *const c_char) -> bool {
    ptr.0.meta.contains_key(&convert_literal_to_bytes_string(key))
}

#[no_mangle]
pub extern "C" fn ddog_get_span_meta_str(
    span: &mut SpanBytes,
    key: *const c_char,
) -> CharSlice<'static> {
    match span.0.meta.get(&convert_literal_to_bytes_string(key)) {
        Some(value) => unsafe {
            let string_value = value.as_str();
            CharSlice::from_raw_parts(string_value.as_ptr().cast(), string_value.len())
        },
        None => CharSlice::empty(),
    }
}

#[no_mangle]
pub extern "C" fn ddog_add_span_metrics_zstr(ptr: &mut SpanBytes, key: &mut ZendString, val: f64) {
    ptr.0.metrics.insert(convert_zend_to_bytes_string(key), val);
}

#[no_mangle]
pub extern "C" fn ddog_has_span_metrics_zstr(ptr: &mut SpanBytes, key: &mut ZendString) -> bool {
    ptr.0.metrics.contains_key(&convert_zend_to_bytes_string(key))
}

#[no_mangle]
pub extern "C" fn ddog_del_span_metrics_zstr(ptr: &mut SpanBytes, key: &mut ZendString) {
    ptr.0.metrics.remove(&convert_zend_to_bytes_string(key));
}

#[no_mangle]
pub extern "C" fn ddog_add_span_metrics_str(ptr: &mut SpanBytes, key: *const c_char, val: f64) {
    ptr.0.metrics
        .insert(convert_literal_to_bytes_string(key), val);
}

#[no_mangle]
pub extern "C" fn ddog_get_span_metrics_str(
    ptr: &mut SpanBytes,
    key: *const c_char,
    result: &mut f64,
) -> bool {
    match ptr.0.metrics.get(&convert_literal_to_bytes_string(key)) {
        Some(&value) => {
            *result = value;
            true
        }
        None => false,
    }
}

#[no_mangle]
pub extern "C" fn ddog_del_span_metrics_str(ptr: &mut SpanBytes, key: *const c_char) {
    ptr.0.metrics.remove(&convert_literal_to_bytes_string(key));
}

#[no_mangle]
pub extern "C" fn ddog_add_span_meta_struct_zstr(
    ptr: &mut SpanBytes,
    key: &mut ZendString,
    val: &mut ZendString,
) {
    ptr.0.meta_struct
        .insert(convert_zend_to_bytes_string(key), convert_to_bytes(val));
}

#[no_mangle]
pub extern "C" fn ddog_add_zstr_span_meta_struct_CharSlice(
    ptr: &mut SpanBytes,
    key: &mut ZendString,
    val: CharSlice,
) {
    ptr.0.meta_struct.insert(
        convert_zend_to_bytes_string(key),
        Bytes::copy_from_slice(val.as_bytes()),
    );
}

#[inline]
fn set_string_field(field: &mut BytesString, slice: CharSlice) {
    if slice.is_empty() {
        return;
    }
    *field = convert_char_slice_to_bytes_string(slice);
}

#[inline]
fn get_string_field(field: &BytesString) -> CharSlice<'static> {
    let string = field.as_str();
    unsafe { CharSlice::from_raw_parts(string.as_ptr().cast(), string.len()) }
}

#[inline]
fn insert_hashmap<V>(map: &mut HashMap<BytesString, V, DefaultHashBuilder, ZendAlloc>, key: CharSlice, value: V) {
    if key.is_empty() {
        return;
    }
    let bytes_str_key = convert_char_slice_to_bytes_string(key);
    map.insert(bytes_str_key, value);
}

#[inline]
fn remove_hashmap<V>(map: &mut HashMap<BytesString, V, DefaultHashBuilder, ZendAlloc>, key: CharSlice) {
    let bytes_str_key = convert_char_slice_to_bytes_string(key);
    map.remove(&bytes_str_key);
}

#[inline]
fn exists_hashmap<V>(map: &HashMap<BytesString, V, DefaultHashBuilder, ZendAlloc>, key: CharSlice) -> bool {
    let bytes_str_key = convert_char_slice_to_bytes_string(key);
    map.contains_key(&bytes_str_key)
}

#[allow(clippy::missing_safety_doc)]
unsafe fn get_hashmap_keys<V>(
    map: &HashMap<BytesString, V, DefaultHashBuilder, ZendAlloc>,
    out_count: &mut usize,
) -> *mut CharSlice<'static> {
    let mut keys: Vec<&str> = map.keys().map(|b| b.as_str()).collect();
    keys.sort_unstable();

    let mut slices = Vec::with_capacity(keys.len());
    for key in keys {
        slices.push(CharSlice::from_raw_parts(key.as_ptr().cast(), key.len()));
    }

    *out_count = slices.len();
    Box::into_raw(slices.into_boxed_slice()) as *mut CharSlice<'static>
}

#[allow(clippy::missing_safety_doc)]
unsafe fn new_vector_item<T: Default>(vec: &mut Vec<T, ZendAlloc>) -> &mut T {
    vec.push(T::default());
    vec.last_mut().unwrap_unchecked()
}

#[allow(clippy::missing_safety_doc)]
unsafe fn new_vector_push<T>(vec: &mut Vec<T, ZendAlloc>, el: T) -> &mut T {
    vec.push(el);
    vec.last_mut().unwrap_unchecked()
}

#[no_mangle]
fn set_event_attribute(
    event: &mut SpanEventBytes,
    key: CharSlice,
    new_item: AttributeArrayValueBytes,
) {
    let bytes_str_key = convert_char_slice_to_bytes_string(key);

    // remove any previous
    let previous = event.0.attributes.remove(&bytes_str_key);

    // merge old + new
    let merged = match previous {
        None => AllocAttributeAnyValueBytes::<ZendAlloc>::SingleValue(new_item),
        Some(AllocAttributeAnyValueBytes::SingleValue(x)) => {
            let mut v = Vec::new_in(ZendAlloc{});
            v.push(x);
            v.push(new_item);
            AllocAttributeAnyValueBytes::<ZendAlloc>::Array(v)
        }
        Some(AllocAttributeAnyValueBytes::Array(mut arr)) => {
            arr.push(new_item);
            AllocAttributeAnyValueBytes::<ZendAlloc>::Array(arr)
        }
    };

    event.0.attributes.insert(bytes_str_key, merged);
}

// ------------------ TracesBytes ------------------

pub struct TraceBytes(Vec<SpanBytes, ZendAlloc>);
pub struct TracesBytes(Vec<TraceBytes, ZendAlloc>);

#[no_mangle]
pub unsafe extern "C" fn ddog_get_traces() -> Box<TracesBytes> {
    std::mem::transmute(Box::new_in(TracesBytes(Vec::new_in(ZendAlloc{})), ZendAlloc{}))
}

#[no_mangle]
pub unsafe extern "C" fn ddog_free_traces(traces: Box<TracesBytes>) {
    let _: Box<TracesBytes, ZendAlloc> = std::mem::transmute(traces);
}

#[no_mangle]
pub extern "C" fn ddog_get_traces_size(traces: &TracesBytes) -> usize {
    traces.0.len()
}

#[no_mangle]
pub extern "C" fn ddog_get_trace(traces: &mut TracesBytes, index: usize) -> *mut TraceBytes {
    if index >= traces.0.len() {
        return std::ptr::null_mut();
    }

    unsafe { traces.0.get_unchecked_mut(index) as *mut TraceBytes }
}

// ------------------ TraceBytes ------------------

#[no_mangle]
pub extern "C" fn ddog_traces_new_trace(traces: &mut TracesBytes) -> &mut TraceBytes {
    unsafe { std::mem::transmute(new_vector_push(&mut traces.0, TraceBytes(Vec::new_in(ZendAlloc{})))) }
}

#[no_mangle]
pub extern "C" fn ddog_get_trace_size(trace: &TraceBytes) -> usize {
    trace.0.len()
}

#[no_mangle]
pub extern "C" fn ddog_get_span(trace: &mut TraceBytes, index: usize) -> *mut SpanBytes {
    if index >= trace.0.len() {
        return std::ptr::null_mut();
    }

    unsafe { trace.0.get_unchecked_mut(index) as *mut SpanBytes }
}

// ------------------- SpanBytes -------------------

#[no_mangle]
pub extern "C" fn ddog_trace_new_span(trace: &mut TraceBytes) -> &mut SpanBytes {
    unsafe { std::mem::transmute(new_vector_item(&mut trace.0)) }
}

#[no_mangle]
pub extern "C" fn ddog_span_debug_log(span: &SpanBytes) -> CharSlice<'static> {
    unsafe {
        let debug_str = format!("{:?}\0", &span.0);
        let len = debug_str.len() - 1;
        CharSlice::from_raw_parts(debug_str.leak().as_ptr().cast(), len)
    }
}

#[no_mangle]
pub extern "C" fn ddog_free_charslice(slice: CharSlice<'static>) {
    let (ptr, len) = slice.as_raw_parts();

    if len == 0 || ptr.is_null() {
        return;
    }

    // SAFETY: we assume this pointer came from `String::leak`
    unsafe {
        let _ = String::from_raw_parts(ptr.cast_mut().cast(), len, len);
    }
}

#[no_mangle]
pub extern "C" fn ddog_set_span_service(span: &mut SpanBytes, slice: CharSlice) {
    set_string_field(&mut span.0.service, slice);
}

#[no_mangle]
pub extern "C" fn ddog_get_span_service(span: &mut SpanBytes) -> CharSlice<'static> {
    get_string_field(&span.0.service)
}

#[no_mangle]
pub extern "C" fn ddog_set_span_name(span: &mut SpanBytes, slice: CharSlice) {
    set_string_field(&mut span.0.name, slice);
}

#[no_mangle]
pub extern "C" fn ddog_get_span_name(span: &mut SpanBytes) -> CharSlice<'static> {
    get_string_field(&span.0.name)
}

#[no_mangle]
pub extern "C" fn ddog_set_span_resource(span: &mut SpanBytes, slice: CharSlice) {
    set_string_field(&mut span.0.resource, slice);
}

#[no_mangle]
pub extern "C" fn ddog_get_span_resource(span: &mut SpanBytes) -> CharSlice<'static> {
    get_string_field(&span.0.resource)
}

#[no_mangle]
pub extern "C" fn ddog_set_span_type(span: &mut SpanBytes, slice: CharSlice) {
    set_string_field(&mut span.0.r#type, slice);
}

#[no_mangle]
pub extern "C" fn ddog_get_span_type(span: &mut SpanBytes) -> CharSlice<'static> {
    get_string_field(&span.0.r#type)
}

#[no_mangle]
pub extern "C" fn ddog_set_span_trace_id(span: &mut SpanBytes, value: u64) {
    span.0.trace_id = value;
}

#[no_mangle]
pub extern "C" fn ddog_get_span_trace_id(span: &mut SpanBytes) -> u64 {
    span.0.trace_id
}

#[no_mangle]
pub extern "C" fn ddog_set_span_id(span: &mut SpanBytes, value: u64) {
    span.0.span_id = value;
}

#[no_mangle]
pub extern "C" fn ddog_get_span_id(span: &mut SpanBytes) -> u64 {
    span.0.span_id
}

#[no_mangle]
pub extern "C" fn ddog_set_span_parent_id(span: &mut SpanBytes, value: u64) {
    span.0.parent_id = value;
}

#[no_mangle]
pub extern "C" fn ddog_get_span_parent_id(span: &mut SpanBytes) -> u64 {
    span.0.parent_id
}

#[no_mangle]
pub extern "C" fn ddog_set_span_start(span: &mut SpanBytes, value: i64) {
    span.0.start = value;
}

#[no_mangle]
pub extern "C" fn ddog_get_span_start(span: &mut SpanBytes) -> i64 {
    span.0.start
}

#[no_mangle]
pub extern "C" fn ddog_set_span_duration(span: &mut SpanBytes, value: i64) {
    span.0.duration = value;
}

#[no_mangle]
pub extern "C" fn ddog_get_span_duration(span: &mut SpanBytes) -> i64 {
    span.0.duration
}

#[no_mangle]
pub extern "C" fn ddog_set_span_error(span: &mut SpanBytes, value: i32) {
    span.0.error = value;
}

#[no_mangle]
pub extern "C" fn ddog_get_span_error(span: &mut SpanBytes) -> i32 {
    span.0.error
}

#[no_mangle]
pub extern "C" fn ddog_add_span_meta(span: &mut SpanBytes, key: CharSlice, value: CharSlice) {
    insert_hashmap(
        &mut span.0.meta,
        key,
        BytesString::from_slice(value.as_bytes()).unwrap_or_default(),
    );
}

#[no_mangle]
pub extern "C" fn ddog_del_span_meta(span: &mut SpanBytes, key: CharSlice) {
    remove_hashmap(&mut span.0.meta, key);
}

#[no_mangle]
pub extern "C" fn ddog_get_span_meta(span: &mut SpanBytes, key: CharSlice) -> CharSlice<'static> {
    let bytes_str_key = convert_char_slice_to_bytes_string(key);
    match span.0.meta.get(&bytes_str_key) {
        Some(value) => unsafe {
            CharSlice::from_raw_parts(value.as_str().as_ptr().cast(), value.as_str().len())
        },
        None => CharSlice::empty(),
    }
}

#[no_mangle]
pub extern "C" fn ddog_has_span_meta(span: &mut SpanBytes, key: CharSlice) -> bool {
    exists_hashmap(&span.0.meta, key)
}

#[no_mangle]
pub extern "C" fn ddog_span_meta_get_keys(
    span: &mut SpanBytes,
    out_count: &mut usize,
) -> *mut CharSlice<'static> {
    unsafe { get_hashmap_keys(&span.0.meta, out_count) }
}

#[no_mangle]
pub extern "C" fn ddog_add_span_metrics(span: &mut SpanBytes, key: CharSlice, val: f64) {
    insert_hashmap(&mut span.0.metrics, key, val);
}

#[no_mangle]
pub extern "C" fn ddog_del_span_metrics(span: &mut SpanBytes, key: CharSlice) {
    remove_hashmap(&mut span.0.metrics, key);
}

#[no_mangle]
pub extern "C" fn ddog_get_span_metrics(
    span: &mut SpanBytes,
    key: CharSlice,
    result: &mut f64,
) -> bool {
    let bytes_str_key = convert_char_slice_to_bytes_string(key);
    match span.0.metrics.get(&bytes_str_key) {
        Some(&value) => {
            *result = value;
            true
        }
        None => false,
    }
}

#[no_mangle]
pub extern "C" fn ddog_has_span_metrics(span: &mut SpanBytes, key: CharSlice) -> bool {
    exists_hashmap(&span.0.metrics, key)
}

#[no_mangle]
pub extern "C" fn ddog_span_metrics_get_keys(
    span: &mut SpanBytes,
    out_count: &mut usize,
) -> *mut CharSlice<'static> {
    unsafe { get_hashmap_keys(&span.0.metrics, out_count) }
}

#[no_mangle]
pub extern "C" fn ddog_add_span_meta_struct(span: &mut SpanBytes, key: CharSlice, val: CharSlice) {
    insert_hashmap(
        &mut span.0.meta_struct,
        key,
        Bytes::copy_from_slice(val.as_bytes()),
    );
}

#[no_mangle]
pub extern "C" fn ddog_del_span_meta_struct(span: &mut SpanBytes, key: CharSlice) {
    remove_hashmap(&mut span.0.meta_struct, key);
}

#[no_mangle]
pub extern "C" fn ddog_get_span_meta_struct(
    span: &mut SpanBytes,
    key: CharSlice,
) -> CharSlice<'static> {
    let bytes_str_key = convert_char_slice_to_bytes_string(key);
    match span.0.meta_struct.get(&bytes_str_key) {
        Some(value) => unsafe { CharSlice::from_raw_parts(value.as_ptr().cast(), value.len()) },
        None => CharSlice::empty(),
    }
}

#[no_mangle]
pub extern "C" fn ddog_has_span_meta_struct(span: &mut SpanBytes, key: CharSlice) -> bool {
    exists_hashmap(&span.0.meta_struct, key)
}

#[no_mangle]
pub extern "C" fn ddog_span_meta_struct_get_keys(
    span: &mut SpanBytes,
    out_count: &mut usize,
) -> *mut CharSlice<'static> {
    unsafe { get_hashmap_keys(&span.0.meta_struct, out_count) }
}

#[no_mangle]
#[allow(clippy::missing_safety_doc)]
pub unsafe extern "C" fn ddog_span_free_keys_ptr(keys_ptr: *mut CharSlice<'static>, count: usize) {
    if keys_ptr.is_null() || count == 0 {
        return;
    }

    Vec::from_raw_parts(keys_ptr, count, count);
}

// ------------------- SpanLinkBytes -------------------

#[no_mangle]
pub extern "C" fn ddog_span_new_link(span: &mut SpanBytes) -> &mut SpanLinkBytes {
    unsafe { std::mem::transmute(new_vector_item(&mut span.0.span_links)) }
}

#[no_mangle]
pub extern "C" fn ddog_set_link_tracestate(link: &mut SpanLinkBytes, slice: CharSlice) {
    set_string_field(&mut link.0.tracestate, slice);
}

#[no_mangle]
pub extern "C" fn ddog_set_link_trace_id(link: &mut SpanLinkBytes, value: u64) {
    link.0.trace_id = value;
}

#[no_mangle]
pub extern "C" fn ddog_set_link_trace_id_high(link: &mut SpanLinkBytes, value: u64) {
    link.0.trace_id_high = value;
}

#[no_mangle]
pub extern "C" fn ddog_set_link_span_id(link: &mut SpanLinkBytes, value: u64) {
    link.0.span_id = value;
}

#[no_mangle]
pub extern "C" fn ddog_set_link_flags(link: &mut SpanLinkBytes, value: u64) {
    link.0.flags = value;
}

#[no_mangle]
pub extern "C" fn ddog_add_link_attributes(
    link: &mut SpanLinkBytes,
    key: CharSlice,
    val: CharSlice,
) {
    insert_hashmap(
        &mut link.0.attributes,
        key,
        BytesString::from_slice(val.as_bytes()).unwrap_or_default(),
    );
}

// ------------------- SpanEventBytes -------------------

#[no_mangle]
pub extern "C" fn ddog_span_new_event(span: &mut SpanBytes) -> &mut SpanEventBytes {
    unsafe { std::mem::transmute(new_vector_item(&mut span.0.span_events)) }
}

#[no_mangle]
pub extern "C" fn ddog_set_event_name(event: &mut SpanEventBytes, slice: CharSlice) {
    set_string_field(&mut event.0.name, slice);
}

#[no_mangle]
pub extern "C" fn ddog_set_event_time(event: &mut SpanEventBytes, val: u64) {
    event.0.time_unix_nano = val;
}

#[no_mangle]
pub extern "C" fn ddog_add_event_attributes_str(
    event: &mut SpanEventBytes,
    key: CharSlice,
    val: CharSlice,
) {
    set_event_attribute(
        event,
        key,
        AttributeArrayValueBytes::String(convert_char_slice_to_bytes_string(val)),
    );
}

#[no_mangle]
pub extern "C" fn ddog_add_event_attributes_bool(
    event: &mut SpanEventBytes,
    key: CharSlice,
    val: bool,
) {
    set_event_attribute(event, key, AttributeArrayValueBytes::Boolean(val));
}

#[no_mangle]
pub extern "C" fn ddog_add_event_attributes_int(
    event: &mut SpanEventBytes,
    key: CharSlice,
    val: i64,
) {
    set_event_attribute(event, key, AttributeArrayValueBytes::Integer(val));
}

#[no_mangle]
pub extern "C" fn ddog_add_event_attributes_float(
    event: &mut SpanEventBytes,
    key: CharSlice,
    val: f64,
) {
    set_event_attribute(event, key, AttributeArrayValueBytes::Double(val));
}

// ------------------- Export Functions -------------------

#[no_mangle]
pub extern "C" fn ddog_serialize_trace_into_c_string(trace: &mut TraceBytes) -> CharSlice<'static> {
    match rmp_serde::encode::to_vec_named(&vec![unsafe { std::mem::transmute::<_, &mut Vec<AllocSpanBytes>>(trace) }]) {
        Ok(vec) => {
            let boxed_str = vec.into_boxed_slice();
            let boxed_len = boxed_str.len();

            let leaked_ptr = Box::into_raw(boxed_str) as *const c_char;

            unsafe { CharSlice::from_raw_parts(leaked_ptr, boxed_len) }
        }
        Err(_) => CharSlice::empty(),
    }
}
