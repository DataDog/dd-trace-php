//! Definitions for interacting with the profiler from a C API, such as the
//! ddtrace extension.

use crate::profiling::bindings::ZaiStr;
use crate::profiling::runtime_id;

#[no_mangle]
pub extern "C" fn datadog_profiling_notify_trace_finished(
    local_root_span_id: u64,
    span_type: ZaiStr,
    resource: ZaiStr,
) {
    crate::profiling::notify_trace_finished(
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
    Uuid(runtime_id())
}

#[cfg(feature = "trigger_time_sample")]
#[no_mangle]
extern "C" fn ddog_php_prof_trigger_time_sample() {
    use crate::profiling::RefCellExt;
    use log::error;
    use std::sync::atomic::Ordering;

    let result = super::REQUEST_LOCALS.try_with_borrow(|locals| {
        if locals.system_settings().profiling_enabled {
            // Safety: only vm interrupts are stored there, or possibly null (edges only).
            if let Some(vm_interrupt) = unsafe { locals.vm_interrupt_addr.as_ref() } {
                // SAFETY: this callback runs on an initialized PHP request thread.
                let globals = unsafe { crate::profiling::module_globals::get_profiler_globals() };
                // SAFETY: the current thread's module globals are valid through GSHUTDOWN.
                unsafe { (*globals).interrupt_count.fetch_add(1, Ordering::Relaxed) };
                vm_interrupt.store(true, Ordering::SeqCst);
            }
        }
    });

    if let Err(err) = result {
        error!("ddog_php_prof_trigger_time_sample failed to borrow request locals: {err}");
    }
}

pub use crate::profiling::wall_time::ddog_php_prof_interrupt_function;

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
