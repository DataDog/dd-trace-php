//! zend_string helpers - replaces zai_str_from_zstr.
//! Uses matrix offsets for universal PHP version support.

use crate::bindings::{zai_str, zend_string, ZaiStr};
use crate::matrix_entry;
use libc::size_t;

/// Converts zend_string to zai_str view using matrix offsets.
///
/// Replaces zai_str_from_zstr from zai_string. Caller must use the result
/// immediately (e.g. call `.into_string()`) before any operation that could
/// free the zend_string.
///
/// # Safety
/// - Must be called when module is registered (matrix_entry available).
/// - zstr must be a valid zend_string pointer or null.
#[inline]
pub(crate) unsafe fn zend_string_to_zai_str(zstr: Option<*mut zend_string>) -> zai_str<'static> {
    let Some(zstr) = zstr else {
        return ZaiStr::from_raw_parts(std::ptr::null(), 0);
    };
    if zstr.is_null() {
        return ZaiStr::from_raw_parts(std::ptr::null(), 0);
    }

    let entry = matrix_entry();
    let len_off = if entry.offsets.zend_string_len >= 0 {
        entry.offsets.zend_string_len as usize
    } else {
        16
    };
    let val_off = if entry.offsets.zend_string_val >= 0 {
        entry.offsets.zend_string_val as usize
    } else {
        24
    };

    let base = zstr as *mut u8;
    let len = *base.add(len_off).cast::<size_t>();
    let ptr = base.add(val_off).cast::<libc::c_char>();

    ZaiStr::from_raw_parts(ptr, len)
}
