#![allow(internal_features)]
#![feature(allow_internal_unstable)]
#![feature(linkage)]
#![allow(static_mut_refs)] // remove with move to Rust 2024 edition

pub mod agent_info;
pub mod ffe;
pub mod log;
pub mod remote_config;
pub mod sidecar;
pub mod stats;
pub mod telemetry;
pub mod trace_filter;
pub mod bytes;

pub use datadog_sidecar_ffi::*;
pub use libdd_crashtracker_ffi::*;
pub use libdd_common_ffi::*;
pub use libdd_library_config_ffi::*;
pub use libdd_telemetry_ffi::*;

use http::uri::{PathAndQuery, Scheme};
use http::Uri;
use libdd_common::entity_id::{get_container_id, set_cgroup_file};
use libdd_common::{parse_uri, Endpoint};
use libdd_common_ffi::slice::{AsBytes, CharSlice};
use std::borrow::Cow;
use std::ffi::{c_char, OsStr};
use std::ptr::null_mut;
use uuid::Uuid;

#[cfg(any(target_os = "macos", target_os = "windows"))]
use std::sync::atomic::{AtomicPtr, Ordering};

#[cfg(unix)]
use libdd_common::connector::uds::socket_path_to_uri;
#[cfg(unix)]
use std::path::Path;

#[cfg(target_os = "linux")]
use libdd_library_config::otel_process_ctx;
#[cfg(target_os = "linux")]
use libdd_library_config::tracer_metadata::{ThreadLocalMetadata, TracerMetadata};

/// Borrowed byte string stored in an immutable process-context snapshot.
///
/// Every view returned by [`ddtrace_get_process_context_v1`] remains valid for the lifetime of the
/// process. The bytes are UTF-8 but are not NUL-terminated.
#[cfg(any(target_os = "macos", target_os = "windows"))]
#[repr(C)]
#[derive(Clone, Copy, Debug)]
pub struct ProcessContextStringView {
    pub ptr: *const c_char,
    pub len: usize,
}

#[cfg(any(target_os = "macos", target_os = "windows"))]
impl ProcessContextStringView {
    const EMPTY: Self = Self {
        ptr: std::ptr::null(),
        len: 0,
    };

    fn new(value: &str) -> Self {
        Self {
            ptr: value.as_ptr().cast(),
            len: value.len(),
        }
    }
}

/// Immutable, process-wide metadata corresponding to the OTel process context published on Linux.
///
/// All pointed-to data remains valid for the lifetime of the process.
#[cfg(any(target_os = "macos", target_os = "windows"))]
#[repr(C)]
#[derive(Debug)]
pub struct ProcessContextV1 {
    pub publisher_pid: u32,
    pub reserved: u32,
    pub service_name: ProcessContextStringView,
    pub service_instance_id: ProcessContextStringView,
    pub service_version: ProcessContextStringView,
    pub deployment_environment_name: ProcessContextStringView,
    pub telemetry_sdk_language: ProcessContextStringView,
    pub telemetry_sdk_version: ProcessContextStringView,
    pub telemetry_sdk_name: ProcessContextStringView,
    pub host_name: ProcessContextStringView,
    pub container_id: ProcessContextStringView,
    pub datadog_process_tags: ProcessContextStringView,
    pub threadlocal_schema_version: ProcessContextStringView,
    pub threadlocal_attribute_keys: *const ProcessContextStringView,
    pub threadlocal_attribute_key_count: usize,
}

#[cfg(any(target_os = "macos", target_os = "windows"))]
struct OwnedProcessContextV1 {
    public: ProcessContextV1,
    _service_instance_id: Box<str>,
    _host_name: Box<str>,
    _threadlocal_attribute_keys: Box<[ProcessContextStringView]>,
}

#[cfg(any(target_os = "macos", target_os = "windows"))]
static PROCESS_CONTEXT: AtomicPtr<ProcessContextV1> = AtomicPtr::new(std::ptr::null_mut());

#[no_mangle]
#[allow(non_upper_case_globals)]
pub static mut datadog_runtime_id: Uuid = Uuid::nil();

#[no_mangle]
#[allow(non_upper_case_globals)]
pub static mut datadog_session_id: Uuid = Uuid::nil();

#[no_mangle]
#[allow(non_upper_case_globals)]
pub static mut datadog_formatted_session_id: [u8; 36] = [0u8; 36];

#[no_mangle]
#[allow(non_upper_case_globals)]
pub static mut datadog_formatted_root_session_id: [u8; 36] = [0u8; 36];

#[no_mangle]
#[allow(non_upper_case_globals)]
pub static mut datadog_formatted_parent_session_id: [u8; 36] = [0u8; 36];

/// # Safety
/// Must be called from a single-threaded context, such as MINIT or first rinit.
#[no_mangle]
pub unsafe extern "C" fn datadog_generate_runtime_id() {
    datadog_runtime_id = Uuid::new_v4();
}

/// # Safety
/// Must be called from a single-threaded context, such as MINIT.
#[no_mangle]
pub unsafe extern "C" fn datadog_generate_session_id() {
    datadog_session_id = Uuid::new_v4();
    datadog_runtime_id = datadog_session_id;
    datadog_session_id.as_hyphenated().encode_lower(&mut datadog_formatted_session_id);

    unsafe fn set(name: &str, value: &mut [u8; 36], force: bool) {
        if let Ok(str) = std::env::var(name) {
            let bytes = str.as_bytes();
            if bytes.len() == 36 {
                value.copy_from_slice(bytes);
                if !force {
                    return;
                }
            }
        }
        std::env::set_var(name, OsStr::from_encoded_bytes_unchecked(&datadog_formatted_session_id));
    }

    set("_DD_PARENT_PHP_SESSION_ID", &mut datadog_formatted_parent_session_id, true);
    set("_DD_ROOT_PHP_SESSION_ID", &mut datadog_formatted_root_session_id, false);
}

#[no_mangle]
pub extern "C" fn datadog_format_runtime_id(buf: &mut [u8; 36]) {
    // Safety: datadog_runtime_id is only supposed to be mutated from single-
    // threaded contexts, so reads should always be safe.
    unsafe { datadog_runtime_id.as_hyphenated().encode_lower(buf) };
}

#[cfg(target_os = "linux")]
#[no_mangle]
pub extern "C" fn datadog_publish_otel_process_context(hostname: CharSlice<'_>) -> bool {
    let runtime_id = unsafe {
        (!datadog_runtime_id.is_nil()).then(|| datadog_runtime_id.as_hyphenated().to_string())
    };

    // This process context publishes the PHP-provided thread-local attribute
    // key map. libdatadog prepends datadog.local_root_span_id automatically;
    // request/runtime values for all keys are stored in the thread-local
    // context record itself.
    let metadata = TracerMetadata {
        runtime_id,
        hostname: hostname.to_utf8_lossy().into_owned(),
        tracer_language: "php".to_owned(),
        tracer_version: include_str!("../VERSION").trim().to_owned(),
        threadlocal_metadata: Some(ThreadLocalMetadata {
            attribute_keys: vec![
                "service.name".to_owned(),
                "service.version".to_owned(),
                "deployment.environment.name".to_owned(),
            ],
            ..Default::default()
        }),
        ..Default::default()
    };

    let context = metadata.to_otel_process_ctx();

    // libdatadog owns the process-wide writer and transparently updates an existing publication or
    // creates a fresh platform-specific publication after a fork.
    match otel_process_ctx::publish(&context) {
        Ok(()) => true,
        Err(error) => {
            tracing::debug!("failed to publish OTel process context: {error}");
            false
        }
    }
}

#[cfg(any(target_os = "macos", target_os = "windows"))]
#[no_mangle]
pub extern "C" fn datadog_publish_otel_process_context(hostname: CharSlice<'_>) -> bool {
    let service_instance_id = unsafe {
        (!datadog_runtime_id.is_nil()).then(|| datadog_runtime_id.as_hyphenated().to_string())
    }
    .unwrap_or_default();

    publish_process_context_v1(
        service_instance_id.into_boxed_str(),
        hostname.to_utf8_lossy().into_owned().into_boxed_str(),
    );
    true
}

/// Returns the latest immutable process-context snapshot for this process.
///
/// The returned snapshot and all data referenced by it remain valid for the lifetime of the
/// process. A null pointer means no context has been published, or that the inherited context is
/// stale after `fork()` and the child has not republished yet.
#[cfg(any(target_os = "macos", target_os = "windows"))]
#[no_mangle]
pub extern "C" fn ddtrace_get_process_context_v1() -> *const ProcessContextV1 {
    get_process_context_v1_for_pid(std::process::id())
}

#[cfg(any(target_os = "macos", target_os = "windows"))]
fn get_process_context_v1_for_pid(pid: u32) -> *const ProcessContextV1 {
    let context = PROCESS_CONTEXT.load(Ordering::Acquire);
    if context.is_null() {
        return std::ptr::null();
    }

    // SAFETY: published snapshots are fully initialized before the release store and deliberately
    // leaked, so an acquire-loaded pointer remains valid for the process lifetime.
    if unsafe { (*context).publisher_pid } != pid {
        return std::ptr::null();
    }

    context.cast_const()
}

#[cfg(any(target_os = "macos", target_os = "windows"))]
fn publish_process_context_v1(service_instance_id: Box<str>, host_name: Box<str>) {
    let threadlocal_attribute_keys: Box<[ProcessContextStringView]> = [
        ProcessContextStringView::new("datadog.local_root_span_id"),
        ProcessContextStringView::new("service.name"),
        ProcessContextStringView::new("service.version"),
        ProcessContextStringView::new("deployment.environment.name"),
    ]
    .into();
    let threadlocal_attribute_keys_ptr = threadlocal_attribute_keys.as_ptr();
    let threadlocal_attribute_key_count = threadlocal_attribute_keys.len();
    let mut owned = Box::new(OwnedProcessContextV1 {
        public: ProcessContextV1 {
            publisher_pid: std::process::id(),
            reserved: 0,
            service_name: ProcessContextStringView::EMPTY,
            service_instance_id: ProcessContextStringView::EMPTY,
            service_version: ProcessContextStringView::EMPTY,
            deployment_environment_name: ProcessContextStringView::EMPTY,
            telemetry_sdk_language: ProcessContextStringView::new("php"),
            telemetry_sdk_version: ProcessContextStringView::new(include_str!("../VERSION").trim()),
            telemetry_sdk_name: ProcessContextStringView::new("libdatadog"),
            host_name: ProcessContextStringView::EMPTY,
            container_id: ProcessContextStringView::EMPTY,
            datadog_process_tags: ProcessContextStringView::EMPTY,
            threadlocal_schema_version: ProcessContextStringView::new("tlsdesc_v1_dev"),
            threadlocal_attribute_keys: threadlocal_attribute_keys_ptr,
            threadlocal_attribute_key_count,
        },
        _service_instance_id: service_instance_id,
        _host_name: host_name,
        _threadlocal_attribute_keys: threadlocal_attribute_keys,
    });

    owned.public.service_instance_id = ProcessContextStringView::new(&owned._service_instance_id);
    owned.public.host_name = ProcessContextStringView::new(&owned._host_name);

    // Published snapshots are immutable and retained permanently. Publication is rare (initial
    // activation and child-side fork handling), and retaining prior snapshots lets readers race
    // updates without locks, reference counting, or use-after-free hazards.
    let leaked = Box::leak(owned);
    PROCESS_CONTEXT.store(std::ptr::from_mut(&mut leaked.public), Ordering::Release);
}

#[cfg(not(any(target_os = "linux", target_os = "macos", target_os = "windows")))]
#[no_mangle]
pub extern "C" fn datadog_publish_otel_process_context(_hostname: CharSlice<'_>) -> bool {
    false
}

#[must_use]
#[no_mangle]
pub extern "C" fn ddtrace_get_container_id() -> CharSlice<'static> {
    get_container_id().map_or(CharSlice::default(), CharSlice::from)
}

#[no_mangle]
pub unsafe extern "C" fn ddtrace_set_container_cgroup_path(path: CharSlice) {
    set_cgroup_file(String::from(path.try_to_utf8().unwrap()))
}

#[no_mangle]
pub unsafe extern "C" fn ddtrace_strip_invalid_utf8(
    input: *const c_char,
    len: *mut usize,
) -> *mut c_char {
    match CharSlice::from_raw_parts(input, *len).to_utf8_lossy() {
        Cow::Borrowed(_) => null_mut(),
        Cow::Owned(s) => {
            *len = s.len();
            let ret = s.as_ptr() as *mut c_char;
            std::mem::forget(s);
            ret
        }
    }
}

#[no_mangle]
pub unsafe extern "C" fn ddtrace_drop_rust_string(input: *mut c_char, len: usize) {
    _ = String::from_raw_parts(input as *mut u8, len, len);
}

#[no_mangle]
pub unsafe extern "C" fn datadog_parse_agent_url(
    url: CharSlice,
) -> std::option::Option<Box<Endpoint>> {
    parse_uri(url.to_utf8_lossy().as_ref())
        .ok()
        .and_then(|url| {
            if url.authority().is_none() {
                None
            } else {
                Some(url)
            }
        })
        .map(|mut url| {
            if url.scheme().is_none() {
                let mut parts = url.into_parts();
                parts.scheme = Some(Scheme::HTTP);
                if parts.path_and_query.is_none() {
                    parts.path_and_query = Some(PathAndQuery::from_static("/"));
                }
                url = Uri::from_parts(parts).unwrap();
            }
            Box::new(Endpoint::from_url(url))
        })
}

#[cfg(unix)]
fn otel_metrics_endpoint_from_unix_socket(_socket_path: &str) -> std::option::Option<Box<Endpoint>> {
    socket_path_to_uri(Path::new(_socket_path)).ok().and_then(|uri| {
        let mut parts = uri.into_parts();
        parts.path_and_query = Some(PathAndQuery::from_static("/v1/metrics"));
        Uri::from_parts(parts)
            .ok()
            .map(|url| Box::new(Endpoint::from_url(url)))
    })
}

#[no_mangle]
pub unsafe extern "C" fn datadog_otel_metrics_endpoint_from_url(url: CharSlice) -> std::option::Option<Box<Endpoint>> {
    let url_str = url.to_utf8_lossy();
    #[cfg(unix)]
    if let Some(socket_path) = url_str.strip_prefix("unix://") {
        let socket_path = socket_path.strip_suffix("/v1/metrics").unwrap_or(socket_path);
        return otel_metrics_endpoint_from_unix_socket(socket_path);
    }
    parse_uri(url_str.as_ref())
        .ok()
        .map(|url| Box::new(Endpoint::from_url(url)))
}

#[no_mangle]
pub unsafe extern "C" fn datadog_otel_metrics_endpoint_from_agent_url(url: CharSlice) -> std::option::Option<Box<Endpoint>> {
    let url_str = url.to_utf8_lossy();
    #[cfg(unix)]
    if let Some(socket_path) = url_str.strip_prefix("unix://") {
        return otel_metrics_endpoint_from_unix_socket(socket_path);
    }
    if url_str.starts_with("http") {
        let parsed = parse_uri(url_str.as_ref()).ok();
        let scheme = parsed.as_ref().and_then(|u| u.scheme_str()).unwrap_or("http");
        let host = parsed
            .as_ref()
            .and_then(|u| u.host())
            .unwrap_or("localhost");
        parse_uri(&format!("{}://{}:4318/v1/metrics", scheme, host))
            .ok()
            .map(|url| Box::new(Endpoint::from_url(url)))
    } else {
        datadog_parse_agent_url(url)
    }
}

#[no_mangle]
#[cfg(unix)]
pub unsafe extern "C" fn datadog_endpoint_as_crashtracker_config(
    endpoint: &Endpoint,
    callback: unsafe extern "C" fn(EndpointConfig<'_>, *mut std::ffi::c_void),
    userdata: *mut std::ffi::c_void,
) {
    let url_str = endpoint.url.to_string();
    unsafe {
        callback(
            EndpointConfig {
                url: CharSlice::from(url_str.as_str()),
                api_key: CharSlice::from(endpoint.api_key.as_deref().unwrap_or("")),
                test_token: CharSlice::from(endpoint.test_token.as_deref().unwrap_or("")),
                timeout: endpoint.timeout_ms,
                use_system_resolver: endpoint.use_system_resolver,
            },
            userdata,
        );
    }
}

// Hack: Without this, the PECL build of the tracer does not contain the ddog_library_* functions
// It works well without in the "normal" build
#[no_mangle]
pub extern "C" fn ddog_library_configurator_new_dummy(
    debug_logs: bool,
    language: CharSlice,
) -> Box<Configurator> {
    ddog_library_configurator_new(debug_logs, language)
}

// Starting with https://github.com/rust-lang/rust/commit/7f74c894b0e31f370b5321d94f2ca2830e1d30fd
// rust assumes posix_spawn_file_actions_addchdir_np exists. Thus we need to polyfill it here.
#[no_mangle]
#[cfg(all(target_os = "linux", target_env = "musl"))]
pub unsafe extern "C" fn posix_spawn_file_actions_addchdir_np(
    file_actions: *mut libc::c_void,
    path: *const libc::c_char,
) -> libc::c_int {
    let sym = std::mem::transmute::<
        *mut libc::c_void,
        std::option::Option<extern "C" fn(*mut libc::c_void, *const libc::c_char) -> libc::c_int>,
    >(libc::dlsym(
        libc::RTLD_NEXT,
        c"posix_spawn_file_actions_addchdir_np".as_ptr(),
    ));
    if let Some(sym) = sym {
        sym(file_actions, path)
    } else {
        libc::ENOSYS
    }
}

const MAX_TAG_VALUE_LENGTH: usize = 100;
const DD_FNV_PRIME: u64 = 1_099_511_628_211;
const DD_FNV_OFFSET_BASIS: u64 = 14_695_981_039_346_656_037;

#[no_mangle]
pub unsafe extern "C" fn dd_fnv1a_64(data: *const u8, len: usize) -> u64 {
    if data.is_null() || len == 0 {
        return DD_FNV_OFFSET_BASIS;
    }

    let bytes = std::slice::from_raw_parts(data, len);
    let mut hash = DD_FNV_OFFSET_BASIS;
    for byte in bytes {
        hash ^= u64::from(*byte);
        hash = hash.wrapping_mul(DD_FNV_PRIME);
    }

    hash
}

#[no_mangle]
pub extern "C" fn ddog_normalize_process_tag_value(
    tag_value: CharSlice,
) -> *const c_char {
    let value = tag_value.to_utf8_lossy();

    let mut out = String::new();
    let mut prev_underscore = false;
    let mut started = false;
    for c in value.chars().take(MAX_TAG_VALUE_LENGTH) {
        if c.is_alphanumeric() || matches!(c, '/' | '.' | '-') {
            for lc in c.to_lowercase() {
                out.push(lc);
            }
            started = true;
            prev_underscore = false;
        } else {
            if started && !prev_underscore {
                out.push('_');
                prev_underscore = true;
            }
        }
    }

    // trim trailing underscores
    if out.ends_with('_') {
        out.pop();
    }

    match std::ffi::CString::new(out) {
        Ok(c_string) => {
            let out_ptr = c_string.as_ptr();
            std::mem::forget(c_string);
            out_ptr
        }
        Err(_) => std::ptr::null(),
    }
}

#[no_mangle]
pub extern "C" fn ddog_free_normalized_tag_value(ptr: *const c_char) {
    if ptr.is_null() {
        return;
    }
    unsafe {
        drop(std::ffi::CString::from_raw(ptr as *mut c_char));
    }
}

#[cfg(all(test, any(target_os = "macos", target_os = "windows")))]
mod process_context_tests {
    use super::*;

    fn view_to_string(view: ProcessContextStringView) -> String {
        if view.len == 0 {
            return String::new();
        }

        // SAFETY: process-context views point into immutable allocations retained for the process
        // lifetime, and a non-empty view always has a non-null pointer to `len` initialized bytes.
        let bytes = unsafe { std::slice::from_raw_parts(view.ptr.cast(), view.len) };
        std::str::from_utf8(bytes)
            .expect("process-context values must be UTF-8")
            .to_owned()
    }

    #[test]
    fn immutable_process_context_publication_is_race_free() {
        publish_process_context_v1("first".into(), "first".into());
        let first = ddtrace_get_process_context_v1();
        assert!(!first.is_null());

        // SAFETY: the getter returned a non-null, process-lifetime snapshot.
        let first_snapshot = unsafe { &*first };
        assert_eq!(view_to_string(first_snapshot.service_instance_id), "first");
        assert_eq!(view_to_string(first_snapshot.host_name), "first");
        assert_eq!(view_to_string(first_snapshot.telemetry_sdk_language), "php");
        assert_eq!(
            view_to_string(first_snapshot.telemetry_sdk_name),
            "libdatadog"
        );

        // SAFETY: the key array is part of the immutable snapshot retained for the process lifetime.
        let keys = unsafe {
            std::slice::from_raw_parts(
                first_snapshot.threadlocal_attribute_keys,
                first_snapshot.threadlocal_attribute_key_count,
            )
        };
        assert_eq!(
            keys.iter()
                .copied()
                .map(view_to_string)
                .collect::<std::vec::Vec<_>>(),
            [
                "datadog.local_root_span_id",
                "service.name",
                "service.version",
                "deployment.environment.name",
            ]
        );

        publish_process_context_v1("second".into(), "second".into());
        let second = ddtrace_get_process_context_v1();
        assert_ne!(first, second);
        // The old snapshot remains readable after replacement.
        assert_eq!(view_to_string(first_snapshot.service_instance_id), "first");
        // SAFETY: the getter returned a non-null, process-lifetime snapshot.
        assert_eq!(
            view_to_string(unsafe { &*second }.service_instance_id),
            "second"
        );
        let wrong_pid = std::process::id().wrapping_add(1);
        assert!(get_process_context_v1_for_pid(wrong_pid).is_null());

        let writer = std::thread::spawn(|| {
            for i in 0..250 {
                let value = format!("snapshot-{i}").into_boxed_str();
                publish_process_context_v1(value.clone(), value);
            }
        });
        let readers: std::vec::Vec<_> = (0..4)
            .map(|_| {
                std::thread::spawn(|| {
                    for _ in 0..2_000 {
                        let snapshot = ddtrace_get_process_context_v1();
                        assert!(!snapshot.is_null());
                        // SAFETY: the getter returned a non-null, process-lifetime snapshot.
                        let snapshot = unsafe { &*snapshot };
                        assert_eq!(
                            view_to_string(snapshot.service_instance_id),
                            view_to_string(snapshot.host_name)
                        );
                    }
                })
            })
            .collect();

        writer.join().expect("writer thread should not panic");
        for reader in readers {
            reader.join().expect("reader thread should not panic");
        }
    }
}
