//! Definitions for interacting with the profiler from a C API, such as the
//! ddtrace extension.

use crate::bindings::{zend_function_entry, zend_string, ZaiStr};
use crate::runtime_id;
use crate::universal::runtime;
use libc::c_long;
use std::sync::OnceLock;

#[no_mangle]
pub extern "C" fn datadog_profiling_notify_trace_finished(
    local_root_span_id: u64,
    span_type: ZaiStr,
    resource: ZaiStr,
) {
    crate::notify_trace_finished(
        local_root_span_id,
        span_type.to_string_lossy(),
        resource.to_string_lossy(),
    );
}

/// Alignment to 16 bytes was done by the C version of the profiler. It's not
/// strictly necessary, but changing it requires a change to the tracer too.
#[repr(C, align(16))]
pub struct Uuid(uuid::Uuid);

impl From<&uuid::Uuid> for Uuid {
    fn from(uuid: &uuid::Uuid) -> Self {
        Self(*uuid)
    }
}

/// Fetch the runtime id of the process. Note that it may return the nil UUID.
/// Only call this from a PHP thread.
#[no_mangle]
pub extern "C" fn datadog_profiling_runtime_id() -> Uuid {
    Uuid::from(runtime_id())
}

#[cfg(feature = "trigger_time_sample")]
#[no_mangle]
pub extern "C" fn ddog_php_prof_trigger_time_sample() {
    use crate::RefCellExt;
    use log::error;
    use std::sync::atomic::Ordering;

    let result = super::REQUEST_LOCALS.try_with_borrow(|locals| {
        if locals.system_settings().profiling_enabled {
            // Safety: only vm interrupts are stored there, or possibly null (edges only).
            if let Some(vm_interrupt) = unsafe { locals.vm_interrupt_addr.as_ref() } {
                locals.interrupt_count.fetch_add(1, Ordering::SeqCst);
                vm_interrupt.store(true, Ordering::SeqCst);
            }
        }
    });

    if let Err(err) = result {
        error!("ddog_php_prof_trigger_time_sample failed to borrow request locals: {err}");
    }
}

pub use crate::wall_time::ddog_php_prof_interrupt_function;

// --- ddtrace integration -----------------------------------------------------

/// Tracer's get_profiling_context; set by datadog_php_profiling_startup when tracer found.
#[repr(C)]
pub struct ddtrace_profiling_context {
    pub local_root_span_id: u64,
    pub span_id: u64,
}

unsafe extern "C" fn noop_get_profiling_context() -> ddtrace_profiling_context {
    ddtrace_profiling_context {
        local_root_span_id: 0,
        span_id: 0,
    }
}

unsafe extern "C" fn noop_get_process_tags_serialized() -> *mut zend_string {
    core::ptr::null_mut()
}

#[no_mangle]
pub static mut datadog_php_profiling_get_profiling_context: Option<
    unsafe extern "C" fn() -> ddtrace_profiling_context,
> = Some(noop_get_profiling_context);

#[no_mangle]
pub static mut datadog_php_profiling_get_process_tags_serialized: Option<
    unsafe extern "C" fn() -> *mut zend_string,
> = Some(noop_get_process_tags_serialized);

/// Wrapper so raw pointer can be stored in OnceLock (Send + Sync).
#[repr(transparent)]
struct SyncUuidPtr(Option<*const uuid::Uuid>);
unsafe impl Send for SyncUuidPtr {}
unsafe impl Sync for SyncUuidPtr {}

/// Cached pointer to ddtrace_runtime_id. Resolved at runtime via dlsym; None if ddtrace not loaded.
static DDTRACE_RUNTIME_ID_CACHE: OnceLock<SyncUuidPtr> = OnceLock::new();

/// Returns pointer to ddtrace's runtime_id if the tracer is loaded. Resolved at runtime via dlsym.
pub fn ddtrace_runtime_id_ptr() -> Option<*const uuid::Uuid> {
    DDTRACE_RUNTIME_ID_CACHE
        .get_or_init(|| {
            let ptr = runtime::symbol_addr("ddtrace_runtime_id");
            SyncUuidPtr(if ptr.is_null() {
                None
            } else {
                Some(ptr as *const uuid::Uuid)
            })
        })
        .0
}

/// Empty function table terminator. Const so it can be used in static array initializers.
const FUNCTIONS_END: zend_function_entry = zend_function_entry {
    fname: core::ptr::null(),
    handler: None,
    arg_info: core::ptr::null(),
    num_args: 0,
    flags: 0,
};

/// ZIF wrapper: PHP calls this with (execute_data, return_value); we ignore both and
/// delegate to the internal trigger logic.
#[cfg(feature = "trigger_time_sample")]
unsafe extern "C" fn trigger_time_sample_zif(
    _execute_data: *mut zend_execute_data,
    _return_value: *mut crate::bindings::zval,
) {
    ddog_php_prof_trigger_time_sample();
}

/// Function entry table for the trigger_time_sample feature.
/// fname encodes the fully-qualified namespace so PHP registers it as
/// \Datadog\Profiling\trigger_time_sample(). null arg_info with num_args=0
/// is safe for a zero-argument function across PHP 7.1–8.5.
#[cfg(feature = "trigger_time_sample")]
static TRIGGER_FUNCTIONS: [zend_function_entry; 2] = [
    zend_function_entry {
        fname: b"Datadog\\Profiling\\trigger_time_sample\0".as_ptr() as *const c_char,
        handler: Some(trigger_time_sample_zif),
        arg_info: core::ptr::null(),
        num_args: 0,
        flags: 0,
    },
    FUNCTIONS_END,
];

/// Wrapper so *const is Sync for static; repr(transparent) preserves layout for C.
#[repr(transparent)]
pub struct SyncFnTable(pub *const zend_function_entry);
unsafe impl Sync for SyncFnTable {}

#[no_mangle]
pub static ddog_php_prof_functions: SyncFnTable = SyncFnTable(
    #[cfg(feature = "trigger_time_sample")]
    TRIGGER_FUNCTIONS.as_ptr(),
    #[cfg(not(feature = "trigger_time_sample"))]
    &FUNCTIONS_END,
);

/// Resolves ddtrace symbols at runtime. If the tracer is loaded, hooks up
/// get_profiling_context and get_process_tags_serialized.
#[no_mangle]
pub extern "C" fn datadog_php_profiling_startup(_extension: *mut crate::bindings::ZendExtension) {
    let get_ctx = runtime::symbol_addr("ddtrace_get_profiling_context");
    let get_tags = runtime::symbol_addr("ddtrace_process_tags_get_serialized");
    if !get_ctx.is_null() {
        let f: unsafe extern "C" fn() -> ddtrace_profiling_context =
            unsafe { core::mem::transmute(get_ctx) };
        unsafe {
            datadog_php_profiling_get_profiling_context = Some(f);
        }
    }
    if !get_tags.is_null() {
        let f: unsafe extern "C" fn() -> *mut zend_string =
            unsafe { core::mem::transmute(get_tags) };
        unsafe {
            datadog_php_profiling_get_process_tags_serialized = Some(f);
        }
    }
}

// --- General C exports -------------------------------------------------------

/// Writes a long into a zval.
#[no_mangle]
pub extern "C" fn ddog_php_prof_copy_long_into_zval(dest: *mut crate::bindings::zval, num: c_long) {
    if dest.is_null() {
        return;
    }
    unsafe {
        (*dest).value.lval = num as crate::bindings::zend_long;
        (*dest).set_type(crate::bindings::IS_LONG);
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::borrow::Cow;

    #[test]
    fn test_string_view() {
        let slice: &[u8] = b"datadog \xF0\x9F\x90\xB6";
        let string_view = ZaiStr::from(slice);
        assert_eq!(slice, string_view.as_bytes());

        let expected = "datadog 🐶";
        let actual = string_view.to_string_lossy();
        match actual {
            Cow::Borrowed(actual) => assert_eq!(expected, actual),
            _ => panic!("Expected a borrowed string, got: {:?}", actual),
        };

        let actual = string_view.into_string();
        assert_eq!(expected, actual)
    }
}
