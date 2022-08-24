//! Definitions for interacting with the profiler from a C API, such as the
//! ddtrace extension.

use crate::bindings::zend_execute_data;
use crate::RUNTIME_ID;

#[repr(C, align(16))]
#[derive(Copy, Clone)]
pub struct Uuid(uuid::Uuid);

impl Uuid {
    pub const fn new() -> Self {
        Self {
            0: uuid::Uuid::nil()
        }
    }
}

impl Default for Uuid {
    fn default() -> Self {
        Uuid::new()
    }
}

impl From<uuid::Uuid> for Uuid {
    fn from(uuid: uuid::Uuid) -> Self {
        Self {
            0: uuid
        }
    }
}

impl From<Uuid> for uuid::Uuid {
    fn from(uuid: Uuid) -> Self {
        uuid.0
    }
}

#[no_mangle]
pub extern "C" fn datadog_profiling_runtime_id() -> Uuid {
    *RUNTIME_ID
}

/// Gathers a time sample if the configured period has elapsed. Used by the
/// tracer to handle pending profiler interrupts before calling a tracing
/// closure from an internal function hook; if this isn't done then the
/// closure is erroneously at the top of the stack.
///
/// # Safety
/// The zend_execute_data pointer should come from the engine to ensure it and
/// its sub-objects are valid.
#[no_mangle]
pub extern "C" fn datadog_profiling_interrupt_function(execute_data: *mut zend_execute_data) {
    crate::interrupt_function(execute_data);
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_uuid() {
        const NIL_UUID: Uuid = Uuid::new();
        let uuid: uuid::Uuid = NIL_UUID.into();
        assert!(uuid.is_nil());

        // Asserting we can losslessly convert both ways.
        let uuidv4 = uuid::Uuid::new_v4();
        let uuid = Uuid::from(uuidv4);
        let actual = uuid::Uuid::from(uuid);
        assert_eq!(uuidv4, actual);
        assert_eq!(4, actual.get_version_num());
    }
}
