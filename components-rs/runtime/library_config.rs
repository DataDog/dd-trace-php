// Stable configuration is shared runtime functionality, not a sidecar service.
pub use libdd_library_config_ffi::*;

use libdd_common_ffi::CharSlice;

// Retain the configuration FFI in PECL builds, preserving the existing C ABI.
// C can resolve these functions with dlsym, so retain each codegen unit explicitly.
#[no_mangle]
pub extern "C" fn ddog_library_configurator_new_dummy(
    debug_logs: bool,
    language: CharSlice,
) -> Box<Configurator> {
    std::hint::black_box([
        ddog_library_configurator_with_local_path as *const (),
        ddog_library_configurator_with_fleet_path as *const (),
        ddog_library_configurator_with_detect_process_info as *const (),
        ddog_library_configurator_get as *const (),
        ddog_library_config_source_to_string as *const (),
        ddog_library_config_drop as *const (),
        ddog_library_configurator_drop as *const (),
        libdd_common_ffi::ddog_Error_drop as *const (),
    ]);
    ddog_library_configurator_new(debug_logs, language)
}
