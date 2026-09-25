use datadog_sidecar_ffi::span::{
    ChunkNode, SpanNode, TracerPayloadV1Builder, DDOG_V1_ATTR_BOOL, DDOG_V1_ATTR_BYTES,
    DDOG_V1_ATTR_DOUBLE, DDOG_V1_ATTR_INT, DDOG_V1_ATTR_KEYVALUE, DDOG_V1_ATTR_LIST,
    DDOG_V1_ATTR_STRING,
};
use libdd_common_ffi::slice::{AsBytes, CharSlice};
use libdd_tinybytes::{Bytes, BytesString, RefCountedCell, RefCountedCellVTable};
use libdd_trace_utils::span::v1::{AttributeValueBytes, SpanEventBytes, SpanKind, SpanLinkBytes};
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

// Native V1 fill surface: builds the `TracerPayloadV1Builder` directly. Each chunk/span/link/event
// is its own heap allocation; a creator returns the new node's raw pointer and per-node mutators
// take only that pointer, materializing `&mut *ptr` against the node's own allocation. Stacked- and
// Tree-Borrows soundness: a mutation never reborrows `&mut builder` (which would pop an outstanding
// node pointer's tag), and a sibling push never moves an existing node, so a held pointer stays
// valid across sibling pushes (the inferred-span case). The C caller keeps at most one live handle
// per node in scope.

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

/// Appends a chunk carrying the 128-bit trace id (high/low halves), returning its node pointer.
#[no_mangle]
pub extern "C" fn ddog_new_chunk(
    builder: &mut TracerPayloadV1Builder,
    trace_id_high: u64,
    trace_id_low: u64,
) -> *mut ChunkNode {
    builder.push_chunk(trace_id_high, trace_id_low)
}

/// Number of spans already in `chunk` (so C can detect the first span of a chunk).
///
/// # Safety
/// `chunk` must be a live chunk node pointer from [`ddog_new_chunk`].
#[no_mangle]
pub unsafe extern "C" fn ddog_chunk_span_count(chunk: *mut ChunkNode) -> usize {
    (*chunk).span_count()
}

/// Appends an empty span to `chunk`, returning its node pointer.
///
/// # Safety
/// `chunk` must be a live chunk node pointer from [`ddog_new_chunk`].
#[no_mangle]
pub unsafe extern "C" fn ddog_new_span(chunk: *mut ChunkNode) -> *mut SpanNode {
    (*chunk).push_span()
}

/// Appends an empty link to `span`, returning its node pointer.
///
/// # Safety
/// `span` must be a live span node pointer from [`ddog_new_span`].
#[no_mangle]
pub unsafe extern "C" fn ddog_new_link(span: *mut SpanNode) -> *mut SpanLinkBytes {
    (*span).push_link()
}

/// Appends an empty event to `span`, returning its node pointer.
///
/// # Safety
/// `span` must be a live span node pointer from [`ddog_new_span`].
#[no_mangle]
pub unsafe extern "C" fn ddog_new_event(span: *mut SpanNode) -> *mut SpanEventBytes {
    (*span).push_event()
}

// ------------------- Span scalar fields -------------------

/// # Safety
/// `span` must be a live span node pointer from [`ddog_new_span`] (applies to every span mutator).
#[no_mangle]
pub unsafe extern "C" fn ddog_span_set_id(span: *mut SpanNode, value: u64) {
    (*span).span_mut().span_id = value;
}

/// # Safety
/// See [`ddog_span_set_id`].
#[no_mangle]
pub unsafe extern "C" fn ddog_span_set_parent_id(span: *mut SpanNode, value: u64) {
    (*span).span_mut().parent_id = value;
}

/// # Safety
/// See [`ddog_span_set_id`].
#[no_mangle]
pub unsafe extern "C" fn ddog_span_set_start(span: *mut SpanNode, value: i64) {
    (*span).span_mut().start = value;
}

/// # Safety
/// See [`ddog_span_set_id`].
#[no_mangle]
pub unsafe extern "C" fn ddog_span_set_duration(span: *mut SpanNode, value: i64) {
    (*span).span_mut().duration = value;
}

/// # Safety
/// See [`ddog_span_set_id`].
#[no_mangle]
pub unsafe extern "C" fn ddog_span_set_error(span: *mut SpanNode, error: bool) {
    (*span).span_mut().error = error;
}

/// Reads the span error flag (used to mirror error state onto an inferred span).
///
/// # Safety
/// See [`ddog_span_set_id`].
#[no_mangle]
pub unsafe extern "C" fn ddog_span_get_error(span: *mut SpanNode) -> bool {
    (*span).span().error
}

// ------------------- Span string fields (ZendString, zero-copy refcounted) -------------------

/// # Safety
/// See [`ddog_span_set_id`].
#[no_mangle]
pub unsafe extern "C" fn ddog_set_span_service_zstr(span: *mut SpanNode, str: &mut ZendString) {
    (*span).span_mut().service = convert_zend_to_bytes_string(str);
}

/// # Safety
/// See [`ddog_span_set_id`].
#[no_mangle]
pub unsafe extern "C" fn ddog_set_span_name_zstr(span: *mut SpanNode, str: &mut ZendString) {
    (*span).span_mut().name = convert_zend_to_bytes_string(str);
}

/// # Safety
/// See [`ddog_span_set_id`].
#[no_mangle]
pub unsafe extern "C" fn ddog_set_span_resource_zstr(span: *mut SpanNode, str: &mut ZendString) {
    (*span).span_mut().resource = convert_zend_to_bytes_string(str);
}

/// # Safety
/// See [`ddog_span_set_id`].
#[no_mangle]
pub unsafe extern "C" fn ddog_set_span_type_zstr(span: *mut SpanNode, str: &mut ZendString) {
    (*span).span_mut().r#type = convert_zend_to_bytes_string(str);
}

// ------------------- Promoted span fields (properties-direct) -------------------

/// # Safety
/// See [`ddog_span_set_id`].
#[no_mangle]
pub unsafe extern "C" fn ddog_set_span_env(span: *mut SpanNode, value: CharSlice) {
    set_field_cs(&mut (*span).span_mut().env, value);
}

/// # Safety
/// See [`ddog_span_set_id`].
#[no_mangle]
pub unsafe extern "C" fn ddog_set_span_version(span: *mut SpanNode, value: CharSlice) {
    set_field_cs(&mut (*span).span_mut().version, value);
}

/// # Safety
/// See [`ddog_span_set_id`].
#[no_mangle]
pub unsafe extern "C" fn ddog_set_span_component(span: *mut SpanNode, value: CharSlice) {
    set_field_cs(&mut (*span).span_mut().component, value);
}

/// Sets the span kind from an OTEL wire value (unset/unknown → Internal).
///
/// # Safety
/// See [`ddog_span_set_id`].
#[no_mangle]
pub unsafe extern "C" fn ddog_set_span_kind(span: *mut SpanNode, kind: u32) {
    (*span).span_mut().span_kind = SpanKind::from(kind);
}

/// Sets the span kind from a v0.4 `span.kind` meta string (mapping owned by libdatadog's
/// `SpanKind::from_meta`; unknown → Internal).
///
/// Returns `true` only for server/client/producer/consumer; otherwise (incl. "internal") the
/// caller must keep `value` as a plain attribute, as `Internal` has no wire slot for it.
///
/// # Safety
/// See [`ddog_span_set_id`].
#[no_mangle]
pub unsafe extern "C" fn ddog_set_span_kind_str(span: *mut SpanNode, value: CharSlice) -> bool {
    let s = String::from_utf8_lossy(value.as_bytes().as_ref());
    (*span).span_mut().span_kind = SpanKind::from_meta(s.as_ref());
    matches!(s.as_ref(), "server" | "client" | "producer" | "consumer")
}

// ------------------- Span attributes (unified V1 map, subsumes meta/metrics/meta_struct) -------------------

/// # Safety
/// See [`ddog_span_set_id`] (applies to every span-attribute mutator/reader below).
#[no_mangle]
pub unsafe extern "C" fn ddog_add_span_attr_cs_cs(span: *mut SpanNode, key: CharSlice, value: CharSlice) {
    let (key, value) = (
        convert_char_slice_to_bytes_string(key),
        AttributeValueBytes::String(convert_char_slice_to_bytes_string(value)),
    );
    insert_attr(&mut (*span).span_mut().attributes, key, value);
}

/// # Safety
/// See [`ddog_span_set_id`].
#[no_mangle]
pub unsafe extern "C" fn ddog_add_span_attr_lit_cs(
    span: *mut SpanNode,
    key: *const c_char,
    value: CharSlice,
) {
    let (key, value) = (
        convert_literal_to_bytes_string(key),
        AttributeValueBytes::String(convert_char_slice_to_bytes_string(value)),
    );
    insert_attr(&mut (*span).span_mut().attributes, key, value);
}

/// # Safety
/// See [`ddog_span_set_id`].
#[no_mangle]
pub unsafe extern "C" fn ddog_add_span_attr_zstr_cs(
    span: *mut SpanNode,
    key: &mut ZendString,
    value: CharSlice,
) {
    let (key, value) = (
        convert_zend_to_bytes_string(key),
        AttributeValueBytes::String(convert_char_slice_to_bytes_string(value)),
    );
    insert_attr(&mut (*span).span_mut().attributes, key, value);
}

/// # Safety
/// See [`ddog_span_set_id`].
#[no_mangle]
pub unsafe extern "C" fn ddog_add_span_attr_zstr_zstr(
    span: *mut SpanNode,
    key: &mut ZendString,
    value: &mut ZendString,
) {
    let (key, value) = (
        convert_zend_to_bytes_string(key),
        AttributeValueBytes::String(convert_zend_to_bytes_string(value)),
    );
    insert_attr(&mut (*span).span_mut().attributes, key, value);
}

/// Adds a numeric (double) attribute under a `CharSlice` key.
///
/// # Safety
/// See [`ddog_span_set_id`].
#[no_mangle]
pub unsafe extern "C" fn ddog_add_span_attr_double_cs(span: *mut SpanNode, key: CharSlice, value: f64) {
    let key = convert_char_slice_to_bytes_string(key);
    insert_attr(&mut (*span).span_mut().attributes, key, AttributeValueBytes::Float(value));
}

/// Adds a numeric (double) attribute under a static C literal key.
///
/// # Safety
/// See [`ddog_span_set_id`].
#[no_mangle]
pub unsafe extern "C" fn ddog_add_span_attr_double_lit(
    span: *mut SpanNode,
    key: *const c_char,
    value: f64,
) {
    let key = convert_literal_to_bytes_string(key);
    insert_attr(&mut (*span).span_mut().attributes, key, AttributeValueBytes::Float(value));
}

/// Adds a numeric (double) attribute under a `ZendString` key.
///
/// # Safety
/// See [`ddog_span_set_id`].
#[no_mangle]
pub unsafe extern "C" fn ddog_add_span_attr_double_zstr(
    span: *mut SpanNode,
    key: &mut ZendString,
    value: f64,
) {
    let key = convert_zend_to_bytes_string(key);
    insert_attr(&mut (*span).span_mut().attributes, key, AttributeValueBytes::Float(value));
}

/// Adds an integer attribute under a `CharSlice` key.
///
/// # Safety
/// See [`ddog_span_set_id`].
#[no_mangle]
pub unsafe extern "C" fn ddog_add_span_attr_int_cs(span: *mut SpanNode, key: CharSlice, value: i64) {
    let key = convert_char_slice_to_bytes_string(key);
    insert_attr(&mut (*span).span_mut().attributes, key, AttributeValueBytes::Int(value));
}

/// Adds a boolean attribute under a `CharSlice` key.
///
/// # Safety
/// See [`ddog_span_set_id`].
#[no_mangle]
pub unsafe extern "C" fn ddog_add_span_attr_bool_cs(span: *mut SpanNode, key: CharSlice, value: bool) {
    let key = convert_char_slice_to_bytes_string(key);
    insert_attr(&mut (*span).span_mut().attributes, key, AttributeValueBytes::Bool(value));
}

/// Adds a bytes-valued attribute (v0.4 `meta_struct`) under a `ZendString` key. The value bytes are
/// copied verbatim and encoded as msgpack `bin`.
///
/// # Safety
/// See [`ddog_span_set_id`].
#[no_mangle]
pub unsafe extern "C" fn ddog_add_span_attr_bytes_zstr(
    span: *mut SpanNode,
    key: &mut ZendString,
    value: CharSlice,
) {
    let (key, value) = (
        convert_zend_to_bytes_string(key),
        AttributeValueBytes::Bytes(Bytes::copy_from_slice(value.as_bytes())),
    );
    insert_attr(&mut (*span).span_mut().attributes, key, value);
}

/// Whether the span carries an attribute under `key` (`ZendString`). Mirrors the v0.4
/// `has_span_meta`/`has_span_metrics` guard so the generic loops never overwrite a promoted value.
///
/// # Safety
/// See [`ddog_span_set_id`].
#[no_mangle]
pub unsafe extern "C" fn ddog_has_span_attr_zstr(span: *mut SpanNode, key: &mut ZendString) -> bool {
    let key = convert_zend_to_bytes_string(key);
    (*span).span().attributes.contains_key(&key)
}

/// Copies the attribute `key` from `from_span` onto `to_span`, returning whether the source had it;
/// removes it from the source when `delete_source` is set. Type-preserving. The two spans are
/// distinct allocations, so the read-clone and the write reborrow are sequenced against separate
/// borrow stacks.
///
/// # Safety
/// `from_span`/`to_span` must be live, distinct span node pointers from [`ddog_new_span`].
#[no_mangle]
pub unsafe extern "C" fn ddog_transfer_span_attr(
    from_span: *mut SpanNode,
    to_span: *mut SpanNode,
    key: *const c_char,
    delete_source: bool,
) -> bool {
    let key = convert_literal_to_bytes_string(key);
    let value = match (*from_span).span().attributes.get(&key) {
        Some(v) => clone_attr(v),
        None => return false,
    };
    (*to_span).span_mut().attributes.insert(key.clone(), value);
    if delete_source {
        (*from_span).span_mut().attributes.remove_slow(&key);
    }
    true
}

// ------------------- Chunk-level fields -------------------

/// # Safety
/// `chunk` must be a live chunk node pointer from [`ddog_new_chunk`] (every chunk mutator below).
#[no_mangle]
pub unsafe extern "C" fn ddog_set_chunk_origin(chunk: *mut ChunkNode, origin: CharSlice) {
    set_field_cs(&mut (*chunk).chunk_mut().origin, origin);
}

/// # Safety
/// See [`ddog_set_chunk_origin`].
#[no_mangle]
pub unsafe extern "C" fn ddog_set_chunk_sampling_priority(chunk: *mut ChunkNode, priority: i32) {
    (*chunk).chunk_mut().priority = Some(priority);
}

/// # Safety
/// See [`ddog_set_chunk_origin`].
#[no_mangle]
pub unsafe extern "C" fn ddog_set_chunk_sampling_mechanism(chunk: *mut ChunkNode, mechanism: u32) {
    (*chunk).chunk_mut().sampling_mechanism = Some(mechanism);
}

// ------------------- Span links -------------------

/// # Safety
/// `link` must be a live link node pointer from [`ddog_new_link`] (every link mutator below).
#[no_mangle]
pub unsafe extern "C" fn ddog_link_set_trace_id(
    link: *mut SpanLinkBytes,
    trace_id_high: u64,
    trace_id_low: u64,
) {
    let l = &mut *link;
    l.trace_id[..8].copy_from_slice(&trace_id_high.to_be_bytes());
    l.trace_id[8..].copy_from_slice(&trace_id_low.to_be_bytes());
}

/// # Safety
/// See [`ddog_link_set_trace_id`].
#[no_mangle]
pub unsafe extern "C" fn ddog_link_set_span_id(link: *mut SpanLinkBytes, value: u64) {
    (*link).span_id = value;
}

/// # Safety
/// See [`ddog_link_set_trace_id`].
#[no_mangle]
pub unsafe extern "C" fn ddog_link_set_tracestate(link: *mut SpanLinkBytes, value: CharSlice) {
    set_field_cs(&mut (*link).tracestate, value);
}

/// # Safety
/// See [`ddog_link_set_trace_id`].
#[no_mangle]
pub unsafe extern "C" fn ddog_link_add_attr_str(
    link: *mut SpanLinkBytes,
    key: CharSlice,
    value: CharSlice,
) {
    let (key, value) = (
        convert_char_slice_to_bytes_string(key),
        AttributeValueBytes::String(convert_char_slice_to_bytes_string(value)),
    );
    insert_attr(&mut (*link).attributes, key, value);
}

// ------------------- Span events -------------------

/// # Safety
/// `event` must be a live event node pointer from [`ddog_new_event`] (every event mutator below).
#[no_mangle]
pub unsafe extern "C" fn ddog_event_set_name(event: *mut SpanEventBytes, value: CharSlice) {
    set_field_cs(&mut (*event).name, value);
}

/// # Safety
/// See [`ddog_event_set_name`].
#[no_mangle]
pub unsafe extern "C" fn ddog_event_set_time(event: *mut SpanEventBytes, time_unix_nano: u64) {
    (*event).time_unix_nano = time_unix_nano;
}

/// # Safety
/// See [`ddog_event_set_name`].
#[no_mangle]
pub unsafe extern "C" fn ddog_event_add_attr_str(
    event: *mut SpanEventBytes,
    key: CharSlice,
    value: CharSlice,
) {
    let (key, value) = (
        convert_char_slice_to_bytes_string(key),
        AttributeValueBytes::String(convert_char_slice_to_bytes_string(value)),
    );
    insert_attr(&mut (*event).attributes, key, value);
}

/// # Safety
/// See [`ddog_event_set_name`].
#[no_mangle]
pub unsafe extern "C" fn ddog_event_add_attr_int(event: *mut SpanEventBytes, key: CharSlice, value: i64) {
    let key = convert_char_slice_to_bytes_string(key);
    insert_attr(&mut (*event).attributes, key, AttributeValueBytes::Int(value));
}

/// # Safety
/// See [`ddog_event_set_name`].
#[no_mangle]
pub unsafe extern "C" fn ddog_event_add_attr_double(
    event: *mut SpanEventBytes,
    key: CharSlice,
    value: f64,
) {
    let key = convert_char_slice_to_bytes_string(key);
    insert_attr(&mut (*event).attributes, key, AttributeValueBytes::Float(value));
}

/// # Safety
/// See [`ddog_event_set_name`].
#[no_mangle]
pub unsafe extern "C" fn ddog_event_add_attr_bool(
    event: *mut SpanEventBytes,
    key: CharSlice,
    value: bool,
) {
    let key = convert_char_slice_to_bytes_string(key);
    insert_attr(&mut (*event).attributes, key, AttributeValueBytes::Bool(value));
}

// ------------------- Nested attributes: owned containers (write side) -------------------
//
// C builds a nested value bottom-up: allocate a list/map, fill it, push it into its parent (which
// takes ownership), and finally attach the outermost container to a span, link or event.

/// An owned `List` attribute value under construction. Opaque to C (`ddog_AttrList *`).
pub struct AttrList(Vec<AttributeValueBytes>);

/// An owned `KeyValue` attribute value under construction. Opaque to C (`ddog_AttrMap *`).
pub struct AttrMap(VecMap<BytesString, AttributeValueBytes>);

/// Takes ownership of a C-held list and returns it as an attribute value.
#[inline]
unsafe fn take_list(list: *mut AttrList) -> AttributeValueBytes {
    AttributeValueBytes::List(Box::from_raw(list).0)
}

/// Takes ownership of a C-held map and returns it as an attribute value.
#[inline]
unsafe fn take_map(map: *mut AttrMap) -> AttributeValueBytes {
    AttributeValueBytes::KeyValue(Box::from_raw(map).0)
}

/// Allocates an empty list with room for `capacity` elements. Ownership passes to C until it is
/// pushed into a parent or attached to a node.
#[no_mangle]
pub extern "C" fn ddog_attr_list_new(capacity: usize) -> *mut AttrList {
    Box::into_raw(Box::new(AttrList(Vec::with_capacity(capacity))))
}

/// Allocates an empty map with room for `capacity` members. Ownership passes to C until it is
/// pushed into a parent or attached to a node.
#[no_mangle]
pub extern "C" fn ddog_attr_map_new(capacity: usize) -> *mut AttrMap {
    Box::into_raw(Box::new(AttrMap(VecMap::with_capacity(capacity))))
}

// ---- Scalar leaves: list append ----

/// # Safety
/// `list` must be a live list from [`ddog_attr_list_new`] (every list mutator below).
#[no_mangle]
pub unsafe extern "C" fn ddog_attr_list_push_str(list: *mut AttrList, value: CharSlice) {
    (*list).0.push(AttributeValueBytes::String(convert_char_slice_to_bytes_string(value)));
}

/// # Safety
/// See [`ddog_attr_list_push_str`].
#[no_mangle]
pub unsafe extern "C" fn ddog_attr_list_push_int(list: *mut AttrList, value: i64) {
    (*list).0.push(AttributeValueBytes::Int(value));
}

/// # Safety
/// See [`ddog_attr_list_push_str`].
#[no_mangle]
pub unsafe extern "C" fn ddog_attr_list_push_double(list: *mut AttrList, value: f64) {
    (*list).0.push(AttributeValueBytes::Float(value));
}

/// # Safety
/// See [`ddog_attr_list_push_str`].
#[no_mangle]
pub unsafe extern "C" fn ddog_attr_list_push_bool(list: *mut AttrList, value: bool) {
    (*list).0.push(AttributeValueBytes::Bool(value));
}

/// # Safety
/// See [`ddog_attr_list_push_str`].
#[no_mangle]
pub unsafe extern "C" fn ddog_attr_list_push_bytes(list: *mut AttrList, value: CharSlice) {
    (*list).0.push(AttributeValueBytes::Bytes(Bytes::copy_from_slice(value.as_bytes())));
}

// ---- Scalar leaves: map insert ----

/// # Safety
/// `map` must be a live map from [`ddog_attr_map_new`] (every map mutator below).
#[no_mangle]
pub unsafe extern "C" fn ddog_attr_map_put_str(map: *mut AttrMap, key: CharSlice, value: CharSlice) {
    (*map).0.insert(
        convert_char_slice_to_bytes_string(key),
        AttributeValueBytes::String(convert_char_slice_to_bytes_string(value)),
    );
}

/// # Safety
/// See [`ddog_attr_map_put_str`].
#[no_mangle]
pub unsafe extern "C" fn ddog_attr_map_put_int(map: *mut AttrMap, key: CharSlice, value: i64) {
    (*map).0.insert(convert_char_slice_to_bytes_string(key), AttributeValueBytes::Int(value));
}

/// # Safety
/// See [`ddog_attr_map_put_str`].
#[no_mangle]
pub unsafe extern "C" fn ddog_attr_map_put_double(map: *mut AttrMap, key: CharSlice, value: f64) {
    (*map).0.insert(convert_char_slice_to_bytes_string(key), AttributeValueBytes::Float(value));
}

/// # Safety
/// See [`ddog_attr_map_put_str`].
#[no_mangle]
pub unsafe extern "C" fn ddog_attr_map_put_bool(map: *mut AttrMap, key: CharSlice, value: bool) {
    (*map).0.insert(convert_char_slice_to_bytes_string(key), AttributeValueBytes::Bool(value));
}

/// # Safety
/// See [`ddog_attr_map_put_str`].
#[no_mangle]
pub unsafe extern "C" fn ddog_attr_map_put_bytes(map: *mut AttrMap, key: CharSlice, value: CharSlice) {
    (*map).0.insert(
        convert_char_slice_to_bytes_string(key),
        AttributeValueBytes::Bytes(Bytes::copy_from_slice(value.as_bytes())),
    );
}

// ---- Nesting: the parent takes ownership of `child` ----

/// # Safety
/// `list` must be a live list; `child` a live list, which is consumed.
#[no_mangle]
pub unsafe extern "C" fn ddog_attr_list_push_list(list: *mut AttrList, child: *mut AttrList) {
    (*list).0.push(take_list(child));
}

/// # Safety
/// `list` must be a live list; `child` a live map, which is consumed.
#[no_mangle]
pub unsafe extern "C" fn ddog_attr_list_push_map(list: *mut AttrList, child: *mut AttrMap) {
    (*list).0.push(take_map(child));
}

/// # Safety
/// `map` must be a live map; `child` a live list, which is consumed.
#[no_mangle]
pub unsafe extern "C" fn ddog_attr_map_put_list(map: *mut AttrMap, key: CharSlice, child: *mut AttrList) {
    (*map).0.insert(convert_char_slice_to_bytes_string(key), take_list(child));
}

/// # Safety
/// `map` must be a live map; `child` a live map, which is consumed.
#[no_mangle]
pub unsafe extern "C" fn ddog_attr_map_put_map(map: *mut AttrMap, key: CharSlice, child: *mut AttrMap) {
    (*map).0.insert(convert_char_slice_to_bytes_string(key), take_map(child));
}

// ---- Attaching: the node takes ownership of the container ----

/// Sets `span.attributes[key]` to `list`, which is consumed.
///
/// # Safety
/// `span` must be a live span node pointer from [`ddog_new_span`]; `list` a live list.
#[no_mangle]
pub unsafe extern "C" fn ddog_span_attr_set_list(span: *mut SpanNode, key: CharSlice, list: *mut AttrList) {
    let key = convert_char_slice_to_bytes_string(key);
    insert_attr(&mut (*span).span_mut().attributes, key, take_list(list));
}

/// Sets `span.attributes[key]` to `map`, which is consumed.
///
/// # Safety
/// `span` must be a live span node pointer from [`ddog_new_span`]; `map` a live map.
#[no_mangle]
pub unsafe extern "C" fn ddog_span_attr_set_map(span: *mut SpanNode, key: CharSlice, map: *mut AttrMap) {
    let key = convert_char_slice_to_bytes_string(key);
    insert_attr(&mut (*span).span_mut().attributes, key, take_map(map));
}

/// Sets `link.attributes[key]` to `list`, which is consumed.
///
/// # Safety
/// `link` must be a live link node pointer from [`ddog_new_link`]; `list` a live list.
#[no_mangle]
pub unsafe extern "C" fn ddog_link_attr_set_list(
    link: *mut SpanLinkBytes,
    key: CharSlice,
    list: *mut AttrList,
) {
    let key = convert_char_slice_to_bytes_string(key);
    insert_attr(&mut (*link).attributes, key, take_list(list));
}

/// Sets `link.attributes[key]` to `map`, which is consumed.
///
/// # Safety
/// `link` must be a live link node pointer from [`ddog_new_link`]; `map` a live map.
#[no_mangle]
pub unsafe extern "C" fn ddog_link_attr_set_map(
    link: *mut SpanLinkBytes,
    key: CharSlice,
    map: *mut AttrMap,
) {
    let key = convert_char_slice_to_bytes_string(key);
    insert_attr(&mut (*link).attributes, key, take_map(map));
}

/// Sets `event.attributes[key]` to `list`, which is consumed.
///
/// # Safety
/// `event` must be a live event node pointer from [`ddog_new_event`]; `list` a live list.
#[no_mangle]
pub unsafe extern "C" fn ddog_event_attr_set_list(
    event: *mut SpanEventBytes,
    key: CharSlice,
    list: *mut AttrList,
) {
    let key = convert_char_slice_to_bytes_string(key);
    insert_attr(&mut (*event).attributes, key, take_list(list));
}

/// Sets `event.attributes[key]` to `map`, which is consumed.
///
/// # Safety
/// `event` must be a live event node pointer from [`ddog_new_event`]; `map` a live map.
#[no_mangle]
pub unsafe extern "C" fn ddog_event_attr_set_map(
    event: *mut SpanEventBytes,
    key: CharSlice,
    map: *mut AttrMap,
) {
    let key = convert_char_slice_to_bytes_string(key);
    insert_attr(&mut (*event).attributes, key, take_map(map));
}

// ------------------- Nested attributes: read-back (introspection) -------------------
//
// Path-addressed getters mirroring the write side, shared by spans, links and events. `node_kind`
// selects the attribute map (`DDOG_V1_ATTR_NODE_*`): the span's own map, or one of its links/events
// addressed by `node_idx`. `path[0]` indexes that top-level map; each further element indexes into
// the `List` (by position) or `KeyValue` (by member order) reached so far. Every call re-walks the
// path from the node root under a fresh shared borrow, so no borrow into the payload escapes to C.

/// The span's own attribute map.
pub const DDOG_V1_ATTR_NODE_SPAN: u32 = 0;
/// The attribute map of the link at `node_idx`.
pub const DDOG_V1_ATTR_NODE_LINK: u32 = 1;
/// The attribute map of the event at `node_idx`.
pub const DDOG_V1_ATTR_NODE_EVENT: u32 = 2;

/// The top-level attribute map of the selected node, or `None` if it does not exist.
#[inline]
unsafe fn node_attrs<'a>(
    builder: &'a TracerPayloadV1Builder,
    chunk: usize,
    span: usize,
    node_kind: u32,
    node_idx: usize,
) -> Option<&'a VecMap<BytesString, AttributeValueBytes>> {
    match node_kind {
        DDOG_V1_ATTR_NODE_LINK => Some(&builder.link(chunk, span, node_idx)?.attributes),
        DDOG_V1_ATTR_NODE_EVENT => Some(&builder.event(chunk, span, node_idx)?.attributes),
        _ => Some(&builder.span(chunk, span)?.attributes),
    }
}

/// Resolves the nested attribute value at `path` (length `path_len`) under the selected node, or
/// `None` if any step is out of range or descends into a scalar.
#[inline]
unsafe fn resolve_node_attr<'a>(
    builder: &'a TracerPayloadV1Builder,
    chunk: usize,
    span: usize,
    node_kind: u32,
    node_idx: usize,
    path: *const usize,
    path_len: usize,
) -> Option<&'a AttributeValueBytes> {
    let attrs = node_attrs(builder, chunk, span, node_kind, node_idx)?;
    let path = std::slice::from_raw_parts(path, path_len);
    let (&first, rest) = path.split_first()?;
    let mut cur = &attrs.iter().nth(first)?.1;
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
pub unsafe extern "C" fn ddog_v1_get_node_attr_child_count(
    builder: &TracerPayloadV1Builder,
    chunk: usize,
    span: usize,
    node_kind: u32,
    node_idx: usize,
    path: *const usize,
    path_len: usize,
) -> usize {
    match resolve_node_attr(builder, chunk, span, node_kind, node_idx, path, path_len) {
        Some(AttributeValueBytes::List(v)) => v.len(),
        Some(AttributeValueBytes::KeyValue(m)) => m.len(),
        _ => 0,
    }
}

/// `DDOG_V1_ATTR_*` tag of the value at `path` (STRING for an out-of-range path).
#[no_mangle]
pub unsafe extern "C" fn ddog_v1_get_node_attr_child_type(
    builder: &TracerPayloadV1Builder,
    chunk: usize,
    span: usize,
    node_kind: u32,
    node_idx: usize,
    path: *const usize,
    path_len: usize,
) -> u32 {
    resolve_node_attr(builder, chunk, span, node_kind, node_idx, path, path_len)
        .map_or(DDOG_V1_ATTR_STRING, attr_tag)
}

/// Member name of the value at `path` within its parent `KeyValue` (empty if the parent is a list
/// or the path is out of range). `path` must have length >= 1.
#[no_mangle]
pub unsafe extern "C" fn ddog_v1_get_node_attr_child_key(
    builder: &TracerPayloadV1Builder,
    chunk: usize,
    span: usize,
    node_kind: u32,
    node_idx: usize,
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
        // Parent is the node's top-level attribute map: the member name is the attribute name.
        return match node_attrs(builder, chunk, span, node_kind, node_idx)
            .and_then(|a| a.iter().nth(last))
        {
            Some((k, _)) => CharSlice::from_bytes(k.as_str().as_bytes()),
            None => CharSlice::empty(),
        };
    }
    // Nested parent: resolve the container at the parent path and read the member name at `last`.
    match resolve_node_attr(builder, chunk, span, node_kind, node_idx, path, parent_len) {
        Some(AttributeValueBytes::KeyValue(m)) => match m.iter().nth(last) {
            Some((k, _)) => CharSlice::from_bytes(k.as_str().as_bytes()),
            None => CharSlice::empty(),
        },
        _ => CharSlice::empty(),
    }
}

/// String value at `path` (empty if not a `String`).
#[no_mangle]
pub unsafe extern "C" fn ddog_v1_get_node_attr_child_str(
    builder: &TracerPayloadV1Builder,
    chunk: usize,
    span: usize,
    node_kind: u32,
    node_idx: usize,
    path: *const usize,
    path_len: usize,
) -> CharSlice {
    match resolve_node_attr(builder, chunk, span, node_kind, node_idx, path, path_len) {
        Some(AttributeValueBytes::String(s)) => CharSlice::from_bytes(s.as_str().as_bytes()),
        _ => CharSlice::empty(),
    }
}

/// Int value at `path` (0 if not an `Int`).
#[no_mangle]
pub unsafe extern "C" fn ddog_v1_get_node_attr_child_int(
    builder: &TracerPayloadV1Builder,
    chunk: usize,
    span: usize,
    node_kind: u32,
    node_idx: usize,
    path: *const usize,
    path_len: usize,
) -> i64 {
    match resolve_node_attr(builder, chunk, span, node_kind, node_idx, path, path_len) {
        Some(AttributeValueBytes::Int(v)) => *v,
        _ => 0,
    }
}

/// Double value at `path` (0.0 if not a `Float`).
#[no_mangle]
pub unsafe extern "C" fn ddog_v1_get_node_attr_child_double(
    builder: &TracerPayloadV1Builder,
    chunk: usize,
    span: usize,
    node_kind: u32,
    node_idx: usize,
    path: *const usize,
    path_len: usize,
) -> f64 {
    match resolve_node_attr(builder, chunk, span, node_kind, node_idx, path, path_len) {
        Some(AttributeValueBytes::Float(v)) => *v,
        _ => 0.0,
    }
}

/// Bool value at `path` (false if not a `Bool`).
#[no_mangle]
pub unsafe extern "C" fn ddog_v1_get_node_attr_child_bool(
    builder: &TracerPayloadV1Builder,
    chunk: usize,
    span: usize,
    node_kind: u32,
    node_idx: usize,
    path: *const usize,
    path_len: usize,
) -> bool {
    matches!(
        resolve_node_attr(builder, chunk, span, node_kind, node_idx, path, path_len),
        Some(AttributeValueBytes::Bool(true))
    )
}

/// Bytes value at `path` (empty if not `Bytes`).
#[no_mangle]
pub unsafe extern "C" fn ddog_v1_get_node_attr_child_bytes(
    builder: &TracerPayloadV1Builder,
    chunk: usize,
    span: usize,
    node_kind: u32,
    node_idx: usize,
    path: *const usize,
    path_len: usize,
) -> CharSlice {
    match resolve_node_attr(builder, chunk, span, node_kind, node_idx, path, path_len) {
        Some(AttributeValueBytes::Bytes(b)) => CharSlice::from_bytes(b.as_ref()),
        _ => CharSlice::empty(),
    }
}

#[cfg(test)]
mod attr_container_ffi_tests {
    // Exercises the nested-attribute container FFI end to end (new → fill → nest → attach) plus the
    // path-addressed read-back, so `cargo miri test` can prove the ownership transfers leak-free.
    use super::*;
    use libdd_common_ffi::slice::{AsBytes, CharSlice};

    fn cs(s: &str) -> CharSlice<'_> {
        CharSlice::from_bytes(s.as_bytes())
    }

    #[test]
    fn build_nested_attrs_and_read_back() {
        let mut b = TracerPayloadV1Builder::default();
        let chunk_ptr = b.push_chunk(0, 1);
        // Read-back getters address by index; the sole chunk/span are at 0/0.
        let (chunk, span) = (0usize, 0usize);
        let span_ptr = unsafe { (*chunk_ptr).push_span() };

        unsafe {
            // root: KeyValue { a: "x", n: 7, items: [ "first", 42, { flag: true } ] }
            let inner = ddog_attr_map_new(1);
            ddog_attr_map_put_bool(inner, cs("flag"), true);
            let items = ddog_attr_list_new(3);
            ddog_attr_list_push_str(items, cs("first"));
            ddog_attr_list_push_int(items, 42);
            ddog_attr_list_push_map(items, inner);
            let root = ddog_attr_map_new(3);
            ddog_attr_map_put_str(root, cs("a"), cs("x"));
            ddog_attr_map_put_int(root, cs("n"), 7);
            ddog_attr_map_put_list(root, cs("items"), items);
            ddog_span_attr_set_map(span_ptr, cs("root"), root);

            // A second top-level attribute: a List of one double.
            let tl = ddog_attr_list_new(1);
            ddog_attr_list_push_double(tl, 1.5);
            ddog_span_attr_set_list(span_ptr, cs("list"), tl);
        }

        // Read back through the span-kind node getters (`node_idx` unused for spans).
        let sp = DDOG_V1_ATTR_NODE_SPAN;
        unsafe {
            let ty = |p: &[usize]| ddog_v1_get_node_attr_child_type(&b, chunk, span, sp, 0, p.as_ptr(), p.len());
            let cnt = |p: &[usize]| ddog_v1_get_node_attr_child_count(&b, chunk, span, sp, 0, p.as_ptr(), p.len());
            let key = |p: &[usize]| ddog_v1_get_node_attr_child_key(&b, chunk, span, sp, 0, p.as_ptr(), p.len()).to_utf8_lossy().into_owned();
            let s = |p: &[usize]| ddog_v1_get_node_attr_child_str(&b, chunk, span, sp, 0, p.as_ptr(), p.len()).to_utf8_lossy().into_owned();
            let i = |p: &[usize]| ddog_v1_get_node_attr_child_int(&b, chunk, span, sp, 0, p.as_ptr(), p.len());
            let d = |p: &[usize]| ddog_v1_get_node_attr_child_double(&b, chunk, span, sp, 0, p.as_ptr(), p.len());
            let bl = |p: &[usize]| ddog_v1_get_node_attr_child_bool(&b, chunk, span, sp, 0, p.as_ptr(), p.len());

            // attr 0 = "root" (KeyValue with 3 members)
            assert_eq!(ty(&[0]), DDOG_V1_ATTR_KEYVALUE);
            assert_eq!(cnt(&[0]), 3);
            // root.a == "x", root.n == 7
            assert_eq!(key(&[0, 0]), "a");
            assert_eq!(s(&[0, 0]), "x");
            assert_eq!(i(&[0, 1]), 7);
            // root.items is a List of 3
            assert_eq!(key(&[0, 2]), "items");
            assert_eq!(ty(&[0, 2]), DDOG_V1_ATTR_LIST);
            assert_eq!(cnt(&[0, 2]), 3);
            // items[0] == "first", items[1] == 42, items[2] == { flag: true }
            assert_eq!(s(&[0, 2, 0]), "first");
            assert_eq!(i(&[0, 2, 1]), 42);
            assert_eq!(ty(&[0, 2, 2]), DDOG_V1_ATTR_KEYVALUE);
            assert_eq!(key(&[0, 2, 2, 0]), "flag");
            assert!(bl(&[0, 2, 2, 0]));
            // attr 1 = "list" (List with one double)
            assert_eq!(ty(&[1]), DDOG_V1_ATTR_LIST);
            assert_eq!(d(&[1, 0]), 1.5);
        }
    }

    // Top-level typed scalars keep their type (SpanData::$attributes input).
    #[test]
    fn top_level_int_and_bool_attrs_keep_their_type() {
        let mut b = TracerPayloadV1Builder::default();
        let chunk_ptr = b.push_chunk(0, 1);
        let span_ptr = unsafe { (*chunk_ptr).push_span() };
        unsafe {
            ddog_add_span_attr_int_cs(span_ptr, cs("i"), -7);
            ddog_add_span_attr_bool_cs(span_ptr, cs("b"), true);
            ddog_add_span_attr_int_cs(span_ptr, cs(""), 1); // empty keys are skipped
        }
        let sp = DDOG_V1_ATTR_NODE_SPAN;
        unsafe {
            assert_eq!(ddog_v1_get_node_attr_child_type(&b, 0, 0, sp, 0, [0usize].as_ptr(), 1), DDOG_V1_ATTR_INT);
            assert_eq!(ddog_v1_get_node_attr_child_int(&b, 0, 0, sp, 0, [0usize].as_ptr(), 1), -7);
            assert_eq!(ddog_v1_get_node_attr_child_type(&b, 0, 0, sp, 0, [1usize].as_ptr(), 1), DDOG_V1_ATTR_BOOL);
            assert!(ddog_v1_get_node_attr_child_bool(&b, 0, 0, sp, 0, [1usize].as_ptr(), 1));
            assert_eq!((*span_ptr).span().attributes.len(), 2);
        }
    }

    // Nested attributes attached to LINK and EVENT node pointers, read back through the same node
    // getters with the link/event node kinds.
    #[test]
    fn build_nested_link_and_event_attrs_and_read_back() {
        let mut b = TracerPayloadV1Builder::default();
        let chunk_ptr = b.push_chunk(0, 1);
        let (chunk, span) = (0usize, 0usize);
        let span_ptr = unsafe { (*chunk_ptr).push_span() };

        unsafe {
            // link[0].attributes = { nums: [ 1, 2 ] }
            let link = ddog_new_link(span_ptr);
            let nums = ddog_attr_list_new(2);
            ddog_attr_list_push_int(nums, 1);
            ddog_attr_list_push_int(nums, 2);
            ddog_link_attr_set_list(link, cs("nums"), nums);

            // event[0].attributes = { obj: { k: "v" } }
            let event = ddog_new_event(span_ptr);
            let obj = ddog_attr_map_new(1);
            ddog_attr_map_put_str(obj, cs("k"), cs("v"));
            ddog_event_attr_set_map(event, cs("obj"), obj);
        }

        let ln = DDOG_V1_ATTR_NODE_LINK;
        let ev = DDOG_V1_ATTR_NODE_EVENT;
        unsafe {
            // link[0].nums is a List [1, 2]
            assert_eq!(ddog_v1_get_node_attr_child_key(&b, chunk, span, ln, 0, [0usize].as_ptr(), 1).to_utf8_lossy(), "nums");
            assert_eq!(ddog_v1_get_node_attr_child_type(&b, chunk, span, ln, 0, [0usize].as_ptr(), 1), DDOG_V1_ATTR_LIST);
            assert_eq!(ddog_v1_get_node_attr_child_count(&b, chunk, span, ln, 0, [0usize].as_ptr(), 1), 2);
            assert_eq!(ddog_v1_get_node_attr_child_int(&b, chunk, span, ln, 0, [0usize, 1].as_ptr(), 2), 2);

            // event[0].obj is a KeyValue { k: "v" }
            assert_eq!(ddog_v1_get_node_attr_child_type(&b, chunk, span, ev, 0, [0usize].as_ptr(), 1), DDOG_V1_ATTR_KEYVALUE);
            assert_eq!(ddog_v1_get_node_attr_child_key(&b, chunk, span, ev, 0, [0usize, 0].as_ptr(), 2).to_utf8_lossy(), "k");
            assert_eq!(ddog_v1_get_node_attr_child_str(&b, chunk, span, ev, 0, [0usize, 0].as_ptr(), 2).to_utf8_lossy(), "v");
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

    fn one_span_builder() -> (TracerPayloadV1Builder, *mut SpanNode) {
        let mut b = TracerPayloadV1Builder::default();
        let c = b.push_chunk(0, 1);
        // Safety: `c` is the live chunk node just pushed.
        let s = unsafe { (*c).push_span() };
        (b, s)
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
        let (newb, s) = one_span_builder();
        unsafe {
            let bar = ddog_attr_list_new(3);
            ddog_attr_list_push_str(bar, cs("1"));
            let e = ddog_attr_map_new(1);
            ddog_attr_map_put_str(e, cs("key"), cs("2"));
            ddog_attr_list_push_map(bar, e);
            ddog_attr_list_push_str(bar, cs("")); // empty/recursive placeholder equivalent
            let m = ddog_attr_map_new(2);
            ddog_attr_map_put_list(m, cs("bar"), bar);
            ddog_attr_map_put_str(m, cs("5"), cs("v")); // numeric-keyed member -> "m.5"
            ddog_span_attr_set_map(s, cs("m"), m);
        }

        // OLD: the identical dotted keys the C flatten wrote, in traversal order.
        let (oldb, s) = one_span_builder();
        unsafe {
            ddog_add_span_attr_cs_cs(s, cs("m.bar.0"), cs("1"));
            ddog_add_span_attr_cs_cs(s, cs("m.bar.1.key"), cs("2"));
            ddog_add_span_attr_cs_cs(s, cs("m.bar.2"), cs(""));
            ddog_add_span_attr_cs_cs(s, cs("m.5"), cs("v"));
        }

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
        let (newb, s) = one_span_builder();
        unsafe {
            let mm = ddog_attr_map_new(2);
            let nums = ddog_attr_list_new(2);
            ddog_attr_list_push_double(nums, 1.0);
            ddog_attr_list_push_double(nums, 2.5);
            ddog_attr_map_put_list(mm, cs("nums"), nums);
            let deep = ddog_attr_map_new(1);
            ddog_attr_map_put_double(deep, cs("x"), 0.0); // empty/recursive placeholder equivalent
            ddog_attr_map_put_map(mm, cs("deep"), deep);
            ddog_span_attr_set_map(s, cs("mm"), mm);
        }

        let (oldb, s) = one_span_builder();
        unsafe {
            ddog_add_span_attr_double_cs(s, cs("mm.nums.0"), 1.0);
            ddog_add_span_attr_double_cs(s, cs("mm.nums.1"), 2.5);
            ddog_add_span_attr_double_cs(s, cs("mm.deep.x"), 0.0);
        }

        let new_span = first_span(&to_vec_from_v1(&newb.into_payload()));
        let old_span = first_span(&to_vec_from_v1(&oldb.into_payload()));
        assert_eq!(
            sorted_bucket(&new_span, "metrics"),
            sorted_bucket(&old_span, "metrics"),
            "v0.4 metrics keys+values of native nesting must match the old flat dotted metrics"
        );
        assert_eq!(sorted_bucket(&new_span, "meta"), sorted_bucket(&old_span, "meta"));
    }

    /// `span[key]` (a msgpack map field) as a borrowed value.
    fn field<'a>(v: &'a Value, key: &str) -> Option<&'a Value> {
        match v {
            Value::Map(m) => m.iter().find(|(k, _)| k.as_str() == Some(key)).map(|(_, v)| v),
            _ => None,
        }
    }

    #[test]
    fn link_nested_attr_matches_legacy_v04_meta() {
        // NEW: native nested link attr `nums = [3, 4]` built through the container FFI.
        let (newb, s) = one_span_builder();
        unsafe {
            let link = ddog_new_link(s);
            let nums = ddog_attr_list_new(2);
            ddog_attr_list_push_int(nums, 3);
            ddog_attr_list_push_int(nums, 4);
            ddog_link_attr_set_list(link, cs("nums"), nums);
        }
        // OLD: the JSON string the pre-native serializer produced (json_encode([3,4])).
        let (oldb, s) = one_span_builder();
        unsafe {
            let link = ddog_new_link(s);
            ddog_link_add_attr_str(link, cs("nums"), cs("[3,4]"));
        }

        let new_span = first_span(&to_vec_from_v1(&newb.into_payload()));
        let old_span = first_span(&to_vec_from_v1(&oldb.into_payload()));
        // v0.4 downgrade carries links as the legacy `_dd.span_links` meta JSON string (no native
        // `span_links` field); native nested `[3,4]` must match the old json_encode string byte-for-byte.
        assert!(field(&new_span, "span_links").is_none());
        let links_meta = |sp: &Value| {
            field(field(sp, "meta").unwrap(), "_dd.span_links")
                .unwrap()
                .as_str()
                .unwrap()
                .to_string()
        };
        let new_meta = links_meta(&new_span);
        assert!(new_meta.contains(r#""nums":"[3,4]""#), "got {new_meta}");
        assert_eq!(new_meta, links_meta(&old_span));
    }

    #[test]
    fn event_nested_attr_downgrades_to_legacy_events_meta_native_array() {
        // Native nested event attr `nums = [3, 4]` built through the container FFI. On the v0.4
        // downgrade it lands in the legacy `events` meta as a REAL JSON array (event attributes
        // keep native JSON types, matching master's json_encode of the PHP attributes array —
        // unlike links, which are String → String). No native `span_events` field on the v0.4 wire.
        let (newb, s) = one_span_builder();
        unsafe {
            let event = ddog_new_event(s);
            let nums = ddog_attr_list_new(2);
            ddog_attr_list_push_int(nums, 3);
            ddog_attr_list_push_int(nums, 4);
            ddog_event_attr_set_list(event, cs("nums"), nums);
        }
        let new_span = first_span(&to_vec_from_v1(&newb.into_payload()));
        assert!(field(&new_span, "span_events").is_none());
        let events_meta = field(field(&new_span, "meta").unwrap(), "events")
            .unwrap()
            .as_str()
            .unwrap();
        assert!(events_meta.contains(r#""nums":[3,4]"#), "got {events_meta}");
    }

    #[test]
    fn span_kind_str_process_survives_v04_downgrade() {
        // Mirrors tracer/serializer.c: only delete `span.kind` meta when canonical. Fixes
        // AMQPIntegration's `Tag::SPAN_KIND = 'process'`, previously destroyed by enum coercion.
        let (b, s) = one_span_builder();
        unsafe {
            let is_canonical = ddog_set_span_kind_str(s, cs("process"));
            assert!(
                !is_canonical,
                "\"process\" is not one of the 4 canonical kind strings"
            );
            ddog_add_span_attr_cs_cs(s, cs("span.kind"), cs("process"));
        }
        let span = first_span(&to_vec_from_v1(&b.into_payload()));
        assert_eq!(
            field(field(&span, "meta").unwrap(), "span.kind")
                .unwrap()
                .as_str(),
            Some("process")
        );
        // Exactly once: not duplicated as a generic attribute alongside the promoted field.
        let meta_entries = match field(&span, "meta").unwrap() {
            Value::Map(m) => m,
            other => panic!("expected map, got {other:?}"),
        };
        let kind_count = meta_entries
            .iter()
            .filter(|(k, _)| k.as_str() == Some("span.kind"))
            .count();
        assert_eq!(
            kind_count, 1,
            "duplicate \"span.kind\" key written to the wire"
        );
    }

    #[test]
    fn span_kind_str_internal_survives_v04_downgrade() {
        // An explicit "internal" is not one of the 4 canonical strings either, so it must
        // round-trip the same way "process" does, not get silently dropped.
        let (b, s) = one_span_builder();
        unsafe {
            let is_canonical = ddog_set_span_kind_str(s, cs("internal"));
            assert!(
                !is_canonical,
                "\"internal\" is not one of the 4 canonical kind strings"
            );
            ddog_add_span_attr_cs_cs(s, cs("span.kind"), cs("internal"));
        }
        let span = first_span(&to_vec_from_v1(&b.into_payload()));
        assert_eq!(
            field(field(&span, "meta").unwrap(), "span.kind")
                .unwrap()
                .as_str(),
            Some("internal")
        );
    }

    #[test]
    fn span_kind_str_known_value_is_canonical_and_not_duplicated() {
        let (b, s) = one_span_builder();
        unsafe {
            let is_canonical = ddog_set_span_kind_str(s, cs("server"));
            assert!(is_canonical);
            // serializer.c deletes the meta key in this case; not re-added as an attribute here.
        }
        let span = first_span(&to_vec_from_v1(&b.into_payload()));
        assert_eq!(
            field(field(&span, "meta").unwrap(), "span.kind")
                .unwrap()
                .as_str(),
            Some("server")
        );
    }
}

#[cfg(test)]
mod pointer_handle_miri_tests {
    // The crux of Phase 2 comment A: prove the Box-per-node pointer model is UB-clean under Stacked
    // AND Tree Borrows for the hazards the old index model was chosen to avoid. Run with
    // `cargo +nightly miri test` (Stacked Borrows, the default) and with `-Zmiri-tree-borrows`.
    use super::*;
    use datadog_sidecar_ffi::span::{ddog_free_charslice, ddog_v1_span_debug_log};

    fn cs(s: &str) -> CharSlice<'_> {
        CharSlice::from_bytes(s.as_bytes())
    }

    // (a) The inferred-span hazard (serializer.c ~2059→2098): after a SECOND span is pushed into the
    // same chunk, the outer frame keeps mutating and reading its ROOT span pointer with NO refetch.
    #[test]
    fn a_root_span_ptr_survives_sibling_push() {
        let mut b = TracerPayloadV1Builder::default();
        let chunk = b.push_chunk(0, 1);
        unsafe {
            let root = ddog_new_span(chunk);
            ddog_span_set_id(root, 100);
            ddog_span_set_error(root, true);
            ddog_add_span_attr_lit_cs(root, c"moved".as_ptr(), cs("v"));

            // The sibling push that reallocs `chunk.spans`; `root` must stay valid (own allocation).
            let inferred = ddog_new_span(chunk);
            ddog_span_set_id(inferred, 200);

            // Use `root` AFTER the sibling push, with no refetch (mirrors the transfers/debug log).
            assert!(ddog_transfer_span_attr(root, inferred, c"moved".as_ptr(), true));
            ddog_span_set_error(inferred, ddog_span_get_error(root));
            let log = ddog_v1_span_debug_log(chunk, root);
            assert!(!log.is_empty());
            ddog_free_charslice(log);
        }

        let payload = b.into_payload();
        let spans = &payload.chunks[0].spans;
        assert_eq!(spans.len(), 2);
        assert_eq!(spans[0].span_id, 100);
        assert_eq!(spans[1].span_id, 200);
        assert!(!spans[0].attributes.contains_key("moved"), "attr moved off root");
        assert!(spans[1].attributes.contains_key("moved"), "attr moved onto inferred");
    }

    // (b) A deep nested List/KeyValue built via the container FFI and attached to a span node
    // pointer, then folded through into_payload.
    #[test]
    fn b_nested_attr_build_on_span_ptr() {
        let mut b = TracerPayloadV1Builder::default();
        let chunk = b.push_chunk(0, 1);
        unsafe {
            let span = ddog_new_span(chunk);
            let nested = ddog_attr_map_new(1);
            ddog_attr_map_put_bool(nested, cs("flag"), true);
            let items = ddog_attr_list_new(2);
            ddog_attr_list_push_str(items, cs("a"));
            ddog_attr_list_push_map(items, nested);
            let root = ddog_attr_map_new(2);
            ddog_attr_map_put_int(root, cs("n"), 7);
            ddog_attr_map_put_list(root, cs("items"), items);
            ddog_span_attr_set_map(span, cs("root"), root);
        }
        let payload = b.into_payload();
        match payload.chunks[0].spans[0].attributes.get("root") {
            Some(AttributeValueBytes::KeyValue(m)) => assert_eq!(m.len(), 2),
            other => panic!("expected KeyValue, got {other:?}"),
        }
    }

    // (c) Links and events built on a span pointer, each fully built — including a deep nested
    // List/KeyValue attribute attached to the link/event node pointer — before the next push; the
    // node pointers stay valid across sibling node pushes.
    #[test]
    fn c_links_and_events_on_span_ptr() {
        let mut b = TracerPayloadV1Builder::default();
        let chunk = b.push_chunk(0, 1);
        unsafe {
            let span = ddog_new_span(chunk);
            let l0 = ddog_new_link(span);
            ddog_link_set_span_id(l0, 11);
            ddog_link_add_attr_str(l0, cs("k"), cs("v"));
            // Deep nested attr on l0: { tags: [ "a", { deep: 1 } ] } — fully built before l1.
            let deep = ddog_attr_map_new(1);
            ddog_attr_map_put_int(deep, cs("deep"), 1);
            let tags = ddog_attr_list_new(2);
            ddog_attr_list_push_str(tags, cs("a"));
            ddog_attr_list_push_map(tags, deep);
            ddog_link_attr_set_list(l0, cs("tags"), tags);

            let l1 = ddog_new_link(span); // sibling push; l0 stays valid (own allocation)
            ddog_link_set_span_id(l1, 22);
            ddog_link_set_span_id(l0, 111); // still valid after the sibling push

            let e0 = ddog_new_event(span);
            ddog_event_set_name(e0, cs("evt"));
            ddog_event_add_attr_int(e0, cs("n"), 5);
            // Deep nested attr on e0: { meta: { list: [ true ] } } — fully built before e1.
            let list = ddog_attr_list_new(1);
            ddog_attr_list_push_bool(list, true);
            let meta = ddog_attr_map_new(1);
            ddog_attr_map_put_list(meta, cs("list"), list);
            ddog_event_attr_set_map(e0, cs("meta"), meta);

            let e1 = ddog_new_event(span);
            ddog_event_set_time(e1, 999);
            ddog_event_set_name(e0, cs("evt0")); // e0 valid after e1's push
        }
        let payload = b.into_payload();
        let span = &payload.chunks[0].spans[0];
        assert_eq!(span.span_links.len(), 2);
        assert_eq!(span.span_links[0].span_id, 111);
        match span.span_links[0].attributes.get("tags") {
            Some(AttributeValueBytes::List(v)) => assert_eq!(v.len(), 2),
            other => panic!("expected link List attr, got {other:?}"),
        }
        assert_eq!(span.span_events.len(), 2);
        assert_eq!(span.span_events[0].name.as_str(), "evt0");
        match span.span_events[0].attributes.get("meta") {
            Some(AttributeValueBytes::KeyValue(m)) => assert_eq!(m.len(), 1),
            other => panic!("expected event KeyValue attr, got {other:?}"),
        }
    }

    // (d) into_payload dedups duplicate keys, and a builder dropped WITHOUT into_payload frees every
    // node box (Miri's leak/double-free checker is the assertion for the drop path).
    #[test]
    fn d_into_payload_dedup_and_drop() {
        let mut b = TracerPayloadV1Builder::default();
        let chunk = b.push_chunk(0, 1);
        unsafe {
            let span = ddog_new_span(chunk);
            ddog_add_span_attr_cs_cs(span, cs("dup"), cs("first"));
            ddog_add_span_attr_cs_cs(span, cs("dup"), cs("second"));
        }
        let payload = b.into_payload();
        let attrs = &payload.chunks[0].spans[0].attributes;
        assert_eq!(attrs.len(), 1, "duplicate keys deduped in into_payload");

        // Drop path: build a populated builder and let it fall out of scope unconsumed.
        let mut d = TracerPayloadV1Builder::default();
        let c = d.push_chunk(0, 2);
        unsafe {
            let s = ddog_new_span(c);
            ddog_new_link(s);
            ddog_new_event(s);
            let m = ddog_attr_map_new(1);
            ddog_attr_map_put_int(m, cs("y"), 1);
            ddog_span_attr_set_map(s, cs("x"), m);
        }
        drop(d);
    }

    // (e) A deep mixed nest (list in map in list), built once with capacity 0 (forcing regrowth) and
    // once with exact capacities; both attach the same value and free cleanly.
    #[test]
    fn e_deep_mixed_nest_any_capacity() {
        unsafe fn build(span: *mut SpanNode, key: &str, cap: impl Fn(usize) -> usize) {
            let inner = ddog_attr_list_new(cap(3));
            ddog_attr_list_push_int(inner, 1);
            ddog_attr_list_push_bytes(inner, cs("raw"));
            ddog_attr_list_push_list(inner, ddog_attr_list_new(cap(0)));
            let mid = ddog_attr_map_new(cap(2));
            ddog_attr_map_put_list(mid, cs("inner"), inner);
            ddog_attr_map_put_map(mid, cs("empty"), ddog_attr_map_new(cap(0)));
            let outer = ddog_attr_list_new(cap(2));
            ddog_attr_list_push_map(outer, mid);
            ddog_attr_list_push_str(outer, cs("tail"));
            ddog_span_attr_set_list(span, cs(key), outer);
        }
        let mut b = TracerPayloadV1Builder::default();
        let chunk = b.push_chunk(0, 1);
        unsafe {
            let span = ddog_new_span(chunk);
            build(span, "zero", |_| 0);
            build(span, "exact", |n| n);
        }
        let payload = b.into_payload();
        let attrs = &payload.chunks[0].spans[0].attributes;
        let shape = |key: &str| match attrs.get(key) {
            Some(AttributeValueBytes::List(outer)) => {
                assert_eq!(outer.len(), 2);
                assert!(matches!(&outer[1], AttributeValueBytes::String(s) if s.as_str() == "tail"));
                match &outer[0] {
                    AttributeValueBytes::KeyValue(mid) => {
                        assert!(matches!(mid.get("empty"), Some(AttributeValueBytes::KeyValue(m)) if m.is_empty()));
                        match mid.get("inner") {
                            Some(AttributeValueBytes::List(inner)) => {
                                assert!(matches!(inner[0], AttributeValueBytes::Int(1)));
                                assert!(matches!(&inner[1], AttributeValueBytes::Bytes(b) if b.as_ref() == b"raw"));
                                assert!(matches!(&inner[2], AttributeValueBytes::List(l) if l.is_empty()));
                            }
                            other => panic!("expected inner List, got {other:?}"),
                        }
                    }
                    other => panic!("expected KeyValue, got {other:?}"),
                }
            }
            other => panic!("expected List, got {other:?}"),
        };
        shape("zero");
        shape("exact");
    }
}
