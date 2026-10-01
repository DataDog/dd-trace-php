use datadog_sidecar_ffi::span::{attrs_mut, bytes_string_from_literal, Attributes, SpanNode};
use libdd_common_ffi::slice::CharSlice;
use libdd_tinybytes::{Bytes, BytesString, RefCountedCell, RefCountedCellVTable};
use libdd_trace_utils::span::v1::AttributeValueBytes;
use std::borrow::Cow;
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

// PHP-specific additions to the V1 builder FFI (`datadog_sidecar_ffi::span`): zero-copy
// zend_string fields and static C-literal keys.

/// # Safety
/// `span` must be a live span node pointer from `ddog_new_span` (every `*_zstr` span setter).
#[no_mangle]
pub unsafe extern "C" fn ddog_set_span_service_zstr(span: *mut SpanNode, str: &mut ZendString) {
    (*span).span_mut().service = convert_zend_to_bytes_string(str);
}

/// # Safety
/// See [`ddog_set_span_service_zstr`].
#[no_mangle]
pub unsafe extern "C" fn ddog_set_span_name_zstr(span: *mut SpanNode, str: &mut ZendString) {
    (*span).span_mut().name = convert_zend_to_bytes_string(str);
}

/// # Safety
/// See [`ddog_set_span_service_zstr`].
#[no_mangle]
pub unsafe extern "C" fn ddog_set_span_resource_zstr(span: *mut SpanNode, str: &mut ZendString) {
    (*span).span_mut().resource = convert_zend_to_bytes_string(str);
}

/// # Safety
/// See [`ddog_set_span_service_zstr`].
#[no_mangle]
pub unsafe extern "C" fn ddog_set_span_type_zstr(span: *mut SpanNode, str: &mut ZendString) {
    (*span).span_mut().r#type = convert_zend_to_bytes_string(str);
}

/// String attribute under a static C literal key.
///
/// # Safety
/// `attrs` must be a live `Attributes` handle and `key` a static NUL-terminated string (applies
/// to every attribute adder below).
#[no_mangle]
pub unsafe extern "C" fn ddog_attributes_add_lit(
    attrs: *mut Attributes,
    key: *const c_char,
    value: CharSlice,
) {
    let value = AttributeValueBytes::String(datadog_sidecar_ffi::span::bytes_string_from_slice(value));
    attrs_mut(attrs).insert(bytes_string_from_literal(key), value);
}

/// String attribute sharing both zend strings (zero-copy).
///
/// # Safety
/// See [`ddog_attributes_add_lit`].
#[no_mangle]
pub unsafe extern "C" fn ddog_attributes_add_zstr(
    attrs: *mut Attributes,
    key: &mut ZendString,
    value: &mut ZendString,
) {
    attrs_mut(attrs).insert(
        convert_zend_to_bytes_string(key),
        AttributeValueBytes::String(convert_zend_to_bytes_string(value)),
    );
}

/// Double attribute under a static C literal key.
///
/// # Safety
/// See [`ddog_attributes_add_lit`].
#[no_mangle]
pub unsafe extern "C" fn ddog_attributes_add_double_lit(
    attrs: *mut Attributes,
    key: *const c_char,
    value: f64,
) {
    attrs_mut(attrs).insert(bytes_string_from_literal(key), AttributeValueBytes::Float(value));
}

/// Double attribute under a shared zend string key (zero-copy).
///
/// # Safety
/// See [`ddog_attributes_add_lit`].
#[no_mangle]
pub unsafe extern "C" fn ddog_attributes_add_double_zstr(
    attrs: *mut Attributes,
    key: &mut ZendString,
    value: f64,
) {
    attrs_mut(attrs).insert(convert_zend_to_bytes_string(key), AttributeValueBytes::Float(value));
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
    use datadog_sidecar_ffi::span::*;
    use libdd_common_ffi::slice::CharSlice;
    use libdd_trace_utils::msgpack_encoder::v04::to_vec_from_v1;
    use libdd_trace_utils::span::v1::SpanKind;
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
            ddog_attributes_add_str(ddog_attr_map_get_attributes(e), cs("key"), cs("2"));
            ddog_attr_list_push_map(bar, e);
            ddog_attr_list_push_str(bar, cs("")); // empty/recursive placeholder equivalent
            let m = ddog_attr_map_new(2);
            ddog_attributes_add_list(ddog_attr_map_get_attributes(m), cs("bar"), bar);
            ddog_attributes_add_str(ddog_attr_map_get_attributes(m), cs("5"), cs("v")); // numeric-keyed member -> "m.5"
            ddog_attributes_add_map(ddog_span_get_attributes(s), cs("m"), m);
        }

        // OLD: the identical dotted keys the C flatten wrote, in traversal order.
        let (oldb, s) = one_span_builder();
        unsafe {
            ddog_attributes_add_str(ddog_span_get_attributes(s), cs("m.bar.0"), cs("1"));
            ddog_attributes_add_str(ddog_span_get_attributes(s), cs("m.bar.1.key"), cs("2"));
            ddog_attributes_add_str(ddog_span_get_attributes(s), cs("m.bar.2"), cs(""));
            ddog_attributes_add_str(ddog_span_get_attributes(s), cs("m.5"), cs("v"));
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
            ddog_attributes_add_list(ddog_attr_map_get_attributes(mm), cs("nums"), nums);
            let deep = ddog_attr_map_new(1);
            ddog_attributes_add_double(ddog_attr_map_get_attributes(deep), cs("x"), 0.0); // empty/recursive placeholder equivalent
            ddog_attributes_add_map(ddog_attr_map_get_attributes(mm), cs("deep"), deep);
            ddog_attributes_add_map(ddog_span_get_attributes(s), cs("mm"), mm);
        }

        let (oldb, s) = one_span_builder();
        unsafe {
            ddog_attributes_add_double(ddog_span_get_attributes(s), cs("mm.nums.0"), 1.0);
            ddog_attributes_add_double(ddog_span_get_attributes(s), cs("mm.nums.1"), 2.5);
            ddog_attributes_add_double(ddog_span_get_attributes(s), cs("mm.deep.x"), 0.0);
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
    fn link_nested_attr_matches_old_json_string_v04() {
        // NEW: native nested link attr `nums = [3, 4]` built through the container FFI.
        let (newb, s) = one_span_builder();
        unsafe {
            let link = ddog_new_link(s);
            let nums = ddog_attr_list_new(2);
            ddog_attr_list_push_int(nums, 3);
            ddog_attr_list_push_int(nums, 4);
            ddog_attributes_add_list(ddog_link_get_attributes(link), cs("nums"), nums);
        }
        // OLD: the JSON string the pre-native serializer produced (json_encode([3,4])).
        let (oldb, s) = one_span_builder();
        unsafe {
            let link = ddog_new_link(s);
            ddog_attributes_add_str(ddog_link_get_attributes(link), cs("nums"), cs("[3,4]"));
        }

        let new_span = first_span(&to_vec_from_v1(&newb.into_payload()));
        let old_span = first_span(&to_vec_from_v1(&oldb.into_payload()));
        // v0.4 links are native `span_links` with String attributes: the nested value carries the
        // old json_encode string.
        let nums = |sp: &Value| {
            let link = &field(sp, "span_links").unwrap().as_array().unwrap()[0];
            field(field(link, "attributes").unwrap(), "nums").unwrap().clone()
        };
        assert_eq!(nums(&new_span).as_str(), Some("[3,4]"));
        assert_eq!(nums(&new_span), nums(&old_span));
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
            ddog_attributes_add_list(ddog_event_get_attributes(event), cs("nums"), nums);
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
            ddog_attributes_add_str(ddog_span_get_attributes(s), cs("span.kind"), cs("process"));
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
    fn span_kind_str_internal_is_canonical_and_survives_v04_downgrade() {
        // An explicit "internal" is the Internal kind: serializer.c deletes the tag, and the
        // downgrade writes it back from the kind.
        let (b, s) = one_span_builder();
        unsafe {
            assert!(ddog_set_span_kind_str(s, cs("internal")));
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
    fn span_kind_unset_stays_unspecified() {
        let (b, _s) = one_span_builder();
        let payload = b.into_payload();
        assert_eq!(payload.chunks[0].spans[0].span_kind, SpanKind::Unspecified);
        let span = first_span(&to_vec_from_v1(&payload));
        assert!(field(&span, "meta")
            .and_then(|m| field(m, "span.kind"))
            .is_none());
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
    use datadog_sidecar_ffi::span::*;
    use libdd_trace_utils::span::v1::AttributeValueBytes;

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
            ddog_attributes_add_lit(ddog_span_get_attributes(root), c"moved".as_ptr(), cs("v"));

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
            ddog_attributes_add_bool(ddog_attr_map_get_attributes(nested), cs("flag"), true);
            let items = ddog_attr_list_new(2);
            ddog_attr_list_push_str(items, cs("a"));
            ddog_attr_list_push_map(items, nested);
            let root = ddog_attr_map_new(2);
            ddog_attributes_add_int(ddog_attr_map_get_attributes(root), cs("n"), 7);
            ddog_attributes_add_list(ddog_attr_map_get_attributes(root), cs("items"), items);
            ddog_attributes_add_map(ddog_span_get_attributes(span), cs("root"), root);
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
            ddog_attributes_add_str(ddog_link_get_attributes(l0), cs("k"), cs("v"));
            // Deep nested attr on l0: { tags: [ "a", { deep: 1 } ] } — fully built before l1.
            let deep = ddog_attr_map_new(1);
            ddog_attributes_add_int(ddog_attr_map_get_attributes(deep), cs("deep"), 1);
            let tags = ddog_attr_list_new(2);
            ddog_attr_list_push_str(tags, cs("a"));
            ddog_attr_list_push_map(tags, deep);
            ddog_attributes_add_list(ddog_link_get_attributes(l0), cs("tags"), tags);

            let l1 = ddog_new_link(span); // sibling push; l0 stays valid (own allocation)
            ddog_link_set_span_id(l1, 22);
            ddog_link_set_span_id(l0, 111); // still valid after the sibling push

            let e0 = ddog_new_event(span);
            ddog_event_set_name(e0, cs("evt"));
            ddog_attributes_add_int(ddog_event_get_attributes(e0), cs("n"), 5);
            // Deep nested attr on e0: { meta: { list: [ true ] } } — fully built before e1.
            let list = ddog_attr_list_new(1);
            ddog_attr_list_push_bool(list, true);
            let meta = ddog_attr_map_new(1);
            ddog_attributes_add_list(ddog_attr_map_get_attributes(meta), cs("list"), list);
            ddog_attributes_add_map(ddog_event_get_attributes(e0), cs("meta"), meta);

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
            ddog_attributes_add_str(ddog_span_get_attributes(span), cs("dup"), cs("first"));
            ddog_attributes_add_str(ddog_span_get_attributes(span), cs("dup"), cs("second"));
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
            ddog_attributes_add_int(ddog_attr_map_get_attributes(m), cs("y"), 1);
            ddog_attributes_add_map(ddog_span_get_attributes(s), cs("x"), m);
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
            ddog_attributes_add_list(ddog_attr_map_get_attributes(mid), cs("inner"), inner);
            ddog_attributes_add_map(ddog_attr_map_get_attributes(mid), cs("empty"), ddog_attr_map_new(cap(0)));
            let outer = ddog_attr_list_new(cap(2));
            ddog_attr_list_push_map(outer, mid);
            ddog_attr_list_push_str(outer, cs("tail"));
            ddog_attributes_add_list(ddog_span_get_attributes(span), cs(key), outer);
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

    // (f) Attribute handles stay valid across other calls on their node and sibling pushes, with no
    // refetch: the handle shares the node pointer's tag (no intermediate `&mut` in the getter).
    #[test]
    fn f_attribute_handles_survive_interleaved_node_calls() {
        let mut b = TracerPayloadV1Builder::default();
        let chunk = b.push_chunk(0, 1);
        unsafe {
            let span = ddog_new_span(chunk);
            let attrs = ddog_span_get_attributes(span);
            ddog_attributes_add_str(attrs, cs("a"), cs("1"));
            ddog_span_set_error(span, true);
            ddog_span_set_id(span, 7);
            let link = ddog_new_link(span);
            let link_attrs = ddog_link_get_attributes(link);
            let event = ddog_new_event(span);
            let event_attrs = ddog_event_get_attributes(event);
            ddog_attributes_add_int(attrs, cs("b"), 2);
            ddog_link_set_span_id(link, 3);
            ddog_attributes_add_str(link_attrs, cs("l"), cs("v"));
            let link2 = ddog_new_link(span); // sibling push
            ddog_event_set_name(event, cs("e"));
            ddog_attributes_add_bool(event_attrs, cs("e"), true);
            ddog_attributes_add_str(ddog_link_get_attributes(link2), cs("l2"), cs("v2"));
            ddog_attributes_add_double(attrs, cs("c"), 1.5);
            assert!(ddog_span_get_error(span));
            let second = ddog_new_span(chunk); // sibling span push
            ddog_attributes_add_str(ddog_span_get_attributes(second), cs("s"), cs("2"));
            let m = ddog_attr_map_new(0);
            let m_attrs = ddog_attr_map_get_attributes(m);
            ddog_attributes_add_int(m_attrs, cs("x"), 1);
            ddog_attributes_add_str(attrs, cs("d"), cs("4"));
            ddog_attributes_add_int(m_attrs, cs("y"), 2);
            ddog_attributes_add_map(attrs, cs("m"), m);
        }
        let payload = b.into_payload();
        let span = &payload.chunks[0].spans[0];
        assert!(span.error);
        assert_eq!(span.span_id, 7);
        assert_eq!(span.attributes.len(), 5);
        match span.attributes.get("m") {
            Some(AttributeValueBytes::KeyValue(m)) => assert_eq!(m.len(), 2),
            other => panic!("expected KeyValue, got {other:?}"),
        }
        assert_eq!(span.span_links.len(), 2);
        assert!(span.span_links[0].attributes.contains_key("l"));
        assert!(span.span_links[1].attributes.contains_key("l2"));
        assert!(span.span_events[0].attributes.contains_key("e"));
        assert!(payload.chunks[0].spans[1].attributes.contains_key("s"));
    }
}
