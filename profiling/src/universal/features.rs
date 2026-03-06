//! Feature accessors — thin delegators to MatrixEntry methods.
//! All detection is based on the PHP API number, not runtime symbol discovery.

use crate::matrix_entry;

/// True if PHP was built with ZTS. From matrix entry.
pub fn is_zts() -> bool {
    matrix_entry().is_zts()
}

/// True if zend_mm_set_custom_handlers_ex is available (PHP 8.4+).
pub fn has_zend_mm_set_custom_handlers_ex() -> bool {
    matrix_entry().has_zend_mm_set_custom_handlers_ex()
}

/// True if zend_accel_schedule_restart_hook is available (PHP 8.4+).
pub fn has_opcache_restart_hook() -> bool {
    matrix_entry().has_opcache_restart_hook()
}

/// True if zend_gc_get_status is available (PHP 7.4+).
pub fn has_gc_status() -> bool {
    matrix_entry().has_gc_status()
}

/// True if fibers are supported — eg.active_fiber is present (PHP 8.1+).
pub fn has_fibers() -> bool {
    matrix_entry().has_fibers()
}

/// True if zend_observer_error_register is available (PHP 8.0+).
pub fn has_zend_error_observer() -> bool {
    matrix_entry().has_zend_error_observer()
}

/// True when the error observer callback receives `const char*` for the filename
/// (PHP 8.0–8.1). On PHP 8.2+ the parameter is `zend_string*` instead.
pub fn zend_error_observer_has_cstr_filename() -> bool {
    matrix_entry().zend_error_observer_has_cstr_filename()
}
