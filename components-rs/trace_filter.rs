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
use regex::Regex;
use std::ffi::{c_char, c_void};
use std::sync::{LazyLock, RwLock};
use tracing::info;

/// An exact-match tag filter: `"key"` (key-presence only) or `"key:value"`.
struct TagFilter {
    key: String,
    value: Option<String>,
}

/// A `filter_tags_regex` entry whose key is a plain literal — direct O(1) lookup.
struct TagRegexFilter {
    key: String,
    value: Option<Regex>,
}

/// A `filter_tags_regex` entry whose key is itself a regex pattern — requires meta iteration.
struct TagRegexKeyFilter {
    key_re: Regex,
    value: Option<Regex>,
}

/// Compiled trace filter rules, ready for fast evaluation.
pub(crate) struct TraceFilterConfig {
    filter_tags_require: Vec<TagFilter>,
    filter_tags_reject: Vec<TagFilter>,
    /// Literal-key regex filters: O(1) lookup per entry via the C lookup callback.
    filter_tags_regex_require: Vec<TagRegexFilter>,
    filter_tags_regex_reject: Vec<TagRegexFilter>,
    /// Regex-key filters (rare): require meta iteration via the C iterator callback.
    filter_tags_regex_key_require: Vec<TagRegexKeyFilter>,
    filter_tags_regex_key_reject: Vec<TagRegexKeyFilter>,
    ignore_resources: Vec<Regex>,
}

fn parse_tag_filter(s: &str) -> TagFilter {
    match s.find(':') {
        Some(i) => TagFilter { key: s[..i].to_owned(), value: Some(s[i + 1..].to_owned()) },
        None => TagFilter { key: s.to_owned(), value: None },
    }
}

/// Compile a regex anchored to the full string.
fn compile_anchored(pattern: &str) -> Option<Regex> {
    Regex::new(&format!("^(?:{pattern})$")).ok()
}

/// Returns `true` when `key` contains no regex metacharacters and can be used for a direct
/// O(1) lookup.  `.` is intentionally treated as a literal (not a wildcard) in key patterns.
fn is_literal_key(key: &str) -> bool {
    !key.contains(|c: char| matches!(c, '*' | '+' | '?' | '[' | ']' | '(' | ')' | '{' | '}' | '^' | '$' | '|' | '\\'))
}

/// Compile all `filter_tags_regex` entries, splitting into literal-key (fast) and
/// regex-key (slow) lists based on whether the key portion contains metacharacters.
fn compile_regex_filters(entries: &[String]) -> (Vec<TagRegexFilter>, Vec<TagRegexKeyFilter>) {
    let mut literal = Vec::new();
    let mut regex_key = Vec::new();
    for s in entries {
        let (key_str, value_str) = match s.find(':') {
            Some(i) => (&s[..i], Some(&s[i + 1..])),
            None => (s.as_str(), None),
        };
        let value = value_str.and_then(|v| compile_anchored(v));
        if is_literal_key(key_str) {
            literal.push(TagRegexFilter { key: key_str.to_owned(), value });
        } else if let Some(key_re) = compile_anchored(key_str) {
            regex_key.push(TagRegexKeyFilter { key_re, value });
        } else {
            info!("'{key_str}' regex tag filter is not a valid regex");
        }
    }
    (literal, regex_key)
}

/// Compile all filter arguments into a `TraceFilterConfig`, or return `None` when all lists
/// are empty (no filtering needed).
pub(crate) fn compile_trace_filter(
    tags_require: &[String],
    tags_reject: &[String],
    regex_require: &[String],
    regex_reject: &[String],
    ignore_resources: &[String],
) -> Option<TraceFilterConfig> {
    if tags_require.is_empty()
        && tags_reject.is_empty()
        && regex_require.is_empty()
        && regex_reject.is_empty()
        && ignore_resources.is_empty()
    {
        return None;
    }
    let (regex_require_literal, regex_require_key) = compile_regex_filters(regex_require);
    let (regex_reject_literal, regex_reject_key) = compile_regex_filters(regex_reject);
    Some(TraceFilterConfig {
        filter_tags_require: tags_require.iter().map(|s| parse_tag_filter(s)).collect(),
        filter_tags_reject: tags_reject.iter().map(|s| parse_tag_filter(s)).collect(),
        filter_tags_regex_require: regex_require_literal,
        filter_tags_regex_reject: regex_reject_literal,
        filter_tags_regex_key_require: regex_require_key,
        filter_tags_regex_key_reject: regex_reject_key,
        ignore_resources: ignore_resources.iter().filter_map(|s| compile_anchored(s)).collect(),
    })
}

/// Currently active compiled trace filter.  `None` when no filters are configured.
static TRACE_FILTER: LazyLock<RwLock<Option<TraceFilterConfig>>> =
    LazyLock::new(|| RwLock::new(None));

/// Replace the active trace filter with a freshly compiled one.
///
/// Called from `apply_concentrator_config` in `stats.rs` whenever the agent /info SHM
/// is updated.
pub(crate) fn set_trace_filter(compiled: Option<TraceFilterConfig>) {
    *TRACE_FILTER.write().unwrap() = compiled;
}

/// Fast path: exact-key lookup into a root span.  Returns null when the key is absent.
pub type RootTagLookupFn = unsafe extern "C" fn(
    ctx: *const c_void,
    key: *const c_char,
    key_len: usize,
    out_len: *mut usize,
) -> *const c_char;

/// Per-entry callback passed to `RootMetaIterFn`.  Return `false` to stop iteration early.
pub type MetaEntryCb = unsafe extern "C" fn(
    iter_ctx: *mut c_void,
    key: *const c_char,
    key_len: usize,
    val: *const c_char,
    val_len: usize,
) -> bool;

/// Slow-path meta iterator.  `NULL` when no regex-key filter entries are present.
/// Iterates all string meta entries, calling `cb` for each; stops when `cb` returns `false`.
pub type RootMetaIterFn = Option<
    unsafe extern "C" fn(ctx: *const c_void, iter_ctx: *mut c_void, cb: MetaEntryCb),
>;

#[inline]
unsafe fn lookup_tag<'a>(
    lookup: RootTagLookupFn,
    ctx: *const c_void,
    key: &str,
) -> Option<&'a str> {
    let mut vlen: usize = 0;
    let vptr = lookup(ctx, key.as_ptr() as *const c_char, key.len(), &mut vlen);
    if vptr.is_null() {
        None
    } else {
        Some(std::str::from_utf8_unchecked(std::slice::from_raw_parts(
            vptr as *const u8,
            vlen,
        )))
    }
}

/// State threaded through the opaque `iter_ctx` pointer during regex-key meta scans.
struct IterState<'a> {
    key_re: &'a Regex,
    value: &'a Option<Regex>,
    found: bool,
}

/// Trampoline called by C for each meta entry.  Sets `found = true` and stops on a match.
unsafe extern "C" fn meta_entry_cb(
    iter_ctx: *mut c_void,
    key: *const c_char,
    klen: usize,
    val: *const c_char,
    vlen: usize,
) -> bool {
    let state = &mut *(iter_ctx as *mut IterState);
    let k = std::str::from_utf8_unchecked(std::slice::from_raw_parts(key as *const u8, klen));
    if state.key_re.is_match(k) {
        let v = std::str::from_utf8_unchecked(std::slice::from_raw_parts(val as *const u8, vlen));
        if state.value.as_ref().map_or(true, |re| re.is_match(v)) {
            state.found = true;
            return false; // stop iteration
        }
    }
    true // continue
}

/// Use the iterator to check whether any meta entry matches a `TagRegexKeyFilter`.
#[inline]
unsafe fn regex_key_matches(
    iter: unsafe extern "C" fn(*const c_void, *mut c_void, MetaEntryCb),
    ctx: *const c_void,
    filter: &TagRegexKeyFilter,
) -> bool {
    let mut state = IterState { key_re: &filter.key_re, value: &filter.value, found: false };
    iter(ctx, &mut state as *mut IterState as *mut c_void, meta_entry_cb);
    state.found
}

/// Check whether the trace rooted at `resource` / `root_span` passes all configured trace
/// filters (filter_tags, filter_tags_regex, ignore_resources from agent /info).
///
/// Returns `true` to include in the pipeline, `false` to drop the entire trace (no sending,
/// no stats).  Filters are evaluated against the root span — the decision applies uniformly
/// to all spans of the trace.
///
/// * **Common case**: `filter_tags` and literal-key `filter_tags_regex` entries — one O(1)
///   `lookup_fn` call per filter entry.
/// * **Rare case**: `filter_tags_regex` entries with regex key patterns — `iter_fn` is invoked
///   to scan all meta entries for those filters.  Pass `NULL` when not needed.
/// * **Fast path**: returns `true` immediately when no filters are configured.
#[no_mangle]
pub unsafe extern "C" fn ddog_check_stats_trace_filter(
    resource: CharSlice<'_>,
    root_span: *const c_void,
    lookup_fn: RootTagLookupFn,
    iter_fn: RootMetaIterFn,
) -> bool {
    let guard = TRACE_FILTER.read().unwrap();
    // Fast path: None means no filters configured (overwhelmingly common).
    let Some(f) = guard.as_ref() else {
        return true;
    };

    let resource_str = resource.try_to_utf8().unwrap_or("");

    // 1. ignore_resources: reject if root resource matches any pattern.
    for re in &f.ignore_resources {
        if re.is_match(resource_str) {
            return false;
        }
    }
    // 2. filter_tags.reject: reject if the root span has a matching tag.
    for filter in &f.filter_tags_reject {
        if let Some(val) = lookup_tag(lookup_fn, root_span, &filter.key) {
            if filter.value.as_deref().map_or(true, |v| v == val) {
                return false;
            }
        }
    }
    // 3a. filter_tags_regex.reject (literal key): reject if value matches.
    for filter in &f.filter_tags_regex_reject {
        if let Some(val) = lookup_tag(lookup_fn, root_span, &filter.key) {
            if filter.value.as_ref().map_or(true, |re| re.is_match(val)) {
                return false;
            }
        }
    }
    // 3b. filter_tags_regex.reject (regex key): slow path — iterate all meta.
    if let Some(iter) = iter_fn {
        for filter in &f.filter_tags_regex_key_reject {
            if regex_key_matches(iter, root_span, filter) {
                return false;
            }
        }
    }
    // 4. filter_tags.require: reject unless every required tag is present and matches.
    for filter in &f.filter_tags_require {
        match lookup_tag(lookup_fn, root_span, &filter.key) {
            None => return false,
            Some(val) => {
                if filter.value.as_deref().is_some_and(|v| v != val) {
                    return false;
                }
            }
        }
    }
    // 5a. filter_tags_regex.require (literal key): reject unless matched.
    for filter in &f.filter_tags_regex_require {
        match lookup_tag(lookup_fn, root_span, &filter.key) {
            None => return false,
            Some(val) => {
                if filter.value.as_ref().is_some_and(|re| !re.is_match(val)) {
                    return false;
                }
            }
        }
    }
    // 5b. filter_tags_regex.require (regex key): slow path — every required pattern must match.
    if let Some(iter) = iter_fn {
        for filter in &f.filter_tags_regex_key_require {
            if !regex_key_matches(iter, root_span, filter) {
                return false;
            }
        }
    }

    true
}
