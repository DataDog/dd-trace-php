pub mod telemetry;

use uuid::Uuid;
use ddcommon::container_id::{get_container_id, set_cgroup_file};
use ddcommon_ffi::CharSlice;

pub use ddcommon_ffi::*;
use ddcommon_ffi::slice::AsBytes;
pub use ddtelemetry_ffi::*;

#[no_mangle]
#[allow(non_upper_case_globals)]
pub static mut ddtrace_runtime_id: [u8; 16] = [0; 16];

#[must_use]
#[no_mangle]
pub unsafe extern "C" fn ddtrace_generate_runtime_id() {
    core::ptr::copy(Uuid::new_v4().as_bytes() as *const [u8; 16], &mut ddtrace_runtime_id, 1);
}

#[must_use]
#[no_mangle]
pub unsafe extern "C" fn ddtrace_format_runtime_id(buf: *mut u8) {
    Uuid::from_bytes_ref(&ddtrace_runtime_id).as_hyphenated().encode_lower(std::slice::from_raw_parts_mut(buf, 36));
}

#[must_use]
#[no_mangle]
pub extern "C" fn ddtrace_get_container_id() -> CharSlice<'static> {
    get_container_id().map_or(CharSlice::default(), CharSlice::from)
}

#[must_use]
#[no_mangle]
pub unsafe extern "C" fn ddtrace_set_container_cgroup_path(path: CharSlice) {
    set_cgroup_file(String::from(path.try_to_utf8().unwrap()))
}
