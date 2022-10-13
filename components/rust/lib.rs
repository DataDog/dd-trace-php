use ddcommon::container_id::{get_container_id, set_cgroup_file};
use ddcommon_ffi::CharSlice;

pub use ddcommon_ffi::*;
use ddcommon_ffi::slice::AsBytes;
pub use ddtelemetry_ffi::*;

#[must_use]
#[no_mangle]
pub unsafe extern "C" fn ddtrace_get_container_id() -> CharSlice<'static> {
    get_container_id().map_or(CharSlice::default(), CharSlice::from)
}

#[must_use]
#[no_mangle]
pub unsafe extern "C" fn ddtrace_set_container_cgroup_path(path: CharSlice) {
    set_cgroup_file(String::from(path.try_to_utf8().unwrap()))
}
