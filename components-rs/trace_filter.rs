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
use libdd_trace_model::{Attributes, Span as TraceFilterSpan, StringData};
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

struct Span<'a> {
    resource_str: &'a str,
    root_span: *const c_void,
    lookup_fn: RootTagLookupFn,
}

impl Attributes for Span<'_> {
    type Text = String;
    type Bytes = Vec<u8>;
    type Values = StringData;

    fn attribute_f64(&self, _key: &str) -> Option<f64> {
        None
    }

    fn attribute_str(&self, key: &str) -> Option<&str> {
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
                    vptr as *const u8,
                    vlen,
                )))
            }
        }
    }
}

// This view is used only by TraceFilterer. The C caller supplies a normalized
// resource and string-tag lookup; unrelated span fields have no data in this view.
impl TraceFilterSpan for Span<'_> {
    type SpanEvent = libdd_trace_protobuf::pb::SpanEvent;
    type SpanLink = libdd_trace_protobuf::pb::SpanLink;

    fn resource(&self) -> &str {
        self.resource_str
    }

    fn service(&self) -> &str {
        ""
    }
    fn name(&self) -> &str {
        ""
    }
    fn typ(&self) -> &str {
        ""
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
        self.attribute_str(libdd_trace_model::SPAN_KIND_KEY)
    }

    fn env(&self) -> Option<&str> {
        self.attribute_str(libdd_trace_model::ENV_KEY)
    }

    fn version(&self) -> Option<&str> {
        self.attribute_str(libdd_trace_model::VERSION_KEY)
    }

    fn component(&self) -> Option<&str> {
        self.attribute_str(libdd_trace_model::COMPONENT_KEY)
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

    !f.should_drop(&Span {
        resource_str: resource.try_to_utf8().unwrap_or(""),
        root_span,
        lookup_fn,
    })
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::collections::HashMap;

    unsafe extern "C" fn lookup_tag(
        context: *const c_void,
        key: *const c_char,
        key_len: usize,
        value_len: *mut usize,
    ) -> *const c_char {
        // The synchronous test call keeps the immutable map and its strings alive.
        let tags = unsafe { &*context.cast::<HashMap<&str, &str>>() };
        let key = unsafe { std::slice::from_raw_parts(key.cast::<u8>(), key_len) };
        let Some(value) = tags.get(std::str::from_utf8(key).unwrap()) else {
            return std::ptr::null();
        };
        unsafe { *value_len = value.len() };
        value.as_ptr().cast()
    }

    #[test]
    fn callback_view_preserves_resource_tag_and_promoted_tag_filters() {
        let filter = compile_trace_filter(
            &[
                "env:prod".into(),
                "component:web".into(),
                "version:1".into(),
                "span.kind:server".into(),
            ],
            &["blocked:yes".into()],
            &["http.method:^GET$".into()],
            &["http.route:^/private".into()],
            &["^GET /health$".into()],
        )
        .unwrap();
        let base = HashMap::from([
            ("env", "PROD"),
            ("component", "web"),
            ("version", "1"),
            ("span.kind", "server"),
            ("http.method", "GET"),
        ]);
        let dropped = |resource, tags: &HashMap<&str, &str>| {
            filter.should_drop(&Span {
                resource_str: resource,
                root_span: std::ptr::from_ref(tags).cast(),
                lookup_fn: lookup_tag,
            })
        };
        assert!(!dropped("GET /api", &base));
        assert!(!dropped("", &base));
        assert!(dropped("GET /health", &base));
        for (key, value) in [
            ("blocked", "yes"),
            ("http.method", "POST"),
            ("http.route", "/private/data"),
            ("env", "staging"),
        ] {
            let mut tags = base.clone();
            tags.insert(key, value);
            assert!(dropped("GET /api", &tags), "{key}={value}");
        }
        let mut tags = base;
        tags.remove("component");
        assert!(dropped("GET /api", &tags));
    }
}
