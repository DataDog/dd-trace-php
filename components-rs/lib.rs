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

use libdd_common::entity_id::{get_container_id, set_cgroup_file};
use http::uri::{PathAndQuery, Scheme};
use http::Uri;
use std::borrow::Cow;
use std::ffi::{c_char, OsStr};
#[cfg(unix)]
use std::path::Path;
use std::ptr::null_mut;
use uuid::Uuid;

pub use libdd_crashtracker_ffi::*;
pub use libdd_library_config_ffi::*;
pub use datadog_sidecar_ffi::*;
use libdd_common::{parse_uri, Endpoint};
#[cfg(unix)]
use libdd_common::connector::uds::socket_path_to_uri;
use libdd_common_ffi::slice::AsBytes;
pub use libdd_common_ffi::*;
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

#[cfg(unix)]
fn otel_traces_endpoint_from_unix_socket(_socket_path: &str) -> std::option::Option<Box<Endpoint>> {
    socket_path_to_uri(Path::new(_socket_path)).ok().and_then(|uri| {
        let mut parts = uri.into_parts();
        parts.path_and_query = Some(PathAndQuery::from_static("/v1/traces"));
        Uri::from_parts(parts)
            .ok()
            .map(|url| Box::new(Endpoint::from_url(url)))
    })
}

/// Builds an OTLP traces endpoint from an explicit, full endpoint URL, used
/// as-is (mirrors `datadog_otel_metrics_endpoint_from_url`).
#[no_mangle]
pub unsafe extern "C" fn datadog_otel_traces_endpoint_from_url(url: CharSlice) -> std::option::Option<Box<Endpoint>> {
    let url_str = url.to_utf8_lossy();
    #[cfg(unix)]
    if let Some(socket_path) = url_str.strip_prefix("unix://") {
        let socket_path = socket_path.strip_suffix("/v1/traces").unwrap_or(socket_path);
        return otel_traces_endpoint_from_unix_socket(socket_path);
    }
    parse_uri(url_str.as_ref())
        .ok()
        .map(|url| Box::new(Endpoint::from_url(url)))
}

/// Builds an OTLP traces endpoint from the agent URL by reusing the agent
/// host and forcing the standard OTLP http port and `/v1/traces` path
/// (mirrors `datadog_otel_metrics_endpoint_from_agent_url`).
#[no_mangle]
pub unsafe extern "C" fn datadog_otel_traces_endpoint_from_agent_url(url: CharSlice) -> std::option::Option<Box<Endpoint>> {
    let url_str = url.to_utf8_lossy();
    #[cfg(unix)]
    if let Some(socket_path) = url_str.strip_prefix("unix://") {
        return otel_traces_endpoint_from_unix_socket(socket_path);
    }
    if url_str.starts_with("http") {
        let parsed = parse_uri(url_str.as_ref()).ok();
        let scheme = parsed.as_ref().and_then(|u| u.scheme_str()).unwrap_or("http");
        let host = parsed
            .as_ref()
            .and_then(|u| u.host())
            .unwrap_or("localhost");
        parse_uri(&format!("{}://{}:4318/v1/traces", scheme, host))
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

#[cfg(test)]
mod otel_traces_endpoint_tests {
    use super::*;
    use libdd_common_ffi::CharSlice;

    #[test]
    fn traces_endpoint_from_explicit_url_used_as_is() {
        let ep = unsafe {
            datadog_otel_traces_endpoint_from_url(CharSlice::from("http://collector:4318/v1/traces"))
        }
        .expect("endpoint should parse");
        assert_eq!(ep.url.to_string(), "http://collector:4318/v1/traces");
    }

    #[test]
    fn traces_endpoint_from_agent_url_uses_4318_and_v1_traces() {
        let ep = unsafe {
            datadog_otel_traces_endpoint_from_agent_url(CharSlice::from("http://agent-host:8126"))
        }
        .expect("endpoint should be derived from agent url");
        // Port forced to 4318 and /v1/traces path appended; host preserved.
        assert_eq!(ep.url.to_string(), "http://agent-host:4318/v1/traces");
    }

    #[test]
    fn traces_endpoint_from_agent_url_defaults_host_when_missing() {
        let ep = unsafe {
            datadog_otel_traces_endpoint_from_agent_url(CharSlice::from("http://"))
        };
        // Falls back to localhost when the agent URL has no host.
        if let Some(ep) = ep {
            assert_eq!(ep.url.to_string(), "http://localhost:4318/v1/traces");
        }
    }
}
