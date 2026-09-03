use datadog_sidecar_ffi::span_v1::TracerPayloadV1Builder;
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

// ---------------------------------------------------------------------------
// Native V1 fill surface
//
// The functions below are the *only* C-facing surface for building a trace payload: they fill the
// native V1 model held by `TracerPayloadV1Builder` directly (no v0.4 `SpanBytes` is ever built).
// Chunks/spans/links/events are addressed by their `usize` index; C holds those indices in its
// `dd_span_sink`/`ddtrace_v1_ctx` handles and passes them back on every call. Soundness (Stacked
// Borrows): each call takes a single `&mut TracerPayloadV1Builder` (or `&` for reads) and resolves
// the target by index, so no `&mut` into the payload is ever handed out to C and outlives a call.
// ---------------------------------------------------------------------------

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

/// Copies the attribute under a static C literal `key` from `from_span` onto `to_span` (both within
/// `chunk`), returning whether the source carried it. When `delete_source` is set, it is also removed
/// from the source. Replicates the v0.4 `transfer_meta_data`/`transfer_metrics_data` inferred-span
/// merge; the unified V1 map subsumes both string and numeric cases with a type-preserving copy. The
/// read (clone) completes before any mutable borrow, so the whole op routes through a single `&mut`.
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
