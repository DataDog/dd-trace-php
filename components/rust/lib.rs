pub mod telemetry;

use uuid::Uuid;
use ddcommon::container_id::{get_container_id, set_cgroup_file};
use ddcommon_ffi::CharSlice;

pub use ddcommon_ffi::*;
use ddcommon_ffi::slice::AsBytes;
pub use ddtelemetry_ffi::*;

#[no_mangle]
#[allow(non_upper_case_globals)]
pub static mut ddtrace_runtime_id: Uuid = Uuid::from_bytes([0; 16]);

#[must_use]
#[no_mangle]
pub unsafe extern "C" fn ddtrace_generate_runtime_id() {
    ddtrace_runtime_id = Uuid::new_v4();
}

#[must_use]
#[no_mangle]
pub unsafe extern "C" fn ddtrace_format_runtime_id(buf: &mut [u8; 36]) {
    ddtrace_runtime_id.as_hyphenated().encode_lower(buf);
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


