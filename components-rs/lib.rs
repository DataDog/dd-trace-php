// Remove with move to Rust 2024 edition.
#![allow(static_mut_refs)]
// The former components and profiler crates carried substantial pre-existing Clippy debt.
// Keep consolidation from turning those warnings into unrelated migration work.
#![allow(
    clippy::bool_assert_comparison,
    clippy::collapsible_else_if,
    clippy::from_over_into,
    clippy::manual_retain,
    clippy::map_clone,
    clippy::missing_safety_doc,
    clippy::missing_transmute_annotations,
    clippy::needless_borrow,
    clippy::needless_lifetimes,
    clippy::needless_return,
    clippy::not_unsafe_ptr_arg_deref,
    clippy::ptr_arg,
    clippy::redundant_closure,
    clippy::too_many_arguments,
    clippy::transmute_ptr_to_ref,
    clippy::unnecessary_map_or,
    clippy::unused_unit,
    clippy::useless_asref
)]

/// Tracer-specific Rust facade, colocated with the C tracer sources.
#[cfg(feature = "tracer")]
#[path = "../tracer/mod.rs"]
pub mod tracer;

/// Standalone profiler implementation, retained in its existing source tree.
#[cfg(feature = "profiling")]
#[path = "../profiling/src/lib.rs"]
pub mod profiling;

#[cfg(not(standalone_profiler))]
pub mod agent_info;
#[cfg(not(standalone_profiler))]
pub mod bytes;
#[cfg(not(standalone_profiler))]
pub mod config;
#[cfg(not(standalone_profiler))]
pub mod ffe;
#[cfg(not(standalone_profiler))]
pub mod log;
#[cfg(not(standalone_profiler))]
pub mod remote_config;
#[cfg(not(standalone_profiler))]
pub mod sidecar;
#[cfg(not(standalone_profiler))]
pub mod stats;
#[cfg(not(standalone_profiler))]
pub mod telemetry;
#[cfg(not(standalone_profiler))]
pub mod trace_filter;

// A standalone profiler must retain the existing profiler-only ABI and size.
// Cargo's `cdylib` keeps every `no_mangle` common export alive, even though the
// profiler does not use them, so omit those exports only in profiler-only builds.
#[cfg(not(standalone_profiler))]
#[rustfmt::skip]
mod common_exports {
#[cfg(unix)]
use datadog_sidecar::crashtracker::crashtracker_receiver_request_bytes;
pub use datadog_sidecar_ffi::*;
use http::uri::{PathAndQuery, Scheme};
use http::Uri;
#[cfg(unix)]
use libdd_common::connector::uds::socket_path_to_uri;
use libdd_common::entity_id::{get_container_id, set_cgroup_file};
use libdd_common::{parse_uri, Endpoint};
use libdd_common_ffi::slice::AsBytes;
pub use libdd_common_ffi::*;
pub use libdd_crashtracker_ffi::*;
pub use libdd_library_config_ffi::*;
pub use libdd_telemetry_ffi::*;
use std::borrow::Cow;
use std::ffi::{c_char, OsStr};
#[cfg(unix)]
use std::path::Path;
use std::ptr::null_mut;
use uuid::Uuid;

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

#[cfg(target_os = "linux")]
fn char_slice_string(value: CharSlice<'_>) -> String {
    value.to_utf8_lossy().into_owned()
}

#[cfg(target_os = "linux")]
fn hostname() -> String {
    let max_len = unsafe { libc::sysconf(libc::_SC_HOST_NAME_MAX) };
    let max_len = usize::try_from(max_len).unwrap_or(255);
    let mut buffer = vec![0; max_len.saturating_add(1)];

    if unsafe { libc::gethostname(buffer.as_mut_ptr().cast(), buffer.len()) } != 0 {
        return String::new();
    }

    let len = buffer
        .iter()
        .position(|&byte| byte == 0)
        .unwrap_or(buffer.len());
    String::from_utf8_lossy(&buffer[..len]).into_owned()
}

/// Publish or update dd-trace-php's standard Linux OTel Process Context.
#[cfg(target_os = "linux")]
#[no_mangle]
pub extern "C" fn datadog_publish_otel_process_context(process_tags: CharSlice<'_>) -> bool {
    use libdd_library_config::otel_process_ctx;
    use libdd_library_config::tracer_metadata::{ThreadLocalMetadata, TracerMetadata};

    let metadata = TracerMetadata {
        // Safety: the runtime ID is only mutated from single-threaded contexts.
        runtime_id: Some(unsafe { datadog_runtime_id.as_hyphenated().to_string() }),
        tracer_language: "php".to_owned(),
        tracer_version: include_str!("../VERSION").trim().to_owned(),
        hostname: hostname(),
        process_tags: Some(char_slice_string(process_tags)),
        container_id: get_container_id().map(str::to_owned),
        threadlocal_metadata: Some(ThreadLocalMetadata {
            attribute_keys: vec![
                "service.name".to_owned(),
                "deployment.environment.name".to_owned(),
                "service.version".to_owned(),
                "thread.id".to_owned(),
            ],
            ..Default::default()
        }),
        ..Default::default()
    };

    otel_process_ctx::publish(&metadata.to_otel_process_ctx()).is_ok()
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

/// Initialize crashtracking, selecting the receiver strategy for this process:
///   - Linux, sidecar host (`master_pid == getpid()`): the in-process thread-mode sidecar can't
///     serve its own crash, so spawn a fork+exec subprocess receiver (like the standalone daemon),
///     resolving frames there since a crashing process can't reliably symbolize itself.
///   - Linux, worker/collector: connect to the sidecar IPC socket and upgrade it to a crashtracker
///     receiver on crash (`SOCK_SEQPACKET` + `enter_crashtracker_receiver`), streaming the report
///     over that single socket and resolving frames in-process.
///   - other unix (macOS): no sidecar upgrade; the default connector reaches the socket path.
///
/// `master_pid` is the thread-mode master listener PID (0 if none): it keys the IPC socket and, on
/// Linux, distinguishes the host from a worker.
///
/// # Safety
/// `endpoint` must point to a valid `Endpoint`; `metadata`'s borrowed strings/tags must outlive the
/// call (they are copied into owned storage before it returns).
#[cfg(unix)]
#[no_mangle]
#[allow(clippy::missing_safety_doc)]
pub unsafe extern "C" fn datadog_crashtracker_init(
    endpoint: &Endpoint,
    metadata: Metadata,
    master_pid: i32,
) -> MaybeError {
    use libdd_crashtracker::{CrashtrackerConfiguration, StacktraceCollection};

    #[cfg(not(target_os = "linux"))]
    let _ = master_pid;

    let result = (|| -> anyhow::Result<()> {
        let metadata: libdd_crashtracker::Metadata = metadata.try_into()?;

        let mut builder = CrashtrackerConfiguration::builder()
            .collect_all_threads(true)
            .timeout(std::time::Duration::from_millis(5000))
            .endpoint_use_system_resolver(endpoint.use_system_resolver)
            .endpoint_url(&endpoint.url.to_string());
        if let Some(api_key) = endpoint.api_key.as_deref() {
            builder = builder.endpoint_api_key(api_key);
        }
        if let Some(test_token) = endpoint.test_token.as_deref() {
            builder = builder.endpoint_test_token(test_token);
        }
        if endpoint.timeout_ms != 0 {
            builder = builder.endpoint_timeout_ms(endpoint.timeout_ms);
        }

        #[cfg(target_os = "linux")]
        {
            // Worker/collector: open a fresh connection to the sidecar IPC socket, upgrade it with
            // the SEQPACKET connector, and resolve frames in-process.
            if master_pid == 0 || master_pid != std::process::id() as i32 {
                let socket_path = datadog_sidecar::crashtracker::crashtracker_ipc_socket_path(
                    master_pid as u32,
                    datadog_sidecar::config::FromEnv::ipc_mode(),
                );
                // Prime the request bytes outside the crash handler so the connector never
                // allocates in signal context.
                let _ = crashtracker_receiver_request_bytes();
                let config = builder
                    .resolve_frames(StacktraceCollection::EnabledWithInprocessSymbols)
                    .unix_socket_path(socket_path.to_string_lossy().into_owned())
                    .unix_socket_connector(
                        datadog_sidecar::crashtracker::connect_to_sidecar_receiver,
                    )
                    .build()?;
                return libdd_crashtracker::init(
                    config,
                    libdd_crashtracker::CrashtrackerReceiverConfig::default(),
                    metadata,
                );
            }
            // Thread-mode host: its in-process sidecar can't serve its own crash, so spawn a
            // transient fork+exec subprocess receiver and resolve frames there.
            let config = builder
                .resolve_frames(StacktraceCollection::EnabledWithSymbolsInReceiver)
                .build()?;
            let receiver_config = datadog_sidecar::build_crashtracker_receiver_config(None, None)?;
            libdd_crashtracker::init(config, receiver_config, metadata)
        }

        // macOS can't open a fresh SOCK_SEQPACKET connection signal-safely, so reuse the
        // already-open sidecar fd and upgrade it at crash time (no-op if there's no connection).
        // The path is a placeholder the connector ignores; it just has to be non-empty so the
        // crashtracker takes the connector path.
        #[cfg(target_os = "macos")]
        {
            // Prime the request bytes outside the crash handler so the connector never
            // allocates in signal context.
            let _ = crashtracker_receiver_request_bytes();
            let config = builder
                .resolve_frames(StacktraceCollection::EnabledWithInprocessSymbols)
                .unix_socket_path("datadog-sidecar-crashtracker".to_string())
                .unix_socket_connector(reuse_sidecar_fd_connector)
                .build()?;
            libdd_crashtracker::init(
                config,
                libdd_crashtracker::CrashtrackerReceiverConfig::default(),
                metadata,
            )
        }

        #[cfg(not(any(target_os = "linux", target_os = "macos")))]
        {
            let _ = (builder, metadata);
            Ok(())
        }
    })();
    match result {
        Ok(()) => MaybeError::None,
        Err(e) => MaybeError::Some(Error::from(format!("{e:?}"))),
    }
}

/// On macos we cannot easily create a new signal safe connection to the sidecar, so we reuse the
/// already open fd from datadog_sidecar_for_signal.
#[cfg(target_os = "macos")]
fn reuse_sidecar_fd_connector(_unix_socket_path: &str) -> std::os::fd::RawFd {
    // Resolve the optional C-side transport dynamically so common-only builds remain loadable
    // without the tracer C module.
    let symbol = unsafe { libc::dlsym(libc::RTLD_DEFAULT, c"datadog_sidecar_for_signal".as_ptr()) }
        as *const *mut std::ffi::c_void;
    if symbol.is_null() {
        return -1;
    }

    // Best-effort, signal context: read the transport pointer and get its current fd via
    // SidecarTransport::as_raw_fd (which uses get_mut, never locking). Going through the raw
    // pointer knowingly bypasses aliasing checks — the crashing thread is the only realistic
    // accessor.
    let transport = unsafe { *symbol } as *mut datadog_sidecar::service::blocking::SidecarTransport;
    if transport.is_null() {
        return -1;
    }
    let fd = unsafe { (*transport).as_raw_fd() };
    let bytes = crashtracker_receiver_request_bytes();
    let sent = unsafe { libc::send(fd, bytes.as_ptr().cast::<libc::c_void>(), bytes.len(), 0) };
    if sent == bytes.len() as isize {
        fd
    } else {
        -1
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
}

#[cfg(not(standalone_profiler))]
pub use common_exports::*;
