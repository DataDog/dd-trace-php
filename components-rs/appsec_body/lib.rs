//! JSON/XML parsers with a private allocator, standard library, and unwinder.
//! This crate must only be linked through the isolation build step.
#![feature(alloc_error_hook)]

mod api;
mod json;
mod runtime;
mod writer;
mod xml;
mod xml_dtd;
pub use api::{AppsecBodyAllocator, AppsecBodyMessagePack, AppsecBodyStatus};
use writer::MsgpackWriter;

impl AppsecBodyMessagePack {
    fn empty(status: AppsecBodyStatus) -> Self {
        Self {
            ptr: std::ptr::null_mut(),
            len: 0,
            capacity: 0,
            free: None,
            status,
        }
    }
}

#[cfg(test)]
mod render;
#[cfg(test)]
use render::render;

/// # Safety
/// Input must remain readable during the call. Callbacks must obey api.rs.
#[no_mangle]
pub unsafe extern "C" fn ddog_appsec_body_private_json(
    input: *const u8,
    len: usize,
    max_depth: i32,
    allocator: AppsecBodyAllocator,
) -> AppsecBodyMessagePack {
    runtime::run(allocator, || {
        json::parse(bytes(input, len), depth_limit(max_depth))
    })
}

/// # Safety
/// Input must remain readable during the call. Callbacks must obey api.rs.
#[no_mangle]
pub unsafe extern "C" fn ddog_appsec_body_private_xml(
    input: *const u8,
    len: usize,
    max_depth: i32,
    allocator: AppsecBodyAllocator,
) -> AppsecBodyMessagePack {
    runtime::run(allocator, || {
        xml::parse(bytes(input, len), depth_limit(max_depth))
    })
}

/// # Safety
/// `body` must come from a parse function and must not be dropped twice.
#[no_mangle]
pub unsafe extern "C" fn ddog_appsec_body_private_drop(body: AppsecBodyMessagePack) {
    if body.ptr.is_null() {
        return;
    }
    match body.free {
        Some(free) => free(body.ptr),
        // Not handed out yet: it belongs to the current allocation scope.
        None => drop(Vec::from_raw_parts(body.ptr, body.len, body.capacity)),
    }
}

unsafe fn bytes<'a>(ptr: *const u8, len: usize) -> &'a [u8] {
    if len == 0 {
        &[]
    } else {
        std::slice::from_raw_parts(ptr, len)
    }
}

fn depth_limit(max_depth: i32) -> u32 {
    u32::try_from(max_depth).unwrap_or(u32::MAX)
}
