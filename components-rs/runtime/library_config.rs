// Stable configuration is shared runtime functionality, not a sidecar service.
pub use libdd_library_config_ffi::*;

use libdd_common_ffi::CharSlice;

// Retain the configuration FFI in PECL builds, preserving the existing C ABI.
#[no_mangle]
pub extern "C" fn ddog_library_configurator_new_dummy(
    debug_logs: bool,
    language: CharSlice,
) -> Box<Configurator> {
    ddog_library_configurator_new(debug_logs, language)
}
