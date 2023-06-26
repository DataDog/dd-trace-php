//! Definitions for interacting with the profiler from a C API, such as the
//! ddtrace extension.

use crate::bindings::zend_execute_data;
use crate::runtime_id;
use std::borrow::Cow;
use std::fmt::{Display, Formatter};
use std::marker::PhantomData;

/// A non-owning, not necessarily null terminated, not utf-8 encoded, borrowed
/// string. Must satisfy the requirements of [core::slice::from_raw_parts],
/// notably it must not use the nul pointer even when the length is 0.
/// Keep this representation in sync with zai_string_view.
#[repr(C)]
pub struct StringView<'a> {
    len: libc::size_t,
    ptr: *const libc::c_char,
    // The PhantomData says this acts like a reference to a [u8], even though
    // it doesn't actually have one.
    _marker: PhantomData<&'a [u8]>,
}

impl<'a> From<&'a [u8]> for StringView<'a> {
    fn from(value: &'a [u8]) -> Self {
        Self {
            len: value.len(),
            ptr: value.as_ptr() as *const libc::c_char,
            _marker: Default::default(),
        }
    }
}

impl<'a> StringView<'a> {
    pub fn as_slice(&self) -> &'a [u8] {
        // Safety: StringView's are required to uphold these invariants.
        unsafe { core::slice::from_raw_parts(self.ptr as *const u8, self.len as usize) }
    }

    pub fn to_string_lossy(&self) -> Cow<'a, str> {
        String::from_utf8_lossy(self.as_slice())
    }
}

impl<'a> Display for StringView<'a> {
    fn fmt(&self, f: &mut Formatter<'_>) -> std::fmt::Result {
        f.write_str(&self.to_string_lossy())
    }
}

#[no_mangle]
pub extern "C" fn datadog_profiling_notify_trace_finished(
    local_root_span_id: u64,
    span_type: StringView,
    resource: StringView,
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
    fn test_string_view() {
        let slice: &[u8] = b"datadog \xF0\x9F\x90\xB6";
        let string_view: StringView = StringView::from(slice);
        assert_eq!(slice, string_view.as_slice());

        let expected = "datadog 🐶";
        let actual = string_view.to_string_lossy();
        match actual {
            Cow::Borrowed(actual) => assert_eq!(expected, actual),
            _ => panic!("Expected a borrowed string, got: {:?}", actual),
        };

        let actual = string_view.to_string();
        assert_eq!(expected, actual)
    }
}
