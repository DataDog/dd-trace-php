// Copyright 2024-Present Datadog, Inc. https://www.datadoghq.com/
// SPDX-License-Identifier: Apache-2.0

//! FFI wrapper for the local SpanConcentrator used to compute span stats on the PHP tracer side.
//!
//! The C extraction side fills a `PhpSpanStats` struct (one call per span), then passes it to
//! `ddog_span_concentrator_add_php_span`. All string slices borrow from PHP memory and are only
//! valid for the duration of that call.

use crate::trace_filter;
use datadog_ipc::shm_stats::{OwnedShmSpanInput, ShmSpanConcentrator, ShmSpanInput, MAX_PEER_TAGS};
use datadog_sidecar::service::blocking::{add_span_to_concentrator, SidecarTransport};
use libdd_trace_protobuf::pb::Trilean;
use libdd_trace_stats::span_concentrator::FixedAggregationKey;
use libdd_common_ffi::slice::{AsBytes, CharSlice};
use std::collections::HashMap;
use std::ffi::{c_char, c_void};
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::{LazyLock, RwLock};
use tracing::trace;

/// Number of gRPC status-code keys checked by the stats aggregation (must match
/// `GRPC_STATUS_CODE_FIELD` in libdd-trace-stats/src/span_concentrator/aggregation.rs).
pub const PHP_GRPC_KEY_COUNT: usize = 4;

/// A (key, value) pair for peer-service tags, borrowed from PHP/concentrator memory.
#[repr(C)]
#[derive(Copy, Clone)]
pub struct PhpPeerTag<'a> {
    /// Key string — borrows from the concentrator's `peer_tag_keys` Vec<String>.
    pub key: CharSlice<'a>,
    /// Value string — borrows from PHP span meta memory.
    pub value: CharSlice<'a>,
}

/// Flat representation of a PHP span's stats-relevant fields, filled by C code in one call.
///
/// All `CharSlice` fields borrow from PHP memory (or from the concentrator for peer-tag keys) and
/// must remain valid for the duration of `ddog_span_concentrator_add_php_span`.
///
/// For absent optional strings pass an empty slice (ptr may be non-null with len == 0).
/// For absent optional `f64` values pass `f64::NAN`.
#[repr(C)]
pub struct PhpSpanStats<'a> {
    pub service: CharSlice<'a>,
    pub resource: CharSlice<'a>,
    pub name: CharSlice<'a>,
    pub r#type: CharSlice<'a>,

    pub start: i64,
    pub duration: i64,

    pub is_error: bool,
    pub is_trace_root: bool,
    pub is_measured: bool,
    pub has_top_level: bool,
    pub is_partial_snapshot: bool,

    pub span_kind: CharSlice<'a>,
    pub http_status_code: CharSlice<'a>,
    pub http_method: CharSlice<'a>,
    pub http_endpoint: CharSlice<'a>,
    pub http_route: CharSlice<'a>,
    pub origin: CharSlice<'a>,
    /// Value of the `_dd.svc_src` meta tag; empty slice when absent.
    pub service_source: CharSlice<'a>,


    /// gRPC meta values in order: rpc.grpc.status_code, grpc.code, rpc.grpc.status.code,
    /// grpc.status.code.  Empty slice = absent.
    pub grpc_meta: [CharSlice<'a>; PHP_GRPC_KEY_COUNT],
    /// Same gRPC keys but from metrics (NaN = absent).
    pub grpc_metrics: [f64; PHP_GRPC_KEY_COUNT],

    /// Number of (key,value) pairs in `peer_tags`.
    pub peer_tags_count: usize,
    /// Pointer to an array of `peer_tags_count` `PhpPeerTag` pairs.
    /// May be null when `peer_tags_count == 0`.
    pub peer_tags: *const PhpPeerTag<'a>,
}

#[inline]
fn char_slice_str(s: CharSlice<'_>) -> &str {
    s.try_to_utf8().unwrap_or("")
}

/// Prefer string meta, fall back to metrics f64, default 0.
#[inline]
fn extract_http_status_code(span: &PhpSpanStats<'_>) -> u32 {
    let s = char_slice_str(span.http_status_code);
    if !s.is_empty() {
        s.parse::<u32>().unwrap_or(0)
    } else {
        0
    }
}

/// First non-absent value across gRPC meta keys then gRPC metric keys.
#[inline]
fn extract_grpc_status_code(span: &PhpSpanStats<'_>) -> Option<u8> {
    span.grpc_meta
        .iter()
        .find_map(|m| {
            let s = char_slice_str(*m);
            if s.is_empty() { None } else { s.parse::<u8>().ok() }
        })
        .or_else(|| {
            span.grpc_metrics
                .iter()
                .find_map(|&v| if v.is_nan() { None } else { Some(v as u8) })
        })
}

/// Prefer http.endpoint, fall back to http.route.
#[inline]
fn extract_http_endpoint<'a>(span: &'a PhpSpanStats<'a>) -> &'a str {
    let ep = char_slice_str(span.http_endpoint);
    if !ep.is_empty() { ep } else { char_slice_str(span.http_route) }
}

/// Decode the raw peer-tags pointer into a slice.
///
/// # Safety
/// `span.peer_tags` must be valid for `span.peer_tags_count` elements when non-null.
#[inline]
unsafe fn peer_tags_slice<'a>(span: &'a PhpSpanStats<'a>) -> &'a [PhpPeerTag<'a>] {
    if span.peer_tags_count > 0 && !span.peer_tags.is_null() {
        std::slice::from_raw_parts(span.peer_tags, span.peer_tags_count)
    } else {
        &[]
    }
}

#[inline]
fn is_synthetics_request(span: &PhpSpanStats<'_>) -> bool {
    char_slice_str(span.origin).starts_with("synthetics")
}

/// Build the `FixedAggregationKey` from borrowed `PhpSpanStats` fields.
#[inline]
fn build_fixed_key<'a>(span: &'a PhpSpanStats<'a>) -> FixedAggregationKey<&'a str> {
    FixedAggregationKey {
        service_name: char_slice_str(span.service),
        resource_name: char_slice_str(span.resource),
        operation_name: char_slice_str(span.name),
        span_type: char_slice_str(span.r#type),
        span_kind: char_slice_str(span.span_kind),
        http_method: char_slice_str(span.http_method),
        http_endpoint: extract_http_endpoint(span),
        http_status_code: extract_http_status_code(span),
        is_synthetics_request: is_synthetics_request(span),
        is_trace_root: if span.is_trace_root {
            Trilean::True
        } else {
            Trilean::False
        },
        grpc_status_code: extract_grpc_status_code(span),
        service_source: char_slice_str(span.service_source),
    }
}

/// Extract a `ShmSpanInput` from a `PhpSpanStats`.
///
/// `peer_tag_buf` must be a caller-allocated buffer of at least `MAX_PEER_TAGS` entries; it is
/// filled in-place so that `ShmSpanInput::peer_tags` can borrow from it.
#[inline]
fn php_span_to_shm_input<'a>(
    span: &'a PhpSpanStats<'a>,
    peer_tag_buf: &'a mut [(&'a str, &'a str); MAX_PEER_TAGS],
) -> ShmSpanInput<'a> {
    let mut peer_tag_count = 0usize;
    // Safety: caller guarantees PhpSpanStats validity (see ddog_span_concentrator_add_php_span).
    for pt in unsafe { peer_tags_slice(span) }.iter().take(MAX_PEER_TAGS) {
        peer_tag_buf[peer_tag_count] = (char_slice_str(pt.key), char_slice_str(pt.value));
        peer_tag_count += 1;
    }

    ShmSpanInput {
        fixed: build_fixed_key(span),
        peer_tags: &peer_tag_buf[..peer_tag_count],
        duration_ns: span.duration,
        is_error: span.is_error,
        is_top_level: span.has_top_level,
    }
}

/// Opaque shared-memory span stats concentrator exposed to C.
///
/// Always heap-allocated (as a `Box`) — C holds a raw pointer and must pass it back to
/// `ddog_span_concentrator_drop` to free.
///
/// When `inner` is `None` this is a *virtual* concentrator: the SHM has not been created by the
/// sidecar yet, but peer-tag keys and span-kinds from `DESIRED_CONFIG` are still available so the
/// C callback can run eligibility checks and extract peer tags.  A virtual concentrator is always
/// considered stale (`needs_refresh` returns `true`) so it will be upgraded to a real one on the
/// next call once the SHM becomes available.
pub struct SpanConcentrator {
    /// `Some` when the backing SHM is open; `None` for virtual concentrators.
    inner: Option<ShmSpanConcentrator>,
    /// Whether the backing SHM is available.  False for virtual concentrators.
    pub has_shm: bool,
    peer_tag_keys: Vec<String>,
    /// Contiguous array of `CharSlice<'static>` views into `peer_tag_keys`.
    /// Rebuilt whenever `set_peer_tags` is called so that C can get a stable pointer.
    peer_tag_key_slices: Vec<CharSlice<'static>>,
    span_kinds: Vec<String>,
}

// SAFETY: `peer_tag_key_slices` borrows from the owned `peer_tag_keys` Vec<String> that lives
// alongside it in the same struct, which is always protected by the global RwLock.
unsafe impl Send for SpanConcentrator {}
unsafe impl Sync for SpanConcentrator {}

impl SpanConcentrator {
    fn rebuild_key_slices(&mut self) {
        self.peer_tag_key_slices = self
            .peer_tag_keys
            .iter()
            .map(|s| unsafe { CharSlice::from_raw_parts(s.as_ptr() as *const c_char, s.len()) })
            .collect();
    }

    /// Returns `true` when the entry should be replaced.
    /// Virtual concentrators (no SHM) always request a refresh so the caller can upgrade them
    /// to real concentrators once the SHM becomes available.
    fn needs_refresh(&self) -> bool {
        match &self.inner {
            Some(shm) => shm.needs_reload(),
            None => true,
        }
    }
}

static SPAN_CONCENTRATORS: LazyLock<RwLock<HashMap<String, SpanConcentrator>>> = LazyLock::new(|| RwLock::default());

/// Set to true once `apply_concentrator_config` has been called at least once,
/// i.e. the sidecar has received and applied the agent's /info response.
static AGENT_INFO_RECEIVED: AtomicBool = AtomicBool::new(false);

/// Set to true once agent info confirms `client_drop_p0s` is true and the
/// reported agent version is >= 7.65.0.  Cached so the per-span hot-path is a
/// single atomic load.
static STATS_COMPUTATION_READY: AtomicBool = AtomicBool::new(false);

fn agent_version_ge(version: Option<&str>, req_major: u32, req_minor: u32, req_patch: u32) -> bool {
    let Some(v) = version else { return false; };
    let v = v.split('-').next().unwrap_or(v); // strip agent version suffixes
    let mut parts = v.split('.').filter_map(|p| p.parse::<u32>().ok());
    let (Some(major), Some(minor), Some(patch)) = (parts.next(), parts.next(), parts.next()) else { return false; };
    (major, minor, patch) >= (req_major, req_minor, req_patch)
}

/// Returns true once the agent /info has been received and applied.
/// Used by the PHP extension to skip stats computation until the concentrator
/// has been properly initialised with peer-tag keys and span kinds.
#[no_mangle]
pub extern "C" fn ddog_is_agent_info_ready() -> bool {
    AGENT_INFO_RECEIVED.load(Ordering::Acquire)
}

/// Returns true when the agent supports client-side stats computation:
/// agent info has been received, `client_drop_p0s` is true, and the reported
/// agent version is >= 7.65.0.
#[no_mangle]
pub extern "C" fn ddog_agent_has_stats_computation() -> bool {
    STATS_COMPUTATION_READY.load(Ordering::Acquire)
}

/// Desired concentrator configuration sourced from the agent's /info endpoint.
/// Populated via `ddog_apply_agent_info`; applied to every concentrator
/// at creation time and when the config changes.
#[derive(Default)]
struct DesiredConfig {
    peer_tag_keys: Vec<String>,
    span_kinds: Vec<String>,
}

static DESIRED_CONFIG: LazyLock<RwLock<DesiredConfig>> = LazyLock::new(|| RwLock::default());

/// Apply updated concentrator config (peer-tag keys, span kinds, trace filters) to
/// `DESIRED_CONFIG` and propagate peer-tag / span-kind changes to all open concentrators.
/// Also updates the `STATS_COMPUTATION_READY` flag based on `client_drop_p0s` and `version`.
pub(crate) fn apply_concentrator_config(
    peer_tag_keys: Vec<String>,
    span_kinds: Vec<String>,
    tags_require: Vec<String>,
    tags_reject: Vec<String>,
    regex_require: Vec<String>,
    regex_reject: Vec<String>,
    ignore_resources: Vec<String>,
    client_drop_p0s: bool,
    version: Option<&str>,
) {
    let compiled = trace_filter::compile_trace_filter(
        &tags_require, &tags_reject, &regex_require, &regex_reject, &ignore_resources,
    );
    trace_filter::set_trace_filter(compiled);
    AGENT_INFO_RECEIVED.store(true, Ordering::Release);
    let ready = client_drop_p0s && agent_version_ge(version, 7, 65, 0);
    STATS_COMPUTATION_READY.store(ready, Ordering::Release);
    {
        let mut dc = DESIRED_CONFIG.write().unwrap();
        dc.peer_tag_keys = peer_tag_keys.clone();
        dc.span_kinds = span_kinds.clone();
    }
    let mut wg = SPAN_CONCENTRATORS.write().unwrap();
    for c in wg.values_mut() {
        c.peer_tag_keys = peer_tag_keys.clone();
        c.span_kinds = span_kinds.clone();
        c.rebuild_key_slices();
    }
}

/// Look up (or lazily create) the concentrator for `(env, version, service)` and invoke
/// `callback` with a shared reference to it while holding the global read lock.
///
/// The callback is **always** invoked — even before the sidecar has created the backing SHM.
/// When the SHM is not yet available a *virtual* concentrator is used: peer-tag keys and
/// span-kinds come from `DESIRED_CONFIG` so eligibility and peer-tag extraction still work
/// correctly.  The C callback should call `ddog_span_concentrator_has_shm` to decide whether to
/// write to the SHM (real concentrator) or store the stats for the IPC path (virtual).
///
/// A virtual concentrator is always considered stale so it will be transparently upgraded to a
/// real one on the next call once the sidecar has created the SHM.
///
/// Returns `true` after the callback returns, `false` only on an internal locking error.
///
/// # Safety
/// `env`, `version`, and `service` must be valid `CharSlice`s.  `callback` must be a valid
/// function pointer. `userdata` is forwarded to `callback` as-is.
#[no_mangle]
pub unsafe extern "C" fn ddog_span_concentrator_with(
    env: CharSlice<'_>,
    version: CharSlice<'_>,
    service: CharSlice<'_>,
    callback: unsafe extern "C" fn(*const SpanConcentrator, *mut c_void),
    userdata: *mut c_void,
) -> bool {
    let env_key = char_slice_str(env).to_owned();
    let version_key = char_slice_str(version).to_owned();
    let service_key = char_slice_str(service).to_owned();
    let map_key = format!("{env_key}\0{version_key}\0{service_key}");
    let map = &SPAN_CONCENTRATORS;

    // Fast path: read lock — entry present and up-to-date (real or virtual).
    {
        let guard = map.read().unwrap();
        if let Some(c) = guard.get(&map_key) {
            if !c.needs_refresh() {
                callback(c as *const SpanConcentrator, userdata);
                return true;
            }
        }
    }

    // Slow path: need to create or refresh — acquire write lock.
    {
        let mut wg = map.write().unwrap();
        let refresh = wg.get(&map_key).map_or(true, |c| c.needs_refresh());
        if refresh {
            wg.remove(&map_key);
            let path = datadog_sidecar::service::stats_flusher::env_stats_shm_path(&env_key, &version_key, &service_key);
            let (shm, has_shm) = match ShmSpanConcentrator::open(path.as_c_str()) {
                Ok(s) => (Some(s), true),
                Err(e) => {
                    trace!("SHM for env={env_key} version={version_key} service={service_key} not yet available ({e}); using virtual concentrator");
                    (None, false)
                }
            };
            let (peer_tag_keys, span_kinds) = {
                let dc = DESIRED_CONFIG.read().unwrap();
                (dc.peer_tag_keys.clone(), dc.span_kinds.clone())
            };
            let mut c = SpanConcentrator {
                inner: shm,
                has_shm,
                peer_tag_keys,
                peer_tag_key_slices: vec![],
                span_kinds,
            };
            c.rebuild_key_slices();
            wg.insert(map_key.clone(), c);
        }
    } // write lock dropped

    // Re-acquire read lock after write.
    let guard = map.read().unwrap();
    match guard.get(&map_key) {
        Some(c) => {
            callback(c as *const SpanConcentrator, userdata);
            true
        }
        None => false,
    }
}

/// Returns `true` when the concentrator is backed by a real SHM and
/// `ddog_span_concentrator_add_php_span` will actually persist data.
/// Returns `false` for virtual concentrators (SHM not yet available) — the C callback should
/// store the stats for the IPC fallback path in that case.
#[no_mangle]
pub extern "C" fn ddog_span_concentrator_has_shm(c: &SpanConcentrator) -> bool {
    c.has_shm
}

/// Return a pointer to the concentrator's peer-tag-key array and write the count to `*out_count`.
///
/// The returned pointer is valid for the lifetime of the guard passed to this call.
/// May return null when there are no peer tag keys.
#[no_mangle]
pub extern "C" fn ddog_span_concentrator_peer_tag_keys<'a>(
    c: &'a SpanConcentrator,
    out_count: &mut usize,
) -> *const CharSlice<'a> {
    let slices = &c.peer_tag_key_slices;
    *out_count = slices.len();
    if slices.is_empty() {
        std::ptr::null()
    } else {
        slices.as_ptr()
    }
}

/// Add a PHP span to the concentrator for stats computation.
///
/// Fast eligibility pre-check: returns true if a span with these attributes would be accepted
/// by `ddog_span_concentrator_add_php_span`.
///
/// Call this before constructing the full `PhpSpanStats`.  If it returns false, skip the span
/// entirely.  If it returns true, fill the remaining fields and call `add_php_span`.
#[no_mangle]
pub extern "C" fn ddog_span_concentrator_is_eligible(
    c: &SpanConcentrator,
    has_top_level: bool,
    is_measured: bool,
    span_kind: CharSlice<'_>,
    is_partial_snapshot: bool,
) -> bool {
    if is_partial_snapshot {
        return false;
    }
    if has_top_level || is_measured {
        return true;
    }
    let kind = span_kind.try_to_utf8().unwrap_or("");
    if kind.is_empty() {
        return false;
    }
    c.span_kinds.iter().any(|k| k == kind)
}

/// Write a PHP span to the concentrator's backing SHM.
///
/// Only valid when `ddog_span_concentrator_has_shm` returns `true`.  For virtual concentrators
/// (no SHM) the caller should use the IPC path instead.
///
/// All `CharSlice` fields in `span` (and in the `peer_tags` array it points to) must remain valid
/// for the duration of this call.
///
/// # Safety
/// `span` must point to a valid `PhpSpanStats`.  The concentrator must have a backing SHM
/// (`ddog_span_concentrator_has_shm` returns `true`).
#[no_mangle]
pub unsafe extern "C" fn ddog_span_concentrator_add_php_span(
    c: &SpanConcentrator,
    span: &PhpSpanStats<'_>,
) {
    if let Some(shm) = &c.inner {
        let mut peer_tag_buf = [("", ""); MAX_PEER_TAGS];
        let input = php_span_to_shm_input(span, &mut peer_tag_buf);
        shm.add_span(&input);
    }
}

/// Convert a `PhpSpanStats` to `OwnedShmSpanInput` for IPC transport.
unsafe fn php_span_to_owned_input(span: &PhpSpanStats<'_>) -> OwnedShmSpanInput {
    let peer_tags = peer_tags_slice(span)
        .iter()
        .take(MAX_PEER_TAGS)
        .map(|pt| (char_slice_str(pt.key).to_owned(), char_slice_str(pt.value).to_owned()))
        .collect();
    let fixed = build_fixed_key(span);

    OwnedShmSpanInput {
        fixed: FixedAggregationKey {
            service_name: fixed.service_name.to_owned(),
            resource_name: fixed.resource_name.to_owned(),
            operation_name: fixed.operation_name.to_owned(),
            span_type: fixed.span_type.to_owned(),
            span_kind: fixed.span_kind.to_owned(),
            http_method: fixed.http_method.to_owned(),
            http_endpoint: fixed.http_endpoint.to_owned(),
            http_status_code: fixed.http_status_code,
            grpc_status_code: fixed.grpc_status_code,
            is_synthetics_request: fixed.is_synthetics_request,
            is_trace_root: fixed.is_trace_root,
            service_source: fixed.service_source.to_owned(),
        },
        peer_tags,
        duration_ns: span.duration,
        is_error: span.is_error,
        is_top_level: span.has_top_level,
    }
}

/// IPC fallback: send a PHP span directly to the sidecar's SHM concentrator for (env, version).
///
/// Called when the SHM is not yet available.  The sidecar processes IPC messages sequentially,
/// and `set_universal_service_tags` is always sent before this message, so the concentrator
/// is guaranteed to exist when the sidecar handles this call.  The sidecar resolves the service
/// dimension from the session's `DD_SERVICE` config.
///
/// # Safety
/// All pointers must be valid.
#[no_mangle]
pub unsafe extern "C" fn ddog_sidecar_add_php_span_to_concentrator(
    transport: &mut Box<SidecarTransport>,
    env: CharSlice<'_>,
    version: CharSlice<'_>,
    span: &PhpSpanStats<'_>,
) {
    let env_str = char_slice_str(env).to_owned();
    let version_str = char_slice_str(version).to_owned();
    let owned_span = php_span_to_owned_input(span);
    if let Err(e) = add_span_to_concentrator(transport, env_str, version_str, owned_span) {
        trace!("Failed to send span to concentrator via IPC: {e}");
    }
}
