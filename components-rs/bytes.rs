use datadog_sidecar_ffi::span::{
    TracerPayloadV1Builder, DDOG_V1_ATTR_BOOL, DDOG_V1_ATTR_BYTES, DDOG_V1_ATTR_DOUBLE,
    DDOG_V1_ATTR_INT, DDOG_V1_ATTR_KEYVALUE, DDOG_V1_ATTR_LIST, DDOG_V1_ATTR_STRING,
};
use libdd_common_ffi::slice::{AsBytes, CharSlice};
use libdd_tinybytes::{Bytes, BytesString, RefCountedCell, RefCountedCellVTable};
use libdd_trace_utils::span::v1::{AttributeValueBytes, SpanKind};
use libdd_trace_utils::span::vec_map::VecMap;
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

// Native V1 fill surface: builds the `TracerPayloadV1Builder` directly, addressing chunks/spans/
// links/events by `usize` index. Stacked-Borrows soundness: each call takes one `&mut` and resolves
// by index, so no `&mut` into the payload ever escapes to C.

/// Sets a V1 string field from a `CharSlice`, leaving it unchanged for an empty slice (matches the
/// builder's `set_string_field` skip-empty semantics so absent values are omitted on the wire).
#[inline]
fn set_field_cs(field: &mut BytesString, val: CharSlice) {
    if val.is_empty() {
        return;
    }
    *field = convert_char_slice_to_bytes_string(val);
}

/// Inserts a typed attribute under `key`, skipping empty keys (mirrors the builder's `insert_attr`).
#[inline]
fn insert_attr(map: &mut VecMap<BytesString, AttributeValueBytes>, key: BytesString, value: AttributeValueBytes) {
    if key.as_str().is_empty() {
        return;
    }
    map.insert(key, value);
}

/// Deep-clones an attribute value for cross-span transfer. `AttributeValue` can't derive `Clone`, so
/// the recursion is spelled out; all leaf payloads are themselves `Clone`.
fn clone_attr(value: &AttributeValueBytes) -> AttributeValueBytes {
    match value {
        AttributeValueBytes::String(s) => AttributeValueBytes::String(s.clone()),
        AttributeValueBytes::Float(f) => AttributeValueBytes::Float(*f),
        AttributeValueBytes::Int(i) => AttributeValueBytes::Int(*i),
        AttributeValueBytes::Bool(b) => AttributeValueBytes::Bool(*b),
        AttributeValueBytes::Bytes(b) => AttributeValueBytes::Bytes(b.clone()),
        AttributeValueBytes::KeyValue(m) => {
            let mut cloned = VecMap::with_capacity(m.len());
            for (k, v) in m.iter() {
                cloned.insert(k.clone(), clone_attr(v));
            }
            AttributeValueBytes::KeyValue(cloned)
        }
        AttributeValueBytes::List(list) => {
            AttributeValueBytes::List(list.iter().map(clone_attr).collect())
        }
    }
}

// ------------------- Chunk / span / link / event creation -------------------

/// Appends a chunk carrying the 128-bit trace id (high/low halves), returning its index.
#[no_mangle]
pub extern "C" fn ddog_new_chunk(
    builder: &mut TracerPayloadV1Builder,
    trace_id_high: u64,
    trace_id_low: u64,
) -> usize {
    builder.push_chunk(trace_id_high, trace_id_low)
}

/// Appends an empty span to `chunk`, returning its index.
#[no_mangle]
pub extern "C" fn ddog_new_span(builder: &mut TracerPayloadV1Builder, chunk: usize) -> usize {
    builder.push_span(chunk)
}

/// Appends an empty link to a span, returning its index.
#[no_mangle]
pub extern "C" fn ddog_new_link(
    builder: &mut TracerPayloadV1Builder,
    chunk: usize,
    span: usize,
) -> usize {
    builder.push_link(chunk, span)
}

/// Appends an empty event to a span, returning its index.
#[no_mangle]
pub extern "C" fn ddog_new_event(
    builder: &mut TracerPayloadV1Builder,
    chunk: usize,
    span: usize,
) -> usize {
    builder.push_event(chunk, span)
}

// ------------------- Span scalar fields -------------------

#[no_mangle]
pub extern "C" fn ddog_span_set_id(
    builder: &mut TracerPayloadV1Builder,
    chunk: usize,
    span: usize,
    value: u64,
) {
    if let Some(s) = builder.span_mut(chunk, span) {
        s.span_id = value;
    }
}

#[no_mangle]
pub extern "C" fn ddog_span_set_parent_id(
    builder: &mut TracerPayloadV1Builder,
    chunk: usize,
    span: usize,
    value: u64,
) {
    if let Some(s) = builder.span_mut(chunk, span) {
        s.parent_id = value;
    }
}

#[no_mangle]
pub extern "C" fn ddog_span_set_start(
    builder: &mut TracerPayloadV1Builder,
    chunk: usize,
    span: usize,
    value: i64,
) {
    if let Some(s) = builder.span_mut(chunk, span) {
        s.start = value;
    }
}

#[no_mangle]
pub extern "C" fn ddog_span_set_duration(
    builder: &mut TracerPayloadV1Builder,
    chunk: usize,
    span: usize,
    value: i64,
) {
    if let Some(s) = builder.span_mut(chunk, span) {
        s.duration = value;
    }
}

#[no_mangle]
pub extern "C" fn ddog_span_set_error(
    builder: &mut TracerPayloadV1Builder,
    chunk: usize,
    span: usize,
    error: bool,
) {
    if let Some(s) = builder.span_mut(chunk, span) {
        s.error = error;
    }
}

// ------------------- Span string fields (ZendString, zero-copy refcounted) -------------------

#[no_mangle]
pub extern "C" fn ddog_set_span_service_zstr(
    builder: &mut TracerPayloadV1Builder,
    chunk: usize,
    span: usize,
    str: &mut ZendString,
) {
    if let Some(s) = builder.span_mut(chunk, span) {
        s.service = convert_zend_to_bytes_string(str);
    }
}

#[no_mangle]
pub extern "C" fn ddog_set_span_name_zstr(
    builder: &mut TracerPayloadV1Builder,
    chunk: usize,
    span: usize,
    str: &mut ZendString,
) {
    if let Some(s) = builder.span_mut(chunk, span) {
        s.name = convert_zend_to_bytes_string(str);
    }
}

#[no_mangle]
pub extern "C" fn ddog_set_span_resource_zstr(
    builder: &mut TracerPayloadV1Builder,
    chunk: usize,
    span: usize,
    str: &mut ZendString,
) {
    if let Some(s) = builder.span_mut(chunk, span) {
        s.resource = convert_zend_to_bytes_string(str);
    }
}

#[no_mangle]
pub extern "C" fn ddog_set_span_type_zstr(
    builder: &mut TracerPayloadV1Builder,
    chunk: usize,
    span: usize,
    str: &mut ZendString,
) {
    if let Some(s) = builder.span_mut(chunk, span) {
        s.r#type = convert_zend_to_bytes_string(str);
    }
}

// ------------------- Promoted span fields (properties-direct) -------------------

#[no_mangle]
pub extern "C" fn ddog_set_span_env(
    builder: &mut TracerPayloadV1Builder,
    chunk: usize,
    span: usize,
    value: CharSlice,
) {
    if let Some(s) = builder.span_mut(chunk, span) {
        set_field_cs(&mut s.env, value);
    }
}

#[no_mangle]
pub extern "C" fn ddog_set_span_version(
    builder: &mut TracerPayloadV1Builder,
    chunk: usize,
    span: usize,
    value: CharSlice,
) {
    if let Some(s) = builder.span_mut(chunk, span) {
        set_field_cs(&mut s.version, value);
    }
}

#[no_mangle]
pub extern "C" fn ddog_set_span_component(
    builder: &mut TracerPayloadV1Builder,
    chunk: usize,
    span: usize,
    value: CharSlice,
) {
    if let Some(s) = builder.span_mut(chunk, span) {
        set_field_cs(&mut s.component, value);
    }
}

/// Sets the span kind from an OTEL wire value (unset/unknown → Internal).
#[no_mangle]
pub extern "C" fn ddog_set_span_kind(
    builder: &mut TracerPayloadV1Builder,
    chunk: usize,
    span: usize,
    kind: u32,
) {
    if let Some(s) = builder.span_mut(chunk, span) {
        s.span_kind = SpanKind::from(kind);
    }
}

/// Sets the span kind from a v0.4 `span.kind` meta string (mapping owned by libdatadog's
/// `SpanKind::from_meta`; unknown → Internal).
#[no_mangle]
pub extern "C" fn ddog_set_span_kind_str(
    builder: &mut TracerPayloadV1Builder,
    chunk: usize,
    span: usize,
    value: CharSlice,
) {
    let kind = SpanKind::from_meta(String::from_utf8_lossy(value.as_bytes().as_ref()).as_ref());
    if let Some(s) = builder.span_mut(chunk, span) {
        s.span_kind = kind;
    }
}

// ------------------- Span attributes (unified V1 map, subsumes meta/metrics/meta_struct) -------------------

#[no_mangle]
pub extern "C" fn ddog_add_span_attr_cs_cs(
    builder: &mut TracerPayloadV1Builder,
    chunk: usize,
    span: usize,
    key: CharSlice,
    value: CharSlice,
) {
    let (key, value) = (
        convert_char_slice_to_bytes_string(key),
        AttributeValueBytes::String(convert_char_slice_to_bytes_string(value)),
    );
    if let Some(s) = builder.span_mut(chunk, span) {
        insert_attr(&mut s.attributes, key, value);
    }
}

#[no_mangle]
pub extern "C" fn ddog_add_span_attr_lit_cs(
    builder: &mut TracerPayloadV1Builder,
    chunk: usize,
    span: usize,
    key: *const c_char,
    value: CharSlice,
) {
    let (key, value) = (
        convert_literal_to_bytes_string(key),
        AttributeValueBytes::String(convert_char_slice_to_bytes_string(value)),
    );
    if let Some(s) = builder.span_mut(chunk, span) {
        insert_attr(&mut s.attributes, key, value);
    }
}

#[no_mangle]
pub extern "C" fn ddog_add_span_attr_zstr_cs(
    builder: &mut TracerPayloadV1Builder,
    chunk: usize,
    span: usize,
    key: &mut ZendString,
    value: CharSlice,
) {
    let (key, value) = (
        convert_zend_to_bytes_string(key),
        AttributeValueBytes::String(convert_char_slice_to_bytes_string(value)),
    );
    if let Some(s) = builder.span_mut(chunk, span) {
        insert_attr(&mut s.attributes, key, value);
    }
}

#[no_mangle]
pub extern "C" fn ddog_add_span_attr_zstr_zstr(
    builder: &mut TracerPayloadV1Builder,
    chunk: usize,
    span: usize,
    key: &mut ZendString,
    value: &mut ZendString,
) {
    let (key, value) = (
        convert_zend_to_bytes_string(key),
        AttributeValueBytes::String(convert_zend_to_bytes_string(value)),
    );
    if let Some(s) = builder.span_mut(chunk, span) {
        insert_attr(&mut s.attributes, key, value);
    }
}

/// Adds a numeric (double) attribute under a `CharSlice` key.
#[no_mangle]
pub extern "C" fn ddog_add_span_attr_double_cs(
    builder: &mut TracerPayloadV1Builder,
    chunk: usize,
    span: usize,
    key: CharSlice,
    value: f64,
) {
    let key = convert_char_slice_to_bytes_string(key);
    if let Some(s) = builder.span_mut(chunk, span) {
        insert_attr(&mut s.attributes, key, AttributeValueBytes::Float(value));
    }
}

/// Adds a numeric (double) attribute under a static C literal key.
#[no_mangle]
pub extern "C" fn ddog_add_span_attr_double_lit(
    builder: &mut TracerPayloadV1Builder,
    chunk: usize,
    span: usize,
    key: *const c_char,
    value: f64,
) {
    let key = convert_literal_to_bytes_string(key);
    if let Some(s) = builder.span_mut(chunk, span) {
        insert_attr(&mut s.attributes, key, AttributeValueBytes::Float(value));
    }
}

/// Adds a numeric (double) attribute under a `ZendString` key.
#[no_mangle]
pub extern "C" fn ddog_add_span_attr_double_zstr(
    builder: &mut TracerPayloadV1Builder,
    chunk: usize,
    span: usize,
    key: &mut ZendString,
    value: f64,
) {
    let key = convert_zend_to_bytes_string(key);
    if let Some(s) = builder.span_mut(chunk, span) {
        insert_attr(&mut s.attributes, key, AttributeValueBytes::Float(value));
    }
}

/// Adds a bytes-valued attribute (v0.4 `meta_struct`) under a `ZendString` key. The value bytes are
/// copied verbatim and encoded as msgpack `bin`.
#[no_mangle]
pub extern "C" fn ddog_add_span_attr_bytes_zstr(
    builder: &mut TracerPayloadV1Builder,
    chunk: usize,
    span: usize,
    key: &mut ZendString,
    value: CharSlice,
) {
    let (key, value) = (
        convert_zend_to_bytes_string(key),
        AttributeValueBytes::Bytes(Bytes::copy_from_slice(value.as_bytes())),
    );
    if let Some(s) = builder.span_mut(chunk, span) {
        insert_attr(&mut s.attributes, key, value);
    }
}

/// Whether the span carries an attribute under `key` (`ZendString`). Mirrors the v0.4
/// `has_span_meta`/`has_span_metrics` guard so the generic loops never overwrite a promoted value.
#[no_mangle]
pub extern "C" fn ddog_has_span_attr_zstr(
    builder: &TracerPayloadV1Builder,
    chunk: usize,
    span: usize,
    key: &mut ZendString,
) -> bool {
    let key = convert_zend_to_bytes_string(key);
    builder
        .span(chunk, span)
        .is_some_and(|s| s.attributes.contains_key(&key))
}

/// Removes the attribute under a static C literal `key`, returning whether it was present.
#[no_mangle]
pub extern "C" fn ddog_del_span_attr_lit(
    builder: &mut TracerPayloadV1Builder,
    chunk: usize,
    span: usize,
    key: *const c_char,
) -> bool {
    let key = convert_literal_to_bytes_string(key);
    match builder.span_mut(chunk, span) {
        Some(s) => {
            let existed = s.attributes.contains_key(&key);
            s.attributes.remove_slow(&key);
            existed
        }
        None => false,
    }
}

/// Copies the attribute `key` from `from_span` onto `to_span` (within `chunk`), returning whether the
/// source had it; removes it from the source when `delete_source` is set. Type-preserving.
#[no_mangle]
pub extern "C" fn ddog_transfer_span_attr(
    builder: &mut TracerPayloadV1Builder,
    chunk: usize,
    from_span: usize,
    to_span: usize,
    key: *const c_char,
    delete_source: bool,
) -> bool {
    let key = convert_literal_to_bytes_string(key);
    let value = match builder.span(chunk, from_span).and_then(|s| s.attributes.get(&key)) {
        Some(v) => clone_attr(v),
        None => return false,
    };
    match builder.span_mut(chunk, to_span) {
        Some(dst) => dst.attributes.insert(key.clone(), value),
        None => return false,
    };
    if delete_source {
        if let Some(src) = builder.span_mut(chunk, from_span) {
            src.attributes.remove_slow(&key);
        }
    }
    true
}

// ------------------- Chunk-level fields -------------------

#[no_mangle]
pub extern "C" fn ddog_set_chunk_origin(
    builder: &mut TracerPayloadV1Builder,
    chunk: usize,
    origin: CharSlice,
) {
    if let Some(c) = builder.chunk_mut(chunk) {
        set_field_cs(&mut c.origin, origin);
    }
}

#[no_mangle]
pub extern "C" fn ddog_set_chunk_dropped_trace(
    builder: &mut TracerPayloadV1Builder,
    chunk: usize,
    dropped: bool,
) {
    if let Some(c) = builder.chunk_mut(chunk) {
        c.dropped_trace = dropped;
    }
}

#[no_mangle]
pub extern "C" fn ddog_set_chunk_sampling_priority(
    builder: &mut TracerPayloadV1Builder,
    chunk: usize,
    priority: i32,
) {
    if let Some(c) = builder.chunk_mut(chunk) {
        c.priority = Some(priority);
    }
}

#[no_mangle]
pub extern "C" fn ddog_set_chunk_sampling_mechanism(
    builder: &mut TracerPayloadV1Builder,
    chunk: usize,
    mechanism: u32,
) {
    if let Some(c) = builder.chunk_mut(chunk) {
        c.sampling_mechanism = Some(mechanism);
    }
}

// ------------------- Span links -------------------

#[no_mangle]
pub extern "C" fn ddog_link_set_trace_id(
    builder: &mut TracerPayloadV1Builder,
    chunk: usize,
    span: usize,
    link: usize,
    trace_id_high: u64,
    trace_id_low: u64,
) {
    if let Some(l) = builder.link_mut(chunk, span, link) {
        l.trace_id[..8].copy_from_slice(&trace_id_high.to_be_bytes());
        l.trace_id[8..].copy_from_slice(&trace_id_low.to_be_bytes());
    }
}

#[no_mangle]
pub extern "C" fn ddog_link_set_span_id(
    builder: &mut TracerPayloadV1Builder,
    chunk: usize,
    span: usize,
    link: usize,
    value: u64,
) {
    if let Some(l) = builder.link_mut(chunk, span, link) {
        l.span_id = value;
    }
}

#[no_mangle]
pub extern "C" fn ddog_link_set_tracestate(
    builder: &mut TracerPayloadV1Builder,
    chunk: usize,
    span: usize,
    link: usize,
    value: CharSlice,
) {
    if let Some(l) = builder.link_mut(chunk, span, link) {
        set_field_cs(&mut l.tracestate, value);
    }
}

#[no_mangle]
pub extern "C" fn ddog_link_add_attr_str(
    builder: &mut TracerPayloadV1Builder,
    chunk: usize,
    span: usize,
    link: usize,
    key: CharSlice,
    value: CharSlice,
) {
    let (key, value) = (
        convert_char_slice_to_bytes_string(key),
        AttributeValueBytes::String(convert_char_slice_to_bytes_string(value)),
    );
    if let Some(l) = builder.link_mut(chunk, span, link) {
        insert_attr(&mut l.attributes, key, value);
    }
}

// ------------------- Span events -------------------

#[no_mangle]
pub extern "C" fn ddog_event_set_name(
    builder: &mut TracerPayloadV1Builder,
    chunk: usize,
    span: usize,
    event: usize,
    value: CharSlice,
) {
    if let Some(e) = builder.event_mut(chunk, span, event) {
        set_field_cs(&mut e.name, value);
    }
}

#[no_mangle]
pub extern "C" fn ddog_event_set_time(
    builder: &mut TracerPayloadV1Builder,
    chunk: usize,
    span: usize,
    event: usize,
    time_unix_nano: u64,
) {
    if let Some(e) = builder.event_mut(chunk, span, event) {
        e.time_unix_nano = time_unix_nano;
    }
}

#[no_mangle]
pub extern "C" fn ddog_event_add_attr_str(
    builder: &mut TracerPayloadV1Builder,
    chunk: usize,
    span: usize,
    event: usize,
    key: CharSlice,
    value: CharSlice,
) {
    let (key, value) = (
        convert_char_slice_to_bytes_string(key),
        AttributeValueBytes::String(convert_char_slice_to_bytes_string(value)),
    );
    if let Some(e) = builder.event_mut(chunk, span, event) {
        insert_attr(&mut e.attributes, key, value);
    }
}

#[no_mangle]
pub extern "C" fn ddog_event_add_attr_int(
    builder: &mut TracerPayloadV1Builder,
    chunk: usize,
    span: usize,
    event: usize,
    key: CharSlice,
    value: i64,
) {
    let key = convert_char_slice_to_bytes_string(key);
    if let Some(e) = builder.event_mut(chunk, span, event) {
        insert_attr(&mut e.attributes, key, AttributeValueBytes::Int(value));
    }
}

#[no_mangle]
pub extern "C" fn ddog_event_add_attr_double(
    builder: &mut TracerPayloadV1Builder,
    chunk: usize,
    span: usize,
    event: usize,
    key: CharSlice,
    value: f64,
) {
    let key = convert_char_slice_to_bytes_string(key);
    if let Some(e) = builder.event_mut(chunk, span, event) {
        insert_attr(&mut e.attributes, key, AttributeValueBytes::Float(value));
    }
}

#[no_mangle]
pub extern "C" fn ddog_event_add_attr_bool(
    builder: &mut TracerPayloadV1Builder,
    chunk: usize,
    span: usize,
    event: usize,
    key: CharSlice,
    value: bool,
) {
    let key = convert_char_slice_to_bytes_string(key);
    if let Some(e) = builder.event_mut(chunk, span, event) {
        insert_attr(&mut e.attributes, key, AttributeValueBytes::Bool(value));
    }
}

// ------------------- Nested span attributes: staging builder (write side) -------------------
//
// Builds a nested `AttributeValue::List`/`KeyValue` for a span attribute out-of-band, then attaches
// it in one shot. Each `AttrBuilder` is its own `Box` allocation (own-allocation provenance); the
// raw `builder`/`parent` pointers it stashes are reborrowed exactly once — at the matching
// `ddog_attr_close` — and never while another live `&mut` to the same object exists. The C caller
// contract keeping this Stacked-Borrows clean: while it holds an `*mut AttrBuilder` it makes ONLY
// AttrBuilder FFI calls (push/put/open/close on the staging tree), never a `&mut TracerPayloadV1Builder`
// FFI call, until the top-level close attaches the finished value via `span_mut`. Exactly one
// container pointer is live per recursion level, and a child is fully built and closed (folded into
// its parent by value) before the next sibling is appended.

/// The partial nested value an `AttrBuilder` accumulates.
enum PartialAttr {
    List(Vec<AttributeValueBytes>),
    Map(VecMap<BytesString, AttributeValueBytes>),
}

impl PartialAttr {
    fn finish(self) -> AttributeValueBytes {
        match self {
            PartialAttr::List(v) => AttributeValueBytes::List(v),
            PartialAttr::Map(m) => AttributeValueBytes::KeyValue(m),
        }
    }
}

/// Where a finished `AttrBuilder` folds on close.
enum Attach {
    /// Top level: insert the finished value into `span[chunk][span].attributes` under `key`.
    Span {
        builder: *mut TracerPayloadV1Builder,
        chunk: usize,
        span: usize,
        key: BytesString,
    },
    /// Append the finished value to the parent list.
    ParentList { parent: *mut AttrBuilder },
    /// Insert the finished value into the parent map under `key`.
    ParentMap {
        parent: *mut AttrBuilder,
        key: BytesString,
    },
}

/// Out-of-band staging builder for one nested attribute value. Opaque to C (`ddog_AttrBuilder *`).
pub struct AttrBuilder {
    value: PartialAttr,
    attach: Attach,
}

#[inline]
fn box_attr(value: PartialAttr, attach: Attach) -> *mut AttrBuilder {
    Box::into_raw(Box::new(AttrBuilder { value, attach }))
}

/// Mutable view of an `AttrBuilder`'s list contents (None if it is a map).
#[inline]
unsafe fn list_of<'a>(list: *mut AttrBuilder) -> Option<&'a mut Vec<AttributeValueBytes>> {
    match &mut (*list).value {
        PartialAttr::List(v) => Some(v),
        PartialAttr::Map(_) => None,
    }
}

/// Mutable view of an `AttrBuilder`'s map contents (None if it is a list).
#[inline]
unsafe fn map_of<'a>(
    map: *mut AttrBuilder,
) -> Option<&'a mut VecMap<BytesString, AttributeValueBytes>> {
    match &mut (*map).value {
        PartialAttr::Map(m) => Some(m),
        PartialAttr::List(_) => None,
    }
}

// ---- Opening a nested container ----

/// Opens a staging `List` attached to `span[chunk][span].attributes[key]` on close.
#[no_mangle]
pub extern "C" fn ddog_span_attr_open_list(
    builder: &mut TracerPayloadV1Builder,
    chunk: usize,
    span: usize,
    key: CharSlice,
) -> *mut AttrBuilder {
    box_attr(
        PartialAttr::List(Vec::new()),
        Attach::Span {
            builder: builder as *mut TracerPayloadV1Builder,
            chunk,
            span,
            key: convert_char_slice_to_bytes_string(key),
        },
    )
}

/// Opens a staging `KeyValue` map attached to `span[chunk][span].attributes[key]` on close.
#[no_mangle]
pub extern "C" fn ddog_span_attr_open_map(
    builder: &mut TracerPayloadV1Builder,
    chunk: usize,
    span: usize,
    key: CharSlice,
) -> *mut AttrBuilder {
    box_attr(
        PartialAttr::Map(VecMap::new()),
        Attach::Span {
            builder: builder as *mut TracerPayloadV1Builder,
            chunk,
            span,
            key: convert_char_slice_to_bytes_string(key),
        },
    )
}

/// Opens a nested `List` appended to the parent list on close.
#[no_mangle]
pub unsafe extern "C" fn ddog_attr_list_open_list(list: *mut AttrBuilder) -> *mut AttrBuilder {
    box_attr(PartialAttr::List(Vec::new()), Attach::ParentList { parent: list })
}

/// Opens a nested `KeyValue` map appended to the parent list on close.
#[no_mangle]
pub unsafe extern "C" fn ddog_attr_list_open_map(list: *mut AttrBuilder) -> *mut AttrBuilder {
    box_attr(PartialAttr::Map(VecMap::new()), Attach::ParentList { parent: list })
}

/// Opens a nested `List` inserted into the parent map under `key` on close.
#[no_mangle]
pub unsafe extern "C" fn ddog_attr_map_open_list(
    map: *mut AttrBuilder,
    key: CharSlice,
) -> *mut AttrBuilder {
    box_attr(
        PartialAttr::List(Vec::new()),
        Attach::ParentMap {
            parent: map,
            key: convert_char_slice_to_bytes_string(key),
        },
    )
}

/// Opens a nested `KeyValue` map inserted into the parent map under `key` on close.
#[no_mangle]
pub unsafe extern "C" fn ddog_attr_map_open_map(
    map: *mut AttrBuilder,
    key: CharSlice,
) -> *mut AttrBuilder {
    box_attr(
        PartialAttr::Map(VecMap::new()),
        Attach::ParentMap {
            parent: map,
            key: convert_char_slice_to_bytes_string(key),
        },
    )
}

// ---- Scalar leaves: list append ----

#[no_mangle]
pub unsafe extern "C" fn ddog_attr_list_push_str(list: *mut AttrBuilder, value: CharSlice) {
    if let Some(v) = list_of(list) {
        v.push(AttributeValueBytes::String(convert_char_slice_to_bytes_string(value)));
    }
}

#[no_mangle]
pub unsafe extern "C" fn ddog_attr_list_push_int(list: *mut AttrBuilder, value: i64) {
    if let Some(v) = list_of(list) {
        v.push(AttributeValueBytes::Int(value));
    }
}

#[no_mangle]
pub unsafe extern "C" fn ddog_attr_list_push_double(list: *mut AttrBuilder, value: f64) {
    if let Some(v) = list_of(list) {
        v.push(AttributeValueBytes::Float(value));
    }
}

#[no_mangle]
pub unsafe extern "C" fn ddog_attr_list_push_bool(list: *mut AttrBuilder, value: bool) {
    if let Some(v) = list_of(list) {
        v.push(AttributeValueBytes::Bool(value));
    }
}

#[no_mangle]
pub unsafe extern "C" fn ddog_attr_list_push_bytes(list: *mut AttrBuilder, value: CharSlice) {
    if let Some(v) = list_of(list) {
        v.push(AttributeValueBytes::Bytes(Bytes::copy_from_slice(value.as_bytes())));
    }
}

// ---- Scalar leaves: map insert ----

#[no_mangle]
pub unsafe extern "C" fn ddog_attr_map_put_str(
    map: *mut AttrBuilder,
    key: CharSlice,
    value: CharSlice,
) {
    if let Some(m) = map_of(map) {
        m.insert(
            convert_char_slice_to_bytes_string(key),
            AttributeValueBytes::String(convert_char_slice_to_bytes_string(value)),
        );
    }
}

#[no_mangle]
pub unsafe extern "C" fn ddog_attr_map_put_int(map: *mut AttrBuilder, key: CharSlice, value: i64) {
    if let Some(m) = map_of(map) {
        m.insert(convert_char_slice_to_bytes_string(key), AttributeValueBytes::Int(value));
    }
}

#[no_mangle]
pub unsafe extern "C" fn ddog_attr_map_put_double(
    map: *mut AttrBuilder,
    key: CharSlice,
    value: f64,
) {
    if let Some(m) = map_of(map) {
        m.insert(convert_char_slice_to_bytes_string(key), AttributeValueBytes::Float(value));
    }
}

#[no_mangle]
pub unsafe extern "C" fn ddog_attr_map_put_bool(map: *mut AttrBuilder, key: CharSlice, value: bool) {
    if let Some(m) = map_of(map) {
        m.insert(convert_char_slice_to_bytes_string(key), AttributeValueBytes::Bool(value));
    }
}

#[no_mangle]
pub unsafe extern "C" fn ddog_attr_map_put_bytes(
    map: *mut AttrBuilder,
    key: CharSlice,
    value: CharSlice,
) {
    if let Some(m) = map_of(map) {
        m.insert(
            convert_char_slice_to_bytes_string(key),
            AttributeValueBytes::Bytes(Bytes::copy_from_slice(value.as_bytes())),
        );
    }
}

/// Finishes `child` and folds it into its parent by value: appends to a parent list, inserts into a
/// parent map under its key, or (top level) inserts into the span's attribute map via the index
/// accessor. This is the single point that reborrows the stashed `builder`/`parent` pointer.
#[no_mangle]
pub unsafe extern "C" fn ddog_attr_close(child: *mut AttrBuilder) {
    let child = Box::from_raw(child);
    let AttrBuilder { value, attach } = *child;
    let finished = value.finish();
    match attach {
        Attach::ParentList { parent } => {
            if let Some(v) = list_of(parent) {
                v.push(finished);
            }
        }
        Attach::ParentMap { parent, key } => {
            if let Some(m) = map_of(parent) {
                m.insert(key, finished);
            }
        }
        Attach::Span {
            builder,
            chunk,
            span,
            key,
        } => {
            if let Some(s) = (*builder).span_mut(chunk, span) {
                insert_attr(&mut s.attributes, key, finished);
            }
        }
    }
}

// ------------------- Nested span attributes: read-back (introspection) -------------------
//
// Path-addressed getters mirroring the write side. `path[0]` indexes the span's top-level attribute
// map; each further element indexes into the `List` (by position) or `KeyValue` (by member order)
// reached so far. Every call re-walks the path from the span root under a fresh shared borrow, so no
// borrow into the payload escapes to C between calls.

/// Resolves the nested attribute value at `path` (length `path_len`), or `None` if any step is out
/// of range or descends into a scalar.
#[inline]
unsafe fn resolve_span_attr<'a>(
    builder: &'a TracerPayloadV1Builder,
    chunk: usize,
    span: usize,
    path: *const usize,
    path_len: usize,
) -> Option<&'a AttributeValueBytes> {
    let s = builder.span(chunk, span)?;
    let path = std::slice::from_raw_parts(path, path_len);
    let (&first, rest) = path.split_first()?;
    let mut cur = &s.attributes.iter().nth(first)?.1;
    for &idx in rest {
        cur = match cur {
            AttributeValueBytes::List(v) => v.get(idx)?,
            AttributeValueBytes::KeyValue(m) => &m.iter().nth(idx)?.1,
            _ => return None,
        };
    }
    Some(cur)
}

/// Exported `DDOG_V1_ATTR_*` tag for `value`.
fn attr_tag(value: &AttributeValueBytes) -> u32 {
    match value {
        AttributeValueBytes::String(_) => DDOG_V1_ATTR_STRING,
        AttributeValueBytes::Int(_) => DDOG_V1_ATTR_INT,
        AttributeValueBytes::Float(_) => DDOG_V1_ATTR_DOUBLE,
        AttributeValueBytes::Bool(_) => DDOG_V1_ATTR_BOOL,
        AttributeValueBytes::Bytes(_) => DDOG_V1_ATTR_BYTES,
        AttributeValueBytes::KeyValue(_) => DDOG_V1_ATTR_KEYVALUE,
        AttributeValueBytes::List(_) => DDOG_V1_ATTR_LIST,
    }
}

/// Number of children of the `List`/`KeyValue` at `path` (0 for a scalar or out-of-range path).
#[no_mangle]
pub unsafe extern "C" fn ddog_v1_get_span_attr_child_count(
    builder: &TracerPayloadV1Builder,
    chunk: usize,
    span: usize,
    path: *const usize,
    path_len: usize,
) -> usize {
    match resolve_span_attr(builder, chunk, span, path, path_len) {
        Some(AttributeValueBytes::List(v)) => v.len(),
        Some(AttributeValueBytes::KeyValue(m)) => m.len(),
        _ => 0,
    }
}

/// `DDOG_V1_ATTR_*` tag of the value at `path` (STRING for an out-of-range path).
#[no_mangle]
pub unsafe extern "C" fn ddog_v1_get_span_attr_child_type(
    builder: &TracerPayloadV1Builder,
    chunk: usize,
    span: usize,
    path: *const usize,
    path_len: usize,
) -> u32 {
    resolve_span_attr(builder, chunk, span, path, path_len).map_or(DDOG_V1_ATTR_STRING, attr_tag)
}

/// Member name of the value at `path` within its parent `KeyValue` (empty if the parent is a list
/// or the path is out of range). `path` must have length >= 1.
#[no_mangle]
pub unsafe extern "C" fn ddog_v1_get_span_attr_child_key(
    builder: &TracerPayloadV1Builder,
    chunk: usize,
    span: usize,
    path: *const usize,
    path_len: usize,
) -> CharSlice {
    if path_len == 0 {
        return CharSlice::empty();
    }
    let full = std::slice::from_raw_parts(path, path_len);
    let last = full[path_len - 1];
    let parent_len = path_len - 1;
    if parent_len == 0 {
        // Parent is the span's top-level attribute map: the member name is the attribute name.
        return match builder.span(chunk, span).and_then(|s| s.attributes.iter().nth(last)) {
            Some((k, _)) => CharSlice::from_bytes(k.as_str().as_bytes()),
            None => CharSlice::empty(),
        };
    }
    // Nested parent: resolve the container at the parent path and read the member name at `last`.
    match resolve_span_attr(builder, chunk, span, path, parent_len) {
        Some(AttributeValueBytes::KeyValue(m)) => match m.iter().nth(last) {
            Some((k, _)) => CharSlice::from_bytes(k.as_str().as_bytes()),
            None => CharSlice::empty(),
        },
        _ => CharSlice::empty(),
    }
}

/// String value at `path` (empty if not a `String`).
#[no_mangle]
pub unsafe extern "C" fn ddog_v1_get_span_attr_child_str(
    builder: &TracerPayloadV1Builder,
    chunk: usize,
    span: usize,
    path: *const usize,
    path_len: usize,
) -> CharSlice {
    match resolve_span_attr(builder, chunk, span, path, path_len) {
        Some(AttributeValueBytes::String(s)) => CharSlice::from_bytes(s.as_str().as_bytes()),
        _ => CharSlice::empty(),
    }
}

/// Int value at `path` (0 if not an `Int`).
#[no_mangle]
pub unsafe extern "C" fn ddog_v1_get_span_attr_child_int(
    builder: &TracerPayloadV1Builder,
    chunk: usize,
    span: usize,
    path: *const usize,
    path_len: usize,
) -> i64 {
    match resolve_span_attr(builder, chunk, span, path, path_len) {
        Some(AttributeValueBytes::Int(v)) => *v,
        _ => 0,
    }
}

/// Double value at `path` (0.0 if not a `Float`).
#[no_mangle]
pub unsafe extern "C" fn ddog_v1_get_span_attr_child_double(
    builder: &TracerPayloadV1Builder,
    chunk: usize,
    span: usize,
    path: *const usize,
    path_len: usize,
) -> f64 {
    match resolve_span_attr(builder, chunk, span, path, path_len) {
        Some(AttributeValueBytes::Float(v)) => *v,
        _ => 0.0,
    }
}

/// Bool value at `path` (false if not a `Bool`).
#[no_mangle]
pub unsafe extern "C" fn ddog_v1_get_span_attr_child_bool(
    builder: &TracerPayloadV1Builder,
    chunk: usize,
    span: usize,
    path: *const usize,
    path_len: usize,
) -> bool {
    matches!(
        resolve_span_attr(builder, chunk, span, path, path_len),
        Some(AttributeValueBytes::Bool(true))
    )
}

/// Bytes value at `path` (empty if not `Bytes`).
#[no_mangle]
pub unsafe extern "C" fn ddog_v1_get_span_attr_child_bytes(
    builder: &TracerPayloadV1Builder,
    chunk: usize,
    span: usize,
    path: *const usize,
    path_len: usize,
) -> CharSlice {
    match resolve_span_attr(builder, chunk, span, path, path_len) {
        Some(AttributeValueBytes::Bytes(b)) => CharSlice::from_bytes(b.as_ref()),
        _ => CharSlice::empty(),
    }
}

#[cfg(test)]
mod staging_ffi_tests {
    // Exercises the nested-attribute staging FFI end to end (build root → nested open → push →
    // close → attach) plus the path-addressed read-back, so `cargo miri test` can prove the
    // raw-pointer stashing/reborrow is Stacked-Borrows / Tree-Borrows clean and leak-free.
    use super::*;
    use libdd_common_ffi::slice::{AsBytes, CharSlice};

    fn cs(s: &str) -> CharSlice<'_> {
        CharSlice::from_bytes(s.as_bytes())
    }

    #[test]
    fn build_nested_attrs_and_read_back() {
        let mut b = TracerPayloadV1Builder::default();
        let chunk = b.push_chunk(0, 1);
        let span = b.push_span(chunk);

        unsafe {
            // root: KeyValue { a: "x", n: 7, items: [ "first", 42, { flag: true } ] }
            let root = ddog_span_attr_open_map(&mut b, chunk, span, cs("root"));
            ddog_attr_map_put_str(root, cs("a"), cs("x"));
            ddog_attr_map_put_int(root, cs("n"), 7);
            let items = ddog_attr_map_open_list(root, cs("items"));
            ddog_attr_list_push_str(items, cs("first"));
            ddog_attr_list_push_int(items, 42);
            let inner = ddog_attr_list_open_map(items);
            ddog_attr_map_put_bool(inner, cs("flag"), true);
            ddog_attr_close(inner); // fold inner map into the list
            ddog_attr_close(items); // fold list into root map
            ddog_attr_close(root); // attach root map onto the span

            // A second top-level attribute: a List of one double.
            let tl = ddog_span_attr_open_list(&mut b, chunk, span, cs("list"));
            ddog_attr_list_push_double(tl, 1.5);
            ddog_attr_close(tl);
        }

        let path = |p: &[usize]| (p.as_ptr(), p.len());
        unsafe {
            // attr 0 = "root" (KeyValue with 3 members)
            let (p, l) = path(&[0]);
            assert_eq!(ddog_v1_get_span_attr_child_type(&b, chunk, span, p, l), DDOG_V1_ATTR_KEYVALUE);
            assert_eq!(ddog_v1_get_span_attr_child_count(&b, chunk, span, p, l), 3);

            // root.a == "x", root.n == 7
            let (p, l) = path(&[0, 0]);
            assert_eq!(ddog_v1_get_span_attr_child_key(&b, chunk, span, p, l).to_utf8_lossy(), "a");
            assert_eq!(ddog_v1_get_span_attr_child_str(&b, chunk, span, p, l).to_utf8_lossy(), "x");
            let (p, l) = path(&[0, 1]);
            assert_eq!(ddog_v1_get_span_attr_child_int(&b, chunk, span, p, l), 7);

            // root.items is a List of 3
            let (p, l) = path(&[0, 2]);
            assert_eq!(ddog_v1_get_span_attr_child_key(&b, chunk, span, p, l).to_utf8_lossy(), "items");
            assert_eq!(ddog_v1_get_span_attr_child_type(&b, chunk, span, p, l), DDOG_V1_ATTR_LIST);
            assert_eq!(ddog_v1_get_span_attr_child_count(&b, chunk, span, p, l), 3);

            // items[0] == "first", items[1] == 42, items[2] == { flag: true }
            let (p, l) = path(&[0, 2, 0]);
            assert_eq!(ddog_v1_get_span_attr_child_str(&b, chunk, span, p, l).to_utf8_lossy(), "first");
            let (p, l) = path(&[0, 2, 1]);
            assert_eq!(ddog_v1_get_span_attr_child_int(&b, chunk, span, p, l), 42);
            let (p, l) = path(&[0, 2, 2]);
            assert_eq!(ddog_v1_get_span_attr_child_type(&b, chunk, span, p, l), DDOG_V1_ATTR_KEYVALUE);
            let (p, l) = path(&[0, 2, 2, 0]);
            assert_eq!(ddog_v1_get_span_attr_child_key(&b, chunk, span, p, l).to_utf8_lossy(), "flag");
            assert!(ddog_v1_get_span_attr_child_bool(&b, chunk, span, p, l));

            // attr 1 = "list" (List with one double)
            let (p, l) = path(&[1]);
            assert_eq!(ddog_v1_get_span_attr_child_type(&b, chunk, span, p, l), DDOG_V1_ATTR_LIST);
            let (p, l) = path(&[1, 0]);
            assert_eq!(ddog_v1_get_span_attr_child_double(&b, chunk, span, p, l), 1.5);
        }
    }
}

#[cfg(test)]
mod v04_parity_tests {
    // Wire-safety gate: the v0.4 downgrade of a NATIVE nested attribute carries the SAME dotted
    // `meta` / `metrics` keys+values (and same meta-vs-metrics bucketing) the OLD C dotted flatten
    // produced. Each case builds both shapes through the FFI, encodes both to v0.4, and compares the
    // decoded `meta` / `metrics` maps order-insensitively — msgpack map key order is not significant
    // for v0.4 consumers, and it is the only byte-level difference between the two encodings (it
    // stems from `VecMap::dedup` reversing entries, which reorders nested vs flat topologies
    // differently; list indices / map members are encoded in the dotted keys, so no data changes).
    use super::*;
    use libdd_common_ffi::slice::CharSlice;
    use libdd_trace_utils::msgpack_encoder::v04::to_vec_from_v1;
    use rmpv::Value;

    fn cs(s: &str) -> CharSlice<'_> {
        CharSlice::from_bytes(s.as_bytes())
    }

    fn one_span_builder() -> (TracerPayloadV1Builder, usize, usize) {
        let mut b = TracerPayloadV1Builder::default();
        let c = b.push_chunk(0, 1);
        let s = b.push_span(c);
        (b, c, s)
    }

    /// Decodes v0.4 bytes (`[[span,...],...]`) and returns the first span's map.
    fn first_span(bytes: &[u8]) -> Value {
        match rmpv::decode::read_value(&mut &bytes[..]).expect("valid msgpack") {
            Value::Array(traces) => match traces.into_iter().next().expect("one trace") {
                Value::Array(spans) => spans.into_iter().next().expect("one span"),
                other => panic!("trace not an array: {other:?}"),
            },
            other => panic!("payload not an array: {other:?}"),
        }
    }

    /// Extracts `span[key]` (a `meta`/`metrics` map) as `(key, value)` pairs sorted by key, so two
    /// encodings can be compared independently of msgpack map serialization order.
    fn sorted_bucket(span: &Value, key: &str) -> Vec<(String, Value)> {
        let entries = match span {
            Value::Map(entries) => entries,
            other => panic!("span not a map: {other:?}"),
        };
        let mut out = Vec::new();
        if let Some((_, Value::Map(bucket))) =
            entries.iter().find(|(k, _)| k.as_str() == Some(key))
        {
            for (k, v) in bucket {
                out.push((k.as_str().expect("string key").to_string(), v.clone()));
            }
        }
        out.sort_by(|a, b| a.0.cmp(&b.0));
        out
    }

    #[test]
    fn meta_nested_matches_old_flat_v04() {
        // NEW: meta attr "m" = { bar: [ "1", { key: "2" }, "" ], "5": "v" }
        // (String leaves, as the meta path stringifies — mirrors the old convert_to_double=false).
        let (mut newb, c, s) = one_span_builder();
        unsafe {
            let m = ddog_span_attr_open_map(&mut newb, c, s, cs("m"));
            let bar = ddog_attr_map_open_list(m, cs("bar"));
            ddog_attr_list_push_str(bar, cs("1"));
            let e = ddog_attr_list_open_map(bar);
            ddog_attr_map_put_str(e, cs("key"), cs("2"));
            ddog_attr_close(e);
            ddog_attr_list_push_str(bar, cs("")); // empty/recursive placeholder equivalent
            ddog_attr_close(bar);
            ddog_attr_map_put_str(m, cs("5"), cs("v")); // numeric-keyed member -> "m.5"
            ddog_attr_close(m);
        }

        // OLD: the identical dotted keys the C flatten wrote, in traversal order.
        let (mut oldb, c, s) = one_span_builder();
        ddog_add_span_attr_cs_cs(&mut oldb, c, s, cs("m.bar.0"), cs("1"));
        ddog_add_span_attr_cs_cs(&mut oldb, c, s, cs("m.bar.1.key"), cs("2"));
        ddog_add_span_attr_cs_cs(&mut oldb, c, s, cs("m.bar.2"), cs(""));
        ddog_add_span_attr_cs_cs(&mut oldb, c, s, cs("m.5"), cs("v"));

        let new_span = first_span(&to_vec_from_v1(&newb.into_payload()));
        let old_span = first_span(&to_vec_from_v1(&oldb.into_payload()));
        assert_eq!(
            sorted_bucket(&new_span, "meta"),
            sorted_bucket(&old_span, "meta"),
            "v0.4 meta keys+values of native nesting must match the old flat dotted meta"
        );
        assert_eq!(sorted_bucket(&new_span, "metrics"), sorted_bucket(&old_span, "metrics"));
    }

    #[test]
    fn metrics_nested_matches_old_flat_v04() {
        // NEW: metrics attr "mm" = { nums: [ 1.0, 2.5 ], deep: { x: 0.0 } }
        // (Float leaves, as the metrics path uses zval_get_double -> convert_to_double=true.)
        let (mut newb, c, s) = one_span_builder();
        unsafe {
            let mm = ddog_span_attr_open_map(&mut newb, c, s, cs("mm"));
            let nums = ddog_attr_map_open_list(mm, cs("nums"));
            ddog_attr_list_push_double(nums, 1.0);
            ddog_attr_list_push_double(nums, 2.5);
            ddog_attr_close(nums);
            let deep = ddog_attr_map_open_map(mm, cs("deep"));
            ddog_attr_map_put_double(deep, cs("x"), 0.0); // empty/recursive placeholder equivalent
            ddog_attr_close(deep);
            ddog_attr_close(mm);
        }

        let (mut oldb, c, s) = one_span_builder();
        ddog_add_span_attr_double_cs(&mut oldb, c, s, cs("mm.nums.0"), 1.0);
        ddog_add_span_attr_double_cs(&mut oldb, c, s, cs("mm.nums.1"), 2.5);
        ddog_add_span_attr_double_cs(&mut oldb, c, s, cs("mm.deep.x"), 0.0);

        let new_span = first_span(&to_vec_from_v1(&newb.into_payload()));
        let old_span = first_span(&to_vec_from_v1(&oldb.into_payload()));
        assert_eq!(
            sorted_bucket(&new_span, "metrics"),
            sorted_bucket(&old_span, "metrics"),
            "v0.4 metrics keys+values of native nesting must match the old flat dotted metrics"
        );
        assert_eq!(sorted_bucket(&new_span, "meta"), sorted_bucket(&old_span, "meta"));
    }
}
