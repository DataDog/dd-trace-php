#![allow(internal_features)]
#![feature(allow_internal_unstable)]
#![feature(linkage)]

pub mod log;
pub mod remote_config;
pub mod sidecar;
pub mod telemetry;
pub mod bytes;

use std::borrow::Cow;
use std::ffi::c_char;
use std::ptr::null_mut;
use http::Uri;
use http::uri::Scheme;
use ddcommon::entity_id::{get_container_id, set_cgroup_file};
use uuid::Uuid;

pub use datadog_crashtracker_ffi::*;
pub use datadog_sidecar_ffi::*;
use ddcommon::{parse_uri, Endpoint};
use ddcommon_ffi::slice::AsBytes;
pub use ddcommon_ffi::*;
pub use ddtelemetry_ffi::*;
pub use datadog_library_config_ffi::*;

#[no_mangle]
#[allow(non_upper_case_globals)]
pub static mut ddtrace_runtime_id: Uuid = Uuid::nil();

/// # Safety
/// Must be called from a single-threaded context, such as MINIT.
#[no_mangle]
pub unsafe extern "C" fn ddtrace_generate_runtime_id() {
    ddtrace_runtime_id = Uuid::new_v4();
}

#[no_mangle]
pub extern "C" fn ddtrace_format_runtime_id(buf: &mut [u8; 36]) {
    // Safety: ddtrace_runtime_id is only supposed to be mutated from single-
    // threaded contexts, so reads should always be safe.
    unsafe { ddtrace_runtime_id.as_hyphenated().encode_lower(buf) };
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
pub unsafe extern "C" fn ddtrace_strip_invalid_utf8(input: *const c_char, len: *mut usize) -> *mut c_char {
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
pub unsafe extern "C" fn ddtrace_parse_agent_url(url: CharSlice) -> std::option::Option<Box<Endpoint>> {
    parse_uri(url.to_utf8_lossy().as_ref())
        .ok()
        .and_then(|url| if url.authority().is_none() { None } else { Some(url) })
        .map(|mut url| {
            if url.scheme().is_none() {
                let mut parts = url.into_parts();
                parts.scheme = Some(Scheme::HTTP);
                url = Uri::from_parts(parts).unwrap();
            }
            Box::new(Endpoint::from_url(url))
        })
}

// Hack: Without this, the PECL build of the tracer does not contain the ddog_library_* functions
// It works well without in the "normal" build
#[no_mangle]
pub extern "C" fn ddog_library_configurator_new_dummy(
    debug_logs: bool,
    language: CharSlice,
) -> Box<Configurator> {
    datadog_library_config_ffi::ddog_library_configurator_new(debug_logs, language)
}

// Starting with https://github.com/rust-lang/rust/commit/7f74c894b0e31f370b5321d94f2ca2830e1d30fd
// rust assumes posix_spawn_file_actions_addchdir_np exists. Thus we need to polyfill it here.
#[no_mangle]
#[cfg(all(target_os = "linux", target_env = "musl"))]
pub unsafe extern "C" fn posix_spawn_file_actions_addchdir_np(file_actions: *mut libc::c_void, path: *const libc::c_char) -> libc::c_int {
    let sym = std::mem::transmute::<*mut libc::c_void, std::option::Option<extern "C" fn(*mut libc::c_void, *const libc::c_char) -> libc::c_int>>(libc::dlsym(null_mut(), c"posix_spawn_file_actions_addchdir_np".as_ptr()));
    if let Some(sym) = sym {
        sym(file_actions, path)
    } else {
        -libc::ENOSYS
    }
}
