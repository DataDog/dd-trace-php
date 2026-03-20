pub mod sidecar_ffi;

// Stub implementations of the sidecar symbols for `cargo test`.
// In production the real symbols are provided by datadog-ipc-helper at
// dlopen time.  The cdylib has no stubs (--allow-shlib-undefined covers it);
// the test executable needs concrete definitions at link time because lld on
// musl does not allow undefined symbols in executables.
#[cfg(test)]
mod test_stubs {
    use super::sidecar_ffi::*;

    #[no_mangle]
    extern "C" fn ddog_Error_drop(_: *mut ddog_Error) {}
    #[no_mangle]
    extern "C" fn ddog_Error_message(_: *const ddog_Error) -> ddog_CharSlice {
        ddog_CharSlice { ptr: std::ptr::null(), len: 0 }
    }
    #[no_mangle]
    extern "C" fn ddog_MaybeError_drop(_: ddog_MaybeError) {}
    #[no_mangle]
    extern "C" fn ddog_set_rc_notify_fn(_: ddog_InProcNotifyFn) {}
    #[no_mangle]
    unsafe extern "C" fn ddog_remote_config_path(
        _: *const ddog_ConfigInvariants,
        _: *const ddog_Arc_Target,
    ) -> *mut std::ffi::c_char {
        std::ptr::null_mut()
    }
    #[no_mangle]
    extern "C" fn ddog_remote_config_path_free(_: *mut std::ffi::c_char) {}
    #[no_mangle]
    unsafe extern "C" fn ddog_sidecar_connect(
        _: *mut *mut ddog_SidecarTransport,
    ) -> ddog_MaybeError {
        ddog_MaybeError {
            tag: ddog_Option_Error_Tag_DDOG_OPTION_ERROR_NONE_ERROR,
            __bindgen_anon_1: unsafe { std::mem::zeroed() },
        }
    }
    #[no_mangle]
    extern "C" fn ddog_sidecar_transport_drop(_: *mut ddog_SidecarTransport) {}
    #[no_mangle]
    unsafe extern "C" fn ddog_sidecar_ping(
        _: *mut *mut ddog_SidecarTransport,
    ) -> ddog_MaybeError {
        ddog_MaybeError {
            tag: ddog_Option_Error_Tag_DDOG_OPTION_ERROR_NONE_ERROR,
            __bindgen_anon_1: unsafe { std::mem::zeroed() },
        }
    }
    #[no_mangle]
    unsafe extern "C" fn ddog_sidecar_enqueue_telemetry_log(
        _: ddog_CharSlice, _: ddog_CharSlice, _: ddog_CharSlice, _: ddog_CharSlice,
        _: ddog_CharSlice, _: ddog_LogLevel, _: ddog_CharSlice,
        _: *mut ddog_CharSlice, _: *mut ddog_CharSlice, _: bool,
    ) -> ddog_MaybeError {
        ddog_MaybeError {
            tag: ddog_Option_Error_Tag_DDOG_OPTION_ERROR_NONE_ERROR,
            __bindgen_anon_1: unsafe { std::mem::zeroed() },
        }
    }
    #[no_mangle]
    unsafe extern "C" fn ddog_sidecar_enqueue_telemetry_point(
        _: ddog_CharSlice, _: ddog_CharSlice, _: ddog_CharSlice, _: ddog_CharSlice,
        _: ddog_CharSlice, _: f64, _: *mut ddog_CharSlice,
    ) -> ddog_MaybeError {
        ddog_MaybeError {
            tag: ddog_Option_Error_Tag_DDOG_OPTION_ERROR_NONE_ERROR,
            __bindgen_anon_1: unsafe { std::mem::zeroed() },
        }
    }
    #[no_mangle]
    unsafe extern "C" fn ddog_sidecar_enqueue_telemetry_metric(
        _: ddog_CharSlice, _: ddog_CharSlice, _: ddog_CharSlice, _: ddog_CharSlice,
        _: ddog_CharSlice, _: ddog_MetricType, _: ddog_MetricNamespace,
    ) -> ddog_MaybeError {
        ddog_MaybeError {
            tag: ddog_Option_Error_Tag_DDOG_OPTION_ERROR_NONE_ERROR,
            __bindgen_anon_1: unsafe { std::mem::zeroed() },
        }
    }
}

