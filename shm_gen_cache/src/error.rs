//! Error numbers.
//!
//! The numeric values are stable API: they are returned by the C API and
//! must not change. `miss = 1` is not an error in Rust (lookups return
//! `Ok(None)`); it exists only as a C status. `InvalidMapping` and
//! `FullRetry` are not produced by the cache code but keep their numbers.

/// Errors returned by cache operations.
#[repr(u8)]
#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub enum Error {
    /// A lookup hit does not fit the output buffer, or a mapping is smaller
    /// than the cache layout.
    InsufficientCapacity = 2,
    /// The key is longer than `max_key_size`.
    KeyTooLarge = 3,
    /// The value is longer than `max_value_size`.
    ValueTooLarge = 4,
    /// Not produced by the cache code.
    InvalidMapping = 5,
    /// Every participant slot is taken by a live registration.
    ParticipantRegistryFull = 6,
    /// Not produced by the cache code.
    FullRetry = 7,
    /// Null mapping, invalid configuration, or a lookup key overlapping the
    /// output storage.
    InvalidArgument = 8,
    /// The liveness backend failed to synchronise with a dead participant.
    IoError = 9,
    /// The liveness backend is not supported by the kernel.
    Unsupported = 10,
    /// Lost a race (internal to rotation), or initialising a mapping whose
    /// header is not zeroed.
    ConcurrentOperation = 11,
    /// A participant slot changed state while it was being claimed.
    Corrupt = 12,
    /// The mapping is not aligned to a cache line.
    Misaligned = 13,
    /// Exhausted the rotation-ownership wait, including a failed takeover.
    RotationOwnerTimeout = 14,
    /// Owned rotation could not clear an old participant pin before arena
    /// reuse.
    ArenaReuseTimeout = 15,
}

impl Error {
    /// The stable numeric value (also the C API status code).
    #[inline]
    pub const fn code(self) -> u8 {
        self as u8
    }
}
