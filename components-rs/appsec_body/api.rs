use std::ffi::c_void;

/// All request allocations, except those made while a panic is in progress,
/// go through these callbacks. Neither callback may reenter the parser,
/// unwind or longjmp.
#[repr(C)]
#[derive(Clone, Copy)]
pub struct AppsecBodyAllocator {
    pub context: *mut c_void, // ctx passed to allocate
    pub allocate: unsafe extern "C" fn(*mut c_void, usize) -> *mut u8,
    /// Must remain callable until the returned MessagePack buffer is dropped.
    pub free: unsafe extern "C" fn(*mut u8),
}

#[repr(C)]
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum AppsecBodyStatus {
    /// Nothing usable was parsed; the MessagePack buffer is empty.
    Empty,
    /// Malformed or truncated input; all emitted containers are closed.
    Partial,
    Complete,
    /// Allocation failed; all partial state has been released.
    OutOfMemory,
    /// An unexpected panic.
    InternalError,
}

/// Owned by the parser library; release with ddog_appsec_body_msgpack_drop.
#[repr(C)]
pub struct AppsecBodyMessagePack {
    pub ptr: *mut u8,
    pub len: usize,
    pub capacity: usize,
    /// The allocator's free callback, set once the buffer leaves the parser.
    pub free: Option<unsafe extern "C" fn(*mut u8)>,
    pub status: AppsecBodyStatus,
}
