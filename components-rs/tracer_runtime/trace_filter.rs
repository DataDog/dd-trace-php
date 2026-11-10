// Copyright 2024-Present Datadog, Inc. https://www.datadoghq.com/
// SPDX-License-Identifier: Apache-2.0

//! Trace-level filter logic for client-side stats (filter_tags, filter_tags_regex,
//! ignore_resources as published by the agent's /info endpoint).
//!
//! The filter is a **full-pipeline gate**: when a trace fails, it is dropped from both
//! serialisation (no trace send) and stats computation.  Individual spans are never
//! filtered in isolation — the root span's tags are evaluated and the decision applies
//! to the entire trace.
//!
//! # Fast path
//! `TRACE_FILTER` holds `None` when no filters are configured (the overwhelmingly common
//! case).  `ddog_check_stats_trace_filter` returns `true` immediately in that case.

use libdd_common_ffi::slice::{AsBytes, CharSlice};
use libdd_trace_model::{Attributes, Span as ModelSpan, ValueTypes, PROMOTED_SPAN_TAGS};
use libdd_trace_utils::span::{v04, BytesData};
use libdd_trace_utils::trace_filter::TraceFilterer;
use std::ffi::{c_char, c_void};
use std::sync::{LazyLock, RwLock};

/// Compile all filter arguments into a `TraceFilterer`, or return `None` when all lists
/// are empty (no filtering needed).
pub(crate) fn compile_trace_filter(
    filter_tags_require: &[String],
    filter_tags_reject: &[String],
    filter_tags_regex_require: &[String],
    filter_tags_regex_reject: &[String],
    ignore_resources: &[String],
) -> Option<TraceFilterer> {
    if [
        filter_tags_require,
        filter_tags_reject,
        filter_tags_regex_require,
        filter_tags_regex_reject,
        ignore_resources,
    ]
    .iter()
    .all(|v| v.is_empty())
    {
        None
    } else {
        Some(TraceFilterer::new(
            filter_tags_require,
            filter_tags_reject,
            filter_tags_regex_require,
            filter_tags_regex_reject,
            ignore_resources,
        ))
    }
}

/// Currently active compiled trace filter.  `None` when no filters are configured.
static TRACE_FILTER: LazyLock<RwLock<Option<TraceFilterer>>> = LazyLock::new(|| RwLock::new(None));

/// Replace the active trace filter with a freshly compiled one.
///
/// Called from `apply_concentrator_config` in `stats.rs` whenever the agent /info SHM
/// is updated.
pub(crate) fn set_trace_filter(compiled: Option<TraceFilterer>) {
    *TRACE_FILTER.write().unwrap() = compiled;
}

/// Fast path: exact-key lookup into a root span.  Returns null when the key is absent.
pub type RootTagLookupFn = unsafe extern "C" fn(
    ctx: *const c_void,
    key: *const c_char,
    key_len: usize,
    out_len: *mut usize,
) -> *const c_char;

/// Borrowed projection of the fields consumed by `TraceFilterer`.
///
/// PHP retains its native span storage and performs lookups only for configured
/// filters. Identity, timing, events and links are absent from this projection;
/// it must not be reused as a general span adapter for serialization or stats.
struct FilterSpan<'a> {
    resource_str: &'a str,
    root_span: *const c_void,
    lookup_fn: RootTagLookupFn,
}

impl FilterSpan<'_> {
    fn lookup(&self, key: &str) -> Option<&str> {
        unsafe {
            let mut vlen: usize = 0;
            let vptr = (self.lookup_fn)(
                self.root_span,
                key.as_ptr() as *const c_char,
                key.len(),
                &mut vlen,
            );
            if vptr.is_null() {
                None
            } else {
                Some(std::str::from_utf8_unchecked(std::slice::from_raw_parts(
                    vptr.cast::<u8>(),
                    vlen,
                )))
            }
        }
    }
}

impl Attributes for FilterSpan<'_> {
    type Text = <BytesData as ValueTypes>::Text;
    type Bytes = <BytesData as ValueTypes>::Bytes;
    type Values = BytesData;

    fn attribute_str(&self, key: &str) -> Option<&str> {
        if PROMOTED_SPAN_TAGS.contains(&key) {
            None
        } else {
            self.lookup(key)
        }
    }

    fn attribute_f64(&self, _key: &str) -> Option<f64> {
        // The existing C filter callback exposes numeric tags as strings, too.
        // This view preserves those filter values; it is not a numeric stats view.
        None
    }
}

impl ModelSpan for FilterSpan<'_> {
    type SpanEvent = v04::SpanEvent<BytesData>;
    type SpanLink = v04::SpanLink<BytesData>;

    fn service(&self) -> &str {
        self.lookup("service").unwrap_or("")
    }

    fn resource(&self) -> &str {
        self.resource_str
    }

    fn name(&self) -> &str {
        self.lookup("name").unwrap_or("")
    }

    fn typ(&self) -> &str {
        self.lookup("type").unwrap_or("")
    }

    fn span_id(&self) -> u64 {
        0
    }

    fn parent_id(&self) -> u64 {
        0
    }

    fn start(&self) -> i64 {
        0
    }

    fn duration(&self) -> i64 {
        0
    }

    fn is_error(&self) -> bool {
        false
    }

    fn span_kind(&self) -> Option<&str> {
        self.lookup("span.kind")
    }

    fn env(&self) -> Option<&str> {
        self.lookup("env")
    }

    fn version(&self) -> Option<&str> {
        self.lookup("version")
    }

    fn component(&self) -> Option<&str> {
        self.lookup("component")
    }

    fn span_events(&self) -> impl Iterator<Item = &Self::SpanEvent> {
        std::iter::empty()
    }

    fn span_links(&self) -> impl Iterator<Item = &Self::SpanLink> {
        std::iter::empty()
    }
}

/// Check whether the trace rooted at `resource` / `root_span` passes all configured trace
/// filters (filter_tags, filter_tags_regex, ignore_resources from agent /info).
///
/// Returns `true` to include in the pipeline, `false` to drop the entire trace (no sending,
/// no stats).  Filters are evaluated against the root span — the decision applies uniformly
/// to all spans of the trace.
///
/// * **When configured**: `filter_tags` and `filter_tags_regex` entries — one
///   `lookup_fn` call per filter entry.
/// * **Fast path**: returns `true` immediately when no filters are configured.
#[no_mangle]
pub unsafe extern "C" fn ddog_check_stats_trace_filter(
    resource: CharSlice<'_>,
    root_span: *const c_void,
    lookup_fn: RootTagLookupFn,
) -> bool {
    let guard = TRACE_FILTER.read().unwrap();
    // Fast path: None means no filters configured (overwhelmingly common).
    let Some(f) = guard.as_ref() else {
        return true;
    };

    !f.should_drop(&FilterSpan {
        resource_str: resource.try_to_utf8().unwrap_or(""),
        root_span,
        lookup_fn,
    })
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::collections::HashMap;

    unsafe extern "C" fn lookup(
        ctx: *const c_void,
        key: *const c_char,
        key_len: usize,
        out_len: *mut usize,
    ) -> *const c_char {
        let tags = &*ctx.cast::<HashMap<&str, &str>>();
        let key = std::str::from_utf8(std::slice::from_raw_parts(key.cast(), key_len)).unwrap();
        match tags.get(key) {
            Some(value) => {
                *out_len = value.len();
                value.as_ptr().cast()
            }
            None => std::ptr::null(),
        }
    }

    fn span<'a>(resource: &'a str, tags: &'a HashMap<&str, &str>) -> FilterSpan<'a> {
        FilterSpan {
            resource_str: resource,
            root_span: std::ptr::from_ref(tags).cast(),
            lookup_fn: lookup,
        }
    }

    #[test]
    fn platform_filters_read_promoted_fields_and_normalize_env() {
        let mut tags = HashMap::from([
            ("env", "Production"),
            ("version", "1.2.3"),
            ("span.kind", "server"),
            ("component", "web"),
            ("http.method", "GET"),
        ]);
        let filter = TraceFilterer::new(
            &[
                "env:production".into(),
                "version:1.2.3".into(),
                "span.kind:server".into(),
                "component:web".into(),
            ],
            &[],
            &["http.method:G.*".into()],
            &[],
            &[],
        );
        let view = span("GET /orders", &tags);
        for key in PROMOTED_SPAN_TAGS {
            assert_eq!(view.attribute_str(key), None);
            assert_eq!(view.compat().attribute_str(key), tags.get(key).copied());
        }
        assert!(!filter.should_drop(&view));
        for key in PROMOTED_SPAN_TAGS {
            let original = tags.insert(key, "wrong").unwrap();
            assert!(filter.should_drop(&span("GET /orders", &tags)), "{key}");
            tags.insert(key, original);
        }
        tags.insert("http.method", "DELETE");
        assert!(filter.should_drop(&span("GET /orders", &tags)));
    }

    #[test]
    fn platform_filters_preserve_missing_empty_and_native_string_values() {
        let mut tags = HashMap::from([("version", ""), ("numeric.tag", "2")]);
        let filter = TraceFilterer::new(
            &["version".into(), "numeric.tag:2".into()],
            &["reject:yes".into()],
            &[],
            &["http.url:.*internal.*".into()],
            &[],
        );
        assert!(!filter.should_drop(&span("GET /orders", &tags)));
        tags.remove("version");
        assert!(filter.should_drop(&span("GET /orders", &tags)));
        tags.insert("version", "");
        tags.insert("reject", "yes");
        assert!(filter.should_drop(&span("GET /orders", &tags)));
        tags.remove("reject");
        tags.insert("http.url", "http://internal/orders");
        assert!(filter.should_drop(&span("GET /orders", &tags)));
    }

    #[test]
    fn platform_filters_use_name_when_resource_is_empty() {
        let tags = HashMap::from([("name", "GET /health")]);
        let filter = TraceFilterer::new(&[], &[], &[], &[], &["^GET /health$".into()]);
        assert!(filter.should_drop(&span("", &tags)));
        assert!(filter.should_drop(&span("GET /health", &tags)));
        assert!(!filter.should_drop(&span("GET /orders", &tags)));
    }
}
