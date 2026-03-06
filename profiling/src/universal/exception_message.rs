//! Pure Rust exception message extraction via runtime symbol resolution.
//! Exception message extraction using matrix/symbol resolution.

use crate::bindings::{zend_class_entry, zend_object, zend_string, zval, IS_OBJECT, IS_STRING};
use crate::universal::runtime::symbol_addr;
use libc::c_void;
use std::ptr;
use std::sync::OnceLock;

/// Wrapper for raw pointers in statics; *const T doesn't implement Send/Sync.
#[repr(transparent)]
struct SendSyncPtr<T>(*const T);
unsafe impl<T> Send for SendSyncPtr<T> {}
unsafe impl<T> Sync for SendSyncPtr<T> {}

/// Fallback when exception is invalid or message cannot be read.
const FALLBACK_MSG: &[u8] = b"(internal error retrieving exception for message)";
const FALLBACK_READ_MSG: &[u8] = b"(internal error reading exception message)";

/// Property name for the message field on Exception/Error.
const MESSAGE_PROP: &[u8] = b"message";

type InstanceofFn = unsafe extern "C" fn(*const zend_class_entry, *const zend_class_entry) -> bool;

/// PHP 8+: zend_read_property(scope, zend_object*, name, len, silent, rv)
type ReadPropertyFn = unsafe extern "C" fn(
    *const zend_class_entry,
    *mut zend_object,
    *const u8,
    usize,
    bool,
    *mut zval,
) -> *mut zval;

/// PHP 7: zend_read_property(scope, zval_object*, name, len, silent, rv)
/// The second parameter is a zval* wrapping the object rather than a bare zend_object*.
type ReadPropertyPhp7Fn = unsafe extern "C" fn(
    *const zend_class_entry,
    *mut zval,
    *const u8,
    usize,
    bool,
    *mut zval,
) -> *mut zval;

type ZendStringInitInternedFn = unsafe extern "C" fn(*const u8, usize, bool) -> *mut zend_string;

static INSTANCEOF: OnceLock<InstanceofFn> = OnceLock::new();
static READ_PROPERTY: OnceLock<ReadPropertyFn> = OnceLock::new();
static READ_PROPERTY_PHP7: OnceLock<ReadPropertyPhp7Fn> = OnceLock::new();
static ZEND_CE_THROWABLE: OnceLock<SendSyncPtr<zend_class_entry>> = OnceLock::new();
static ZEND_CE_EXCEPTION: OnceLock<SendSyncPtr<zend_class_entry>> = OnceLock::new();
static ZEND_CE_ERROR: OnceLock<SendSyncPtr<zend_class_entry>> = OnceLock::new();
static ZEND_STRING_INIT_INTERNED: OnceLock<ZendStringInitInternedFn> = OnceLock::new();

fn resolve_symbols() -> bool {
    let _ = INSTANCEOF.get_or_init(|| {
        let addr = symbol_addr("instanceof_function_slow");
        if addr.is_null() {
            return dummy_instanceof;
        }
        unsafe { std::mem::transmute(addr) }
    });

    // zend_read_property exists in both PHP 7 and 8; only its ABI differs.
    // Resolve once and store under both typed aliases.
    let rp_addr = symbol_addr("zend_read_property");
    let _ = READ_PROPERTY.get_or_init(move || {
        if rp_addr.is_null() {
            return dummy_read_property;
        }
        unsafe { std::mem::transmute(rp_addr) }
    });
    let _ = READ_PROPERTY_PHP7.get_or_init(move || {
        if rp_addr.is_null() {
            return dummy_read_property_php7;
        }
        unsafe { std::mem::transmute(rp_addr) }
    });

    let _ = ZEND_CE_THROWABLE
        .get_or_init(|| SendSyncPtr(symbol_addr("zend_ce_throwable") as *const zend_class_entry));
    let _ = ZEND_CE_EXCEPTION
        .get_or_init(|| SendSyncPtr(symbol_addr("zend_ce_exception") as *const zend_class_entry));
    let _ = ZEND_CE_ERROR
        .get_or_init(|| SendSyncPtr(symbol_addr("zend_ce_error") as *const zend_class_entry));

    let _ = ZEND_STRING_INIT_INTERNED.get_or_init(|| {
        // zend_string_init_interned is a function pointer variable; dereference to get the fn
        let var_addr = symbol_addr("zend_string_init_interned");
        if var_addr.is_null() {
            return dummy_zend_string_init_interned;
        }
        let fn_ptr = unsafe { *(var_addr as *const *mut c_void) };
        if fn_ptr.is_null() {
            return dummy_zend_string_init_interned;
        }
        unsafe { std::mem::transmute(fn_ptr) }
    });

    let ce_throwable = ZEND_CE_THROWABLE.get().map(|p| p.0).unwrap_or(ptr::null());
    let ce_exception = ZEND_CE_EXCEPTION.get().map(|p| p.0).unwrap_or(ptr::null());
    let ce_error = ZEND_CE_ERROR.get().map(|p| p.0).unwrap_or(ptr::null());
    let instanceof = INSTANCEOF.get().copied().unwrap_or(dummy_instanceof);
    let read_resolved =
        READ_PROPERTY.get().copied().unwrap_or(dummy_read_property) != dummy_read_property;
    let init_interned = ZEND_STRING_INIT_INTERNED
        .get()
        .copied()
        .unwrap_or(dummy_zend_string_init_interned);

    !ce_throwable.is_null()
        && !ce_exception.is_null()
        && !ce_error.is_null()
        && instanceof != dummy_instanceof
        && read_resolved
        && init_interned != dummy_zend_string_init_interned
}

unsafe extern "C" fn dummy_instanceof(
    _: *const zend_class_entry,
    _: *const zend_class_entry,
) -> bool {
    false
}

unsafe extern "C" fn dummy_read_property(
    _: *const zend_class_entry,
    _: *mut zend_object,
    _: *const u8,
    _: usize,
    _: bool,
    _: *mut zval,
) -> *mut zval {
    ptr::null_mut()
}

unsafe extern "C" fn dummy_read_property_php7(
    _: *const zend_class_entry,
    _: *mut zval,
    _: *const u8,
    _: usize,
    _: bool,
    _: *mut zval,
) -> *mut zval {
    ptr::null_mut()
}

unsafe extern "C" fn dummy_zend_string_init_interned(
    _: *const u8,
    _: usize,
    _: bool,
) -> *mut zend_string {
    ptr::null_mut()
}

/// Extract the message from a Throwable exception object.
/// Returns a zend_string (never null) - either the message or a fallback.
/// Caller must only read the bytes; does not take ownership.
///
/// # Safety
/// - `ex` must be a valid zend_object* for a Throwable instance, or null.
/// - Must be called from a request context (e.g. exception hook).
pub unsafe fn exception_message(ex: *mut zend_object) -> *mut zend_string {
    if ex.is_null() {
        return fallback_string(FALLBACK_MSG);
    }

    if !resolve_symbols() {
        return fallback_string(FALLBACK_MSG);
    }

    let ce = (*ex).ce;
    let instanceof = INSTANCEOF.get().copied().unwrap_or(dummy_instanceof);
    let ce_throwable = ZEND_CE_THROWABLE.get().map(|p| p.0).unwrap_or(ptr::null());
    let ce_exception = ZEND_CE_EXCEPTION.get().map(|p| p.0).unwrap_or(ptr::null());
    let ce_error = ZEND_CE_ERROR.get().map(|p| p.0).unwrap_or(ptr::null());

    if ce_throwable.is_null() || !instanceof(ce, ce_throwable) {
        return fallback_string(FALLBACK_MSG);
    }

    let base = if instanceof(ce, ce_exception) {
        ce_exception
    } else {
        ce_error
    };

    let mut rv: zval = std::mem::zeroed();

    // PHP 7: zend_read_property takes *mut zval wrapping the object.
    // PHP 8+: zend_read_property takes *mut zend_object directly.
    let api_no = crate::matrix_entry().key.api_no;
    let msg = if api_no < 420200930 {
        let read = READ_PROPERTY_PHP7
            .get()
            .copied()
            .unwrap_or(dummy_read_property_php7);
        let mut obj_zval: zval = std::mem::zeroed();
        obj_zval.value.obj = ex;
        obj_zval.set_type(IS_OBJECT);
        read(
            base,
            &mut obj_zval,
            MESSAGE_PROP.as_ptr(),
            MESSAGE_PROP.len(),
            true,
            &mut rv,
        )
    } else {
        let read = READ_PROPERTY.get().copied().unwrap_or(dummy_read_property);
        read(
            base,
            ex,
            MESSAGE_PROP.as_ptr(),
            MESSAGE_PROP.len(),
            true,
            &mut rv,
        )
    };

    if msg.is_null() {
        return fallback_string(FALLBACK_READ_MSG);
    }

    // Z_TYPE_P(msg) == IS_STRING
    let type_ = (*msg).get_type();
    if type_ != IS_STRING as u8 {
        return fallback_string(FALLBACK_READ_MSG);
    }

    let str_ptr = (*msg).value.str_;
    if str_ptr.is_null() {
        return fallback_string(FALLBACK_READ_MSG);
    }

    str_ptr
}

fn fallback_string(bytes: &[u8]) -> *mut zend_string {
    let init = ZEND_STRING_INIT_INTERNED
        .get()
        .copied()
        .unwrap_or(dummy_zend_string_init_interned);
    if init == dummy_zend_string_init_interned {
        // zend_string_init_interned unresolved - use alloc fallback.
        fallback_string_alloc(bytes)
    } else {
        let result = unsafe { init(bytes.as_ptr(), bytes.len(), true) };
        if result.is_null() {
            fallback_string_alloc(bytes)
        } else {
            result
        }
    }
}

/// Static fallback when malloc fails. Layout matches zend_string (len=16, val=24).
/// Longest message is FALLBACK_MSG (46 bytes).
#[repr(C)]
struct StaticZendStringFallback {
    _gc: [u8; 8],
    _h: [u8; 8],
    len: usize,
    val: [u8; 48],
}

const FALLBACK_VAL: [u8; 48] = {
    let mut v = [0u8; 48];
    let src = b"(internal error retrieving exception for message)";
    let mut i = 0;
    while i < 46 {
        v[i] = src[i];
        i += 1;
    }
    v
};

static FALLBACK_STATIC: StaticZendStringFallback = StaticZendStringFallback {
    _gc: [1, 0, 0, 0, 0, 0, 0, 0], // refcount=1
    _h: [0; 8],
    len: FALLBACK_MSG.len(),
    val: FALLBACK_VAL,
};

/// Allocate a fake zend_string with libc malloc when PHP allocator unavailable.
/// Layout: gc (8) + h (8) + len (8) + val (flex). Matrix: len=16, val=24.
fn fallback_string_alloc(bytes: &[u8]) -> *mut zend_string {
    let header_size = 24;
    let total = header_size + bytes.len() + 1;
    let ptr = unsafe { libc::malloc(total) };
    if ptr.is_null() {
        return ptr::addr_of!(FALLBACK_STATIC) as *mut zend_string;
    }
    let base = ptr as *mut u8;
    unsafe {
        *(base.add(0) as *mut u32) = 1;
        *(base.add(4) as *mut u32) = 0;
        *(base.add(8) as *mut u64) = 0;
        *(base.add(16) as *mut usize) = bytes.len();
        std::ptr::copy_nonoverlapping(bytes.as_ptr(), base.add(24), bytes.len());
        *base.add(24 + bytes.len()) = 0;
    }
    base as *mut zend_string
}
