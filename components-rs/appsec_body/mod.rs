//! C ABI facade for the independently compiled, localized parser library.
use libdd_common_ffi::slice::{AsBytes, CharSlice};

mod api;
pub use api::{AppsecBodyAllocator, AppsecBodyMessagePack, AppsecBodyStatus};

extern "C" {
    fn ddog_appsec_body_private_json(
        input: *const u8,
        len: usize,
        depth: i32,
        allocator: AppsecBodyAllocator,
    ) -> AppsecBodyMessagePack;
    fn ddog_appsec_body_private_xml(
        input: *const u8,
        len: usize,
        depth: i32,
        allocator: AppsecBodyAllocator,
    ) -> AppsecBodyMessagePack;
    fn ddog_appsec_body_private_drop(body: AppsecBodyMessagePack);
}

/// Parse JSON directly to MessagePack. A negative depth means unlimited.
/// Allocator callbacks must not reenter the parser, unwind or longjmp.
#[no_mangle]
pub extern "C" fn ddog_appsec_parse_json(
    input: CharSlice,
    max_depth: i32,
    allocator: AppsecBodyAllocator,
) -> AppsecBodyMessagePack {
    let input = input.as_bytes();
    unsafe { ddog_appsec_body_private_json(input.as_ptr(), input.len(), max_depth, allocator) }
}

/// Parse XML directly to MessagePack; external entities are never loaded.
/// Allocator callbacks must not reenter the parser, unwind or longjmp.
#[no_mangle]
pub extern "C" fn ddog_appsec_parse_xml(
    input: CharSlice,
    max_depth: i32,
    allocator: AppsecBodyAllocator,
) -> AppsecBodyMessagePack {
    let input = input.as_bytes();
    unsafe { ddog_appsec_body_private_xml(input.as_ptr(), input.len(), max_depth, allocator) }
}

/// # Safety
/// `body` must come from a parse function and must not be dropped twice.
#[no_mangle]
pub unsafe extern "C" fn ddog_appsec_body_msgpack_drop(body: AppsecBodyMessagePack) {
    ddog_appsec_body_private_drop(body);
}
