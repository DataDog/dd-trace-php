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
use libdd_trace_utils::trace_filter::{Span as TraceFilterSpan, TraceFilterer};
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

impl<'a> TraceFilterSpan<'a> for Span<'a> {
    fn resource_normalized(&'a self) -> &'a str {
        // FIXME: normalization: if resource is empty, name should be used instead
        self.resource_str
    }

    fn get_meta(&'a self, key: &str) -> Option<&'a str> {
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
