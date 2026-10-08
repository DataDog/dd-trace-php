//! Internals for tests.
//!
//! Compiled in verification builds (feature `verify`) and with feature
//! `test-access`. Tests should prefer the public API and use these only to
//! stage states or check invariants the API cannot observe; they must never
//! copy production code.

use core::sync::atomic::{AtomicU32, AtomicU64, Ordering};

use crate::arena::ctl::CtlWord;
use crate::arena::records::RecordHeader;
use crate::cache::{ARENA_COUNT, Cache, INITIAL_EPOCH};
use crate::config::Params;
use crate::error::Error;
use crate::occupancy::OccupancyMode;
use crate::participant::{ParticipantSlot, State};

pub use crate::arena::index::{bucket, mix_hash};
pub use crate::arena::probe::ProbeError;
pub use crate::arena::records::RecordLengths;
pub use crate::cache::rotation::{RotationOwner, RotationPolicy};

/// Number of arenas.
pub const ARENAS: u64 = ARENA_COUNT;
/// The epoch of a freshly initialised cache.
pub const FIRST_EPOCH: u64 = INITIAL_EPOCH;

/// A decoded arena control word plus the exact occupancy counter.
#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub struct ArenaState {
    /// 32-bit epoch stamp of the incarnation.
    pub epoch: u32,
    /// Whether the incarnation is sealed.
    pub sealed: bool,
    /// Bytes claimed (may exceed the record area).
    pub bump: u32,
    /// The exact counter (`None` in estimator mode).
    pub occupancy: Option<u32>,
}

/// The global epoch word.
pub fn global_epoch<P: Params>(cache: Cache<'_, P>) -> &AtomicU64 {
    cache.global_epoch()
}

/// The rotation owner word.
pub fn rotation_owner_word<'m, P: Params>(cache: Cache<'m, P>) -> &'m AtomicU64 {
    &cache.header().rotation_owner
}

/// The registration counter.
pub fn registration_counter<'m, P: Params>(cache: Cache<'m, P>) -> &'m AtomicU32 {
    &cache.header().registration_counter
}

/// Loads the rotation owner with `order`.
pub fn rotation_owner<P: Params>(cache: Cache<'_, P>, order: Ordering) -> RotationOwner {
    RotationOwner(rotation_owner_word(cache).load(order))
}

/// Arena `index`'s control word.
pub fn arena_ctl_word<'m, P: Params>(cache: Cache<'m, P>, index: u64) -> &'m AtomicU64 {
    let hp = cache.params().hot();
    cache.arena(&hp, index).ctl()
}

/// Arena `index`'s exact occupancy counter (exact mode only).
pub fn arena_exact_occupancy<'m, P: Params>(cache: Cache<'m, P>, index: u64) -> &'m AtomicU32 {
    assert!(!<P::Occupancy as OccupancyMode>::ESTIMATES);
    let hp = cache.params().hot();
    cache.arena(&hp, index).exact_occupancy()
}

/// A decoded arena control word.
#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub struct ArenaCtl {
    /// 32-bit epoch stamp of the incarnation.
    pub epoch: u32,
    /// Whether the incarnation is sealed.
    pub sealed: bool,
    /// Bytes claimed (may exceed the record area).
    pub bump: u32,
}

/// Arena `index`'s control word, decoded: a single load with `order`.
/// Unlike [`arena_state`] it does not also read the occupancy counter, so it
/// adds exactly one event where a test only needs the control word.
pub fn arena_ctl<P: Params>(cache: Cache<'_, P>, index: u64, order: Ordering) -> ArenaCtl {
    let ctl = CtlWord(arena_ctl_word(cache, index).load(order));
    ArenaCtl {
        epoch: ctl.epoch(),
        sealed: ctl.sealed(),
        bump: ctl.bump(),
    }
}

/// Arena `index`'s state; each word loaded with `order`.
pub fn arena_state<P: Params>(cache: Cache<'_, P>, index: u64, order: Ordering) -> ArenaState {
    let ctl = CtlWord(arena_ctl_word(cache, index).load(order));
    let occupancy = if <P::Occupancy as OccupancyMode>::ESTIMATES {
        None
    } else {
        Some(arena_exact_occupancy(cache, index).load(order))
    };
    ArenaState {
        epoch: ctl.epoch(),
        sealed: ctl.sealed(),
        bump: ctl.bump(),
        occupancy,
    }
}

/// Encodes a control word.
pub const fn encode_ctl(epoch: u32, sealed: bool, bump: u32) -> u64 {
    CtlWord::new(epoch, sealed, bump).0
}

/// Participant slot `index`.
pub fn participant<'m, P: Params>(cache: Cache<'m, P>, index: u32) -> &'m ParticipantSlot {
    let hp = cache.params().hot();
    cache.participant(&hp, index)
}

/// A slot's pinned epoch (0: unpinned).
pub fn pinned_epoch(slot: &ParticipantSlot) -> &AtomicU64 {
    &slot.pinned_epoch
}

/// A slot's state word.
pub fn state_word(slot: &ParticipantSlot) -> &AtomicU64 {
    &slot.state
}

/// A slot's start time word.
pub fn thread_disambiguation(slot: &ParticipantSlot) -> &AtomicU64 {
    &slot.thread_disambiguation
}

/// A slot's reservation chunk: `(epoch, cursor, end)`.
pub fn chunk(slot: &ParticipantSlot) -> (&AtomicU64, &AtomicU32, &AtomicU32) {
    (&slot.chunk_epoch, &slot.chunk_cursor, &slot.chunk_end)
}

/// Whether a slot is FREE.
pub fn is_free(slot: &ParticipantSlot) -> bool {
    slot.is_free()
}

/// The registration id in a slot's state word (bits 32-63): one load with
/// `Relaxed`, without the production assertions of the internal accessor
/// (which would also query the liveness backend).
pub fn registration_id(slot: &ParticipantSlot) -> u32 {
    State(slot.state.load(Ordering::Relaxed)).registration_id()
}

/// The REGISTERED state word of `(pid, registration_id)`.
pub fn registered_state(pid: u32, registration_id: u32) -> u64 {
    State::registered(pid, registration_id).0
}

/// The INITIALIZING state word of `(pid, registration_id)`.
pub fn initializing_state(pid: u32, registration_id: u32) -> u64 {
    State::initializing(pid, registration_id).0
}

/// The REAPING state word.
pub const REAPING_STATE: u64 = State::REAPING.0;

/// Rotates from epoch `e` as the participant in slot `slot_index`.
pub fn rotate<P: Params>(
    cache: Cache<'_, P>,
    slot_index: u32,
    e: u64,
    policy: RotationPolicy,
) -> Result<(), Error> {
    let slot = participant(cache, slot_index);
    cache.rotate(slot, slot_index, e, policy)
}

/// Acquires rotation ownership for epoch `e` as slot `slot_index`.
pub fn acquire_rotation<P: Params>(
    cache: Cache<'_, P>,
    slot_index: u32,
    e: u64,
    policy: RotationPolicy,
) -> Result<(), Error> {
    let hp = cache.params().hot();
    let slot = participant(cache, slot_index);
    cache.acquire_rotation(&hp, slot, slot_index, e, policy)
}

/// Slot reaping of slot `slot_index`.
pub fn release_zombie_claim<P: Params>(
    cache: Cache<'_, P>,
    slot_index: u32,
) -> Result<bool, Error> {
    participant(cache, slot_index).release_zombie_claim::<P::Pid>()
}

/// Probes the incarnation `epoch` of arena `epoch % 3` for `key` (no
/// promotion), copying a hit into `output` (logical capacity `capacity`).
pub fn probe<P: Params>(
    cache: Cache<'_, P>,
    hash: u64,
    key: &[u8],
    epoch: u64,
    output: &mut [u64],
    capacity: usize,
) -> Result<RecordLengths, ProbeError> {
    assert!(capacity <= output.len() * 8);
    let hp = cache.params().hot();
    // SAFETY: output holds at least align8(capacity) bytes.
    unsafe {
        crate::arena::probe(
            cache.arena_of(&hp, epoch),
            &hp,
            hash,
            key,
            epoch,
            output.as_mut_ptr(),
            capacity,
            cache.global_epoch(),
        )
    }
}

/// Arena `index`'s record area: first byte and size.
pub fn record_area<P: Params>(cache: Cache<'_, P>, index: u64) -> (*const u8, usize) {
    let hp = cache.params().hot();
    (
        cache.arena(&hp, index).records().as_ptr(),
        hp.record_area_size as usize,
    )
}

/// Arena `index`'s first byte.
pub fn arena_base<P: Params>(cache: Cache<'_, P>, index: u64) -> *const u8 {
    let hp = cache.params().hot();
    cache.arena(&hp, index).base().as_ptr()
}

/// Arena `index`'s index entry `slot`.
pub fn index_entry<'m, P: Params>(cache: Cache<'m, P>, index: u64, slot: u32) -> &'m AtomicU64 {
    let hp = cache.params().hot();
    assert!(slot <= hp.bucket_mask);
    cache.arena(&hp, index).slot(slot)
}

/// The 64-bit word at byte `offset` of arena `index`'s record area, loaded
/// with `order`. `offset` must be 8-aligned and inside the record area.
pub fn record_word<P: Params>(
    cache: Cache<'_, P>,
    index: u64,
    offset: usize,
    order: Ordering,
) -> u64 {
    let (records, size) = record_area(cache, index);
    assert!(offset.is_multiple_of(8) && offset + 8 <= size);
    // SAFETY: an aligned word inside the record area, which is only ever
    // accessed as 64-bit atomic words.
    unsafe { AtomicU64::from_ptr(records.add(offset).cast::<u64>().cast_mut()) }.load(order)
}

/// A decoded record header word.
#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub struct RecordHeaderView {
    /// The epoch stamp (published last, with release).
    pub epoch: u32,
    /// The payload length.
    pub len: u32,
}

/// The header of the record at byte `offset` of arena `index`'s record area.
pub fn record_header<P: Params>(
    cache: Cache<'_, P>,
    index: u64,
    offset: usize,
    order: Ordering,
) -> RecordHeaderView {
    let header = RecordHeader(record_word(cache, index, offset, order));
    RecordHeaderView {
        epoch: header.epoch(),
        len: header.len(),
    }
}

/// The lengths word of the record at byte `offset` of arena `index`'s
/// record area (the word after its header).
pub fn record_lengths<P: Params>(
    cache: Cache<'_, P>,
    index: u64,
    offset: usize,
    order: Ordering,
) -> RecordLengths {
    RecordLengths(record_word(cache, index, offset + 8, order))
}

/// The first `N` hashes from 0 up whose home buckets are pairwise distinct:
/// `result[0] == 0`, and each next one is the smallest larger hash whose
/// bucket differs from all earlier ones.
pub const fn distinct_bucket_hashes<const N: usize>(bucket_count: u32) -> [u64; N] {
    assert!(N <= bucket_count as usize);
    let mut result = [0u64; N];
    let mut i = 1;
    while i < N {
        let mut candidate = result[i - 1] + 1;
        let mut j = 0;
        while j < i {
            if bucket(candidate, bucket_count) == bucket(result[j], bucket_count) {
                candidate += 1;
                j = 0;
            } else {
                j += 1;
            }
        }
        result[i] = candidate;
        i += 1;
    }
    result
}
