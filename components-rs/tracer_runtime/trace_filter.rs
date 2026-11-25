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
use libdd_trace_model::{
    Attributes, Span, SpanEvent, SpanLink, StringData, COMPONENT_KEY, ENV_KEY, SPAN_KIND_KEY,
    VERSION_KEY,
};
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

/// Read-only view of the PHP root span, backed by `lookup_fn`.
///
/// Only what `TraceFilterer::should_drop` reads is wired to the C side: the resource, the
/// operation name and the tags (attributes and the promoted `env`, `version`, `span.kind` and
/// `component` tags, which `should_drop` reads through `CompatSpan`). The remaining span fields
/// are not used by the trace filter and read as unset.
struct RootSpan<'a> {
    resource_str: &'a str,
    root_span: *const c_void,
    lookup_fn: RootTagLookupFn,
}

impl RootSpan<'_> {
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

impl Attributes for RootSpan<'_> {
    type Text = String;
    type Bytes = Vec<u8>;
    type Values = StringData;

    fn attribute_str(&self, key: &str) -> Option<&str> {
        self.lookup(key)
    }

    fn attribute_f64(&self, _key: &str) -> Option<f64> {
        // The C lookup only exposes tags as strings (metrics are stringified); the trace filter
        // only reads string values.
        None
    }
}

impl Span for RootSpan<'_> {
    type SpanEvent = NoEventOrLink;
    type SpanLink = NoEventOrLink;

    fn service(&self) -> &str {
        self.lookup("service").unwrap_or("")
    }

    fn resource(&self) -> &str {
        // Resource has been normalized on the C side before calling ddog_check_stats_trace_filter
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
        // This is always the trace root.
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
        self.lookup(SPAN_KIND_KEY)
    }

    fn env(&self) -> Option<&str> {
        self.lookup(ENV_KEY)
    }

    fn version(&self) -> Option<&str> {
        self.lookup(VERSION_KEY)
    }

    fn component(&self) -> Option<&str> {
        self.lookup(COMPONENT_KEY)
    }

    fn span_events(&self) -> impl Iterator<Item = &Self::SpanEvent> {
        std::iter::empty()
    }

    fn span_links(&self) -> impl Iterator<Item = &Self::SpanLink> {
        std::iter::empty()
    }
}

/// Uninhabited span event / link type: the trace filter never reads events or links.
enum NoEventOrLink {}

impl Attributes for NoEventOrLink {
    type Text = String;
    type Bytes = Vec<u8>;
    type Values = StringData;

    fn attribute_str(&self, _key: &str) -> Option<&str> {
        match *self {}
    }

    fn attribute_f64(&self, _key: &str) -> Option<f64> {
        match *self {}
    }
}

impl SpanEvent for NoEventOrLink {
    fn name(&self) -> &str {
        match *self {}
    }

    fn time_unix_nano(&self) -> u64 {
        match *self {}
    }
}

impl SpanLink for NoEventOrLink {
    fn trace_id(&self) -> u128 {
        match *self {}
    }

    fn span_id(&self) -> u64 {
        match *self {}
    }

    fn tracestate(&self) -> &str {
        match *self {}
    }

    fn flags(&self) -> u32 {
        match *self {}
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

    !f.should_drop(&RootSpan {
        resource_str: resource.try_to_utf8().unwrap_or(""),
        root_span,
        lookup_fn,
    })
}
