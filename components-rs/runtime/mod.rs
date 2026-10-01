// PHP-facing services shared by tracer builds and standalone profiling.
// These do not depend on or start the sidecar. In SSI they are defined only in
// libdatadog_php.so; the PHP-version-specific extension imports their C ABI.

pub mod log;

/// Check that a configuration value can be represented as a UTF-8 PHP string.
///
/// # Safety
/// `bytes` must point to `len` readable bytes unless `len` is zero.
#[no_mangle]
pub unsafe extern "C" fn datadog_bytes_are_valid_utf8(bytes: *const u8, len: usize) -> bool {
    if len == 0 {
        return true;
    }
    std::str::from_utf8(std::slice::from_raw_parts(bytes, len)).is_ok()
}
