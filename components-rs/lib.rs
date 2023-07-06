pub mod telemetry;

use ddcommon::container_id::{get_container_id, set_cgroup_file};
use ddcommon_ffi::CharSlice;
use uuid::Uuid;

pub use datadog_sidecar_ffi::*;
use ddcommon_ffi::slice::AsBytes;
pub use ddcommon_ffi::*;
pub use ddtelemetry_ffi::*;

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
