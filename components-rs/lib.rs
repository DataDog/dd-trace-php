#![allow(internal_features)]
#![feature(allow_internal_unstable)]
#![feature(linkage)]
#![allow(static_mut_refs)] // remove with move to Rust 2024 edition

pub mod agent_info;
pub mod bytes;
pub mod ffe;
pub mod log;
pub mod remote_config;
pub mod sidecar;
pub mod stats;
pub mod telemetry;
pub mod trace_filter;

use http::uri::{PathAndQuery, Scheme};
use http::Uri;
use libdd_alloc::{Allocator, VirtualAllocator};
use libdd_common::entity_id::{get_container_id, set_cgroup_file};
use libdd_library_config::otel_process_ctx;
#[cfg(not(target_os = "linux"))]
use libdd_library_config::otel_process_ctx::ProcessContextMapping;
use libdd_library_config::tracer_metadata::{ThreadLocalMetadata, TracerMetadata};
use std::borrow::Cow;
use std::ffi::{c_char, OsStr};
#[cfg(unix)]
use std::path::Path;
use std::ptr::null_mut;
use uuid::Uuid;

pub use datadog_sidecar_ffi::*;
#[cfg(unix)]
use libdd_common::connector::uds::socket_path_to_uri;
use libdd_common::{parse_uri, Endpoint};
use libdd_common_ffi::slice::AsBytes;
pub use libdd_common_ffi::*;
pub use libdd_crashtracker_ffi::*;
pub use libdd_library_config_ffi::*;
pub use libdd_otel_thread_ctx_ffi::*;
pub use libdd_telemetry_ffi::*;

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

const PHP_OTEL_PROCESS_CONTEXT_SIZE: usize = 16 * 1024;

pub struct PhpOtelProcessContext {
    allocation: std::option::Option<std::ptr::NonNull<[u8]>>,
    initialized: bool,
}

#[cfg(not(target_os = "linux"))]
fn allocate_otel_process_context(
    allocator: &impl Allocator,
) -> std::result::Result<std::ptr::NonNull<[u8]>, libdd_alloc::AllocError> {
    let layout = std::alloc::Layout::from_size_align(PHP_OTEL_PROCESS_CONTEXT_SIZE, 8)
        .expect("the fixed OTel process-context layout is valid");
    allocator.allocate_zeroed(layout)
}

impl PhpOtelProcessContext {
    #[cfg(not(target_os = "linux"))]
    fn mapping(&self) -> std::io::Result<ProcessContextMapping> {
        let allocation = self.allocation.ok_or_else(|| {
            std::io::Error::new(std::io::ErrorKind::NotFound, "no caller-owned mapping")
        })?;
        unsafe {
            ProcessContextMapping::from_raw_parts(
                allocation.as_ptr().cast::<u8>(),
                allocation.len(),
            )
        }
    }
}

impl Drop for PhpOtelProcessContext {
    fn drop(&mut self) {
        #[cfg(not(target_os = "linux"))]
        if self.initialized {
            if let Ok(mapping) = self.mapping() {
                otel_process_ctx::invalidate(mapping);
            }
        }
        if let (Some(allocation), Ok(layout)) = (
            self.allocation,
            std::alloc::Layout::from_size_align(PHP_OTEL_PROCESS_CONTEXT_SIZE, 8),
        ) {
            unsafe { (VirtualAllocator {}).deallocate(allocation.cast(), layout) };
        }
    }
}

#[no_mangle]
pub extern "C" fn datadog_otel_process_context_new() -> *mut PhpOtelProcessContext {
    #[cfg(target_os = "linux")]
    {
        return Box::into_raw(Box::new(PhpOtelProcessContext {
            allocation: None,
            initialized: false,
        }));
    }
    #[cfg(not(target_os = "linux"))]
    match allocate_otel_process_context(&VirtualAllocator {}) {
        Ok(allocation) => Box::into_raw(Box::new(PhpOtelProcessContext {
            allocation: Some(allocation),
            initialized: false,
        })),
        Err(_) => std::ptr::null_mut(),
    }
}

#[no_mangle]
pub unsafe extern "C" fn datadog_otel_process_context_publish(
    storage: *mut PhpOtelProcessContext,
    hostname: CharSlice<'_>,
    service: CharSlice<'_>,
    env: CharSlice<'_>,
    version: CharSlice<'_>,
    process_tags: CharSlice<'_>,
) -> bool {
    let Some(storage) = (unsafe { storage.as_mut() }) else {
        return false;
    };
    let value = |slice: CharSlice<'_>| {
        let value = slice.to_utf8_lossy();
        (!value.is_empty()).then(|| value.into_owned())
    };
    let runtime_id = unsafe {
        (!datadog_runtime_id.is_nil()).then(|| datadog_runtime_id.as_hyphenated().to_string())
    };
    let metadata = TracerMetadata {
        runtime_id,
        tracer_language: "php".to_owned(),
        tracer_version: include_str!("../VERSION").trim().to_owned(),
        hostname: hostname.to_utf8_lossy().into_owned(),
        service_name: value(service),
        service_env: value(env),
        service_version: value(version),
        process_tags: value(process_tags),
        container_id: get_container_id().map(str::to_owned),
        threadlocal_metadata: Some(ThreadLocalMetadata {
            attribute_keys: vec![
                "service.name".to_owned(),
                "service.version".to_owned(),
                "deployment.environment.name".to_owned(),
                "datadog.thread_id".to_owned(),
            ],
            ..Default::default()
        }),
        ..Default::default()
    };
    let context = metadata.to_otel_process_ctx();
    #[cfg(target_os = "linux")]
    {
        return match otel_process_ctx::publish(&context) {
            Ok(()) => {
                storage.initialized = true;
                true
            }
            Err(error) => {
                storage.initialized = false;
                tracing::error!("failed to publish PHP OTel process context: {error}");
                false
            }
        };
    }
    #[cfg(not(target_os = "linux"))]
    let mapping = match storage.mapping() {
        Ok(mapping) => mapping,
        Err(error) => {
            tracing::error!("invalid PHP OTel process-context allocation: {error}");
            storage.initialized = false;
            return false;
        }
    };
    #[cfg(not(target_os = "linux"))]
    let result = if storage.initialized {
        otel_process_ctx::update(mapping, &context)
    } else {
        otel_process_ctx::initialize(mapping, &context)
    };
    #[cfg(not(target_os = "linux"))]
    match result {
        Ok(()) => {
            storage.initialized = true;
            true
        }
        Err(error) => {
            otel_process_ctx::invalidate(mapping);
            storage.initialized = false;
            tracing::error!("failed to publish PHP OTel process context: {error}");
            false
        }
    }
}

#[no_mangle]
#[cfg(not(target_os = "linux"))]
pub unsafe extern "C" fn datadog_otel_process_context_mapping(
    storage: *const PhpOtelProcessContext,
    base: *mut *const u8,
    len: *mut usize,
) -> bool {
    let (Some(storage), Some(base), Some(len)) = (
        unsafe { storage.as_ref() },
        unsafe { base.as_mut() },
        unsafe { len.as_mut() },
    ) else {
        return false;
    };
    let Some(allocation) = storage.allocation else {
        return false;
    };
    *base = allocation.as_ptr().cast::<u8>();
    *len = allocation.len();
    true
}

#[no_mangle]
pub unsafe extern "C" fn datadog_otel_process_context_drop(storage: *mut PhpOtelProcessContext) {
    if !storage.is_null() {
        drop(unsafe { Box::from_raw(storage) });
    }
}

#[cfg(all(test, not(target_os = "linux")))]
mod otel_process_context_tests {
    use super::*;

    struct FailingAllocator;

    unsafe impl Allocator for FailingAllocator {
        fn allocate(
            &self,
            _layout: std::alloc::Layout,
        ) -> std::result::Result<std::ptr::NonNull<[u8]>, libdd_alloc::AllocError> {
            Err(libdd_alloc::AllocError)
        }

        unsafe fn deallocate(&self, _ptr: std::ptr::NonNull<u8>, _layout: std::alloc::Layout) {
            unreachable!("a failing allocator cannot produce an allocation")
        }
    }

    #[test]
    fn allocation_failure_is_non_fatal() {
        assert!(allocate_otel_process_context(&FailingAllocator).is_err());
    }

    #[test]
    fn unavailable_storage_fails_closed() {
        assert!(!unsafe {
            datadog_otel_process_context_publish(
                std::ptr::null_mut(),
                CharSlice::from("host"),
                CharSlice::from("service"),
                CharSlice::from("env"),
                CharSlice::from("version"),
                CharSlice::from("process-tag:value"),
            )
        });
    }

    #[test]
    fn normal_context_is_inline_and_oversized_context_invalidates_publication() {
        let storage = datadog_otel_process_context_new();
        assert!(!storage.is_null());
        let mut base = std::ptr::null();
        let mut len = 0;
        assert!(unsafe { datadog_otel_process_context_mapping(storage, &mut base, &mut len) });
        assert_eq!(len, PHP_OTEL_PROCESS_CONTEXT_SIZE);
        assert!(unsafe {
            datadog_otel_process_context_publish(
                storage,
                CharSlice::from("host"),
                CharSlice::from("service"),
                CharSlice::from("env"),
                CharSlice::from("version"),
                CharSlice::from("process-tag:value"),
            )
        });

        assert!(unsafe { datadog_otel_process_context_mapping(storage, &mut base, &mut len) });
        assert!(!base.is_null());
        assert_eq!(len, PHP_OTEL_PROCESS_CONTEXT_SIZE);
        let payload_size = unsafe {
            std::sync::atomic::AtomicU32::from_ptr(base.add(12).cast_mut().cast())
                .load(std::sync::atomic::Ordering::Relaxed)
        };
        assert!(
            payload_size < 1024,
            "normal process context is unexpectedly large"
        );
        let mapping = unsafe {
            ProcessContextMapping::from_raw_parts(base.cast_mut(), len).expect("valid mapping")
        };
        let context = otel_process_ctx::decode(mapping).expect("decodable process context");
        assert_eq!(
            otel_process_ctx::threadlocal_attribute_key_map(&context),
            Some(vec![
                "datadog.local_root_span_id".to_owned(),
                "service.name".to_owned(),
                "service.version".to_owned(),
                "deployment.environment.name".to_owned(),
                "datadog.thread_id".to_owned(),
            ])
        );
        let process_tags = context
            .extra_attributes
            .iter()
            .find(|attribute| attribute.key == "datadog.process_tags")
            .and_then(|attribute| attribute.value.as_ref())
            .and_then(|value| value.value.as_ref());
        assert!(matches!(
            process_tags,
            Some(libdd_trace_protobuf::opentelemetry::proto::common::v1::any_value::Value::StringValue(value))
                if value == "process-tag:value"
        ));

        let oversized = "x".repeat(PHP_OTEL_PROCESS_CONTEXT_SIZE * 2);
        assert!(!unsafe {
            datadog_otel_process_context_publish(
                storage,
                CharSlice::from("host"),
                CharSlice::from("service"),
                CharSlice::from("env"),
                CharSlice::from("version"),
                CharSlice::from(oversized.as_str()),
            )
        });
        assert!(unsafe { datadog_otel_process_context_mapping(storage, &mut base, &mut len) });
        assert!(otel_process_ctx::decode(mapping).is_err());

        unsafe { datadog_otel_process_context_drop(storage) };
    }
}

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
    datadog_session_id
        .as_hyphenated()
        .encode_lower(&mut datadog_formatted_session_id);

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
        std::env::set_var(
            name,
            OsStr::from_encoded_bytes_unchecked(&datadog_formatted_session_id),
        );
    }

    set(
        "_DD_PARENT_PHP_SESSION_ID",
        &mut datadog_formatted_parent_session_id,
        true,
    );
    set(
        "_DD_ROOT_PHP_SESSION_ID",
        &mut datadog_formatted_root_session_id,
        false,
    );
}

#[no_mangle]
pub extern "C" fn datadog_format_runtime_id(buf: &mut [u8; 36]) {
    // Safety: datadog_runtime_id is only supposed to be mutated from single-
    // threaded contexts, so reads should always be safe.
    unsafe { datadog_runtime_id.as_hyphenated().encode_lower(buf) };
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
fn otel_metrics_endpoint_from_unix_socket(
    _socket_path: &str,
) -> std::option::Option<Box<Endpoint>> {
    socket_path_to_uri(Path::new(_socket_path))
        .ok()
        .and_then(|uri| {
            let mut parts = uri.into_parts();
            parts.path_and_query = Some(PathAndQuery::from_static("/v1/metrics"));
            Uri::from_parts(parts)
                .ok()
                .map(|url| Box::new(Endpoint::from_url(url)))
        })
}

#[no_mangle]
pub unsafe extern "C" fn datadog_otel_metrics_endpoint_from_url(
    url: CharSlice,
) -> std::option::Option<Box<Endpoint>> {
    let url_str = url.to_utf8_lossy();
    #[cfg(unix)]
    if let Some(socket_path) = url_str.strip_prefix("unix://") {
        let socket_path = socket_path
            .strip_suffix("/v1/metrics")
            .unwrap_or(socket_path);
        return otel_metrics_endpoint_from_unix_socket(socket_path);
    }
    parse_uri(url_str.as_ref())
        .ok()
        .map(|url| Box::new(Endpoint::from_url(url)))
}

#[no_mangle]
pub unsafe extern "C" fn datadog_otel_metrics_endpoint_from_agent_url(
    url: CharSlice,
) -> std::option::Option<Box<Endpoint>> {
    let url_str = url.to_utf8_lossy();
    #[cfg(unix)]
    if let Some(socket_path) = url_str.strip_prefix("unix://") {
        return otel_metrics_endpoint_from_unix_socket(socket_path);
    }
    if url_str.starts_with("http") {
        let parsed = parse_uri(url_str.as_ref()).ok();
        let scheme = parsed
            .as_ref()
            .and_then(|u| u.scheme_str())
            .unwrap_or("http");
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
pub extern "C" fn ddog_normalize_process_tag_value(tag_value: CharSlice) -> *const c_char {
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
