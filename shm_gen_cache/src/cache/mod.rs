//! The cache: shared layout, initialisation and
//! registration. Lookup, store and rotation live in the submodules.
//!
//! Shared layout (layout version 11; fully determined by the configuration
//! and the architecture's cache-line size):
//!
//! ```text
//! 0                              CacheHeader                      1 CL
//! CL                             ParticipantSlot[capacity]        1 CL each
//! CL * (1 + capacity)            arena[3], arena_stride bytes each
//! ```
//!
//! `global_epoch` (starting at [`INITIAL_EPOCH`]) selects the current arena
//! `e % 3`; the previous generation is arena `(e - 1) % 3`, stamped `e - 1`.
//! The third arena is the one the next rotation reuses.

mod lookup;
pub(crate) mod rotation;
mod store;

use core::cell::UnsafeCell;
use core::marker::PhantomData;
use core::ptr::NonNull;
use core::sync::atomic::Ordering::{Relaxed, Release, SeqCst};
use core::sync::atomic::{AtomicU32, AtomicU64};

use crate::arena::ArenaView;
use crate::arena::ctl::CtlWord;
use crate::arena::index::EMPTY_EPOCH;
use crate::config::{HotParams, Params};
use crate::error::Error;
use crate::lock::ParticipantLock;
use crate::occupancy::OccupancyMode;
use crate::participant::{ParticipantSlot, next_registration_id};
use crate::util::CACHE_LINE;

/// Number of arenas: current, previous, and the one being reused.
pub(crate) const ARENA_COUNT: u64 = 3;
/// Lookup probes both the current epoch and its predecessor. Neither may
/// initially equal the zero stamp used to initialise every index entry.
pub(crate) const INITIAL_EPOCH: u64 = 2;

/// Header `magic` values: the 8 characters as a little-endian word.
const MAGIC_ZEROED: u64 = 0;
const MAGIC_INITIALIZING: u64 = u64::from_le_bytes(*b"SCGINIT\0");
const MAGIC_INITIALIZED: u64 = u64::from_le_bytes(*b"SCCACHE\0");

/// The mapping header. The plain fields record the layout version and the
/// configuration; they are written once, before the mapping is published,
/// and are part of layout version 11, although the cache code never reads
/// them back.
#[cfg_attr(target_arch = "aarch64", repr(C, align(128)))]
#[cfg_attr(not(target_arch = "aarch64"), repr(C, align(64)))]
pub struct CacheHeader {
    magic: AtomicU64,
    version: UnsafeCell<u32>,
    participant_capacity: UnsafeCell<u32>,
    /// Per arena.
    bucket_count: UnsafeCell<u32>,
    max_key_size: UnsafeCell<u32>,
    max_value_size: UnsafeCell<u32>,
    record_area_size: UnsafeCell<u32>,
    max_occupancy: UnsafeCell<u32>,
    _padding: u32,
    pub(crate) global_epoch: AtomicU64,
    /// Tags participant data to avoid ABA problems.
    pub(crate) registration_counter: AtomicU32,
    /// The configured value (zero for the default). All attachers must agree
    /// because the bump overshoot bound covers every participant.
    reservation_chunk_size: UnsafeCell<u32>,
    /// One owner serialises all epoch transitions, including crash
    /// recovery: `{slot: bits 0-31, registration_id: bits 32-63}`, with
    /// registration id zero meaning no owner.
    pub(crate) rotation_owner: AtomicU64,
}

const _: () = {
    use core::mem::{align_of, offset_of, size_of};
    assert!(size_of::<CacheHeader>() == CACHE_LINE);
    assert!(align_of::<CacheHeader>() == CACHE_LINE);
    assert!(offset_of!(CacheHeader, magic) == 0);
    assert!(offset_of!(CacheHeader, version) == 8);
    assert!(offset_of!(CacheHeader, participant_capacity) == 12);
    assert!(offset_of!(CacheHeader, bucket_count) == 16);
    assert!(offset_of!(CacheHeader, max_key_size) == 20);
    assert!(offset_of!(CacheHeader, max_value_size) == 24);
    assert!(offset_of!(CacheHeader, record_area_size) == 28);
    assert!(offset_of!(CacheHeader, max_occupancy) == 32);
    assert!(offset_of!(CacheHeader, global_epoch) == 40);
    assert!(offset_of!(CacheHeader, registration_counter) == 48);
    assert!(offset_of!(CacheHeader, reservation_chunk_size) == 52);
    assert!(offset_of!(CacheHeader, rotation_owner) == 56);
};

/// Zero-initialised, cache-line aligned storage for a cache of `N` bytes
/// (usable as a `static`).
#[repr(C, align(128))]
pub struct CacheStorage<const N: usize>(UnsafeCell<[u8; N]>);

// SAFETY: the storage is only accessed through Cache, whose shared accesses
// are atomic (plain writes happen only during initialisation, before
// publication).
unsafe impl<const N: usize> Sync for CacheStorage<N> {}

impl<const N: usize> CacheStorage<N> {
    /// Zeroed storage.
    pub const fn new() -> Self {
        Self(UnsafeCell::new([0; N]))
    }

    /// The first byte.
    pub const fn as_mut_ptr(&self) -> *mut u8 {
        self.0.get().cast()
    }

    /// The size in bytes.
    pub const fn len(&self) -> usize {
        N
    }

    /// Whether the size is zero.
    pub const fn is_empty(&self) -> bool {
        N == 0
    }
}

impl<const N: usize> Default for CacheStorage<N> {
    fn default() -> Self {
        Self::new()
    }
}

/// A process-local view of an initialised cache mapping (pointer +
/// parameters; `Copy`).
///
/// Any number of threads and processes sharing the mapping may operate on
/// it concurrently; each must register a participant first
/// ([`Cache::register_participant`]).
pub struct Cache<'m, P: Params> {
    base: NonNull<u8>,
    params: P,
    _m: PhantomData<&'m CacheHeader>,
}

impl<P: Params> Clone for Cache<'_, P> {
    #[inline(always)]
    fn clone(&self) -> Self {
        *self
    }
}

impl<P: Params> Copy for Cache<'_, P> {}

// SAFETY: every shared access goes through atomics.
unsafe impl<P: Params + Send> Send for Cache<'_, P> {}
// SAFETY: as above.
unsafe impl<P: Params + Sync> Sync for Cache<'_, P> {}

impl<'m, P: Params> Cache<'m, P> {
    /// Initialises a cache in `mem..mem + len`.
    ///
    /// Errors: null `mem` -> [`Error::InvalidArgument`]; `len` below the
    /// mapping size -> [`Error::InsufficientCapacity`]; `mem` not cache-line
    /// aligned -> [`Error::Misaligned`]; header not zeroed (or concurrently
    /// initialised) -> [`Error::ConcurrentOperation`].
    ///
    /// # Safety
    /// `mem..mem + len` must be valid for reads and writes for `'m`,
    /// zero-filled (an anonymous mapping or zeroed static), and accessed by
    /// others only through this crate's protocol (after this returns).
    pub unsafe fn initialize(mem: *mut u8, len: usize, params: P) -> Result<Self, Error> {
        let Some(base) = NonNull::new(mem) else {
            return Err(Error::InvalidArgument);
        };
        let d = params.derived();
        if len < d.mapping_size {
            return Err(Error::InsufficientCapacity);
        }
        if !(mem as usize).is_multiple_of(CACHE_LINE) {
            return Err(Error::Misaligned);
        }
        let cache = Cache {
            base,
            params,
            _m: PhantomData,
        };
        let header = cache.header();
        if header
            .magic
            .compare_exchange(MAGIC_ZEROED, MAGIC_INITIALIZING, SeqCst, SeqCst)
            .is_err()
        {
            return Err(Error::ConcurrentOperation);
        }
        let values = &d.header;
        // SAFETY: the mapping is unpublished, and this thread won the magic
        // CAS: no other access to these plain fields can happen.
        unsafe {
            header.version.get().write(crate::LAYOUT_VERSION);
            header
                .participant_capacity
                .get()
                .write(values.participant_capacity);
            header.bucket_count.get().write(values.bucket_count);
            header.max_key_size.get().write(values.max_key_size);
            header.max_value_size.get().write(values.max_value_size);
            header.record_area_size.get().write(values.record_area_size);
            header.max_occupancy.get().write(values.max_occupancy);
            header
                .reservation_chunk_size
                .get()
                .write(values.reservation_chunk_size);
        }
        header.registration_counter.store(1, Relaxed);
        header.global_epoch.store(0, Relaxed);
        header.rotation_owner.store(0, Relaxed);

        // SAFETY: as above.
        unsafe { cache.initialize_storage() };
        Ok(cache)
    }

    /// Initialises the participants and arenas, then publishes the epoch
    /// and the magic.
    ///
    /// # Safety
    /// The mapping must be unpublished.
    unsafe fn initialize_storage(self) {
        let hp = self.params.hot();
        for i in 0..hp.participant_capacity {
            self.participant(&hp, i).initialize();
        }
        for i in 0..ARENA_COUNT {
            let arena = self.arena(&hp, i);
            // Arena INITIAL_EPOCH % 3 holds the initial epoch, unsealed; the
            // other two are incarnation zero, sealed.
            let epoch = if i == INITIAL_EPOCH % ARENA_COUNT {
                INITIAL_EPOCH as u32
            } else {
                0
            };
            arena
                .ctl()
                .store(CtlWord::new(epoch, epoch == 0, 0).0, Relaxed);
            if !<P::Occupancy as OccupancyMode>::ESTIMATES {
                arena.exact_occupancy().store(0, Relaxed);
            }
            // SAFETY: unpublished (caller).
            unsafe { fill_zero(arena.index(), hp.bucket_mask as usize + 1) };
            const _: () = assert!(EMPTY_EPOCH == 0);
            // SAFETY: as above; the record area holds RAS / 8 words.
            unsafe { fill_zero(arena.records().cast(), hp.record_area_size as usize / 8) };
        }
        self.header().global_epoch.store(INITIAL_EPOCH, Release);
        self.header().magic.store(MAGIC_INITIALIZED, Release);
    }

    /// Registers the calling thread as a participant, reaping a dead
    /// participant's slot when the registry is full.
    ///
    /// The returned lock must be used and dropped on the calling thread.
    /// Errors: [`Error::ParticipantRegistryFull`]; liveness backend errors
    /// from slot reaping; [`Error::Corrupt`].
    pub fn register_participant(self) -> Result<ParticipantLock<'m, P>, Error> {
        let hp = self.params.hot();
        let registration_id = next_registration_id(&self.header().registration_counter);
        for i in 0..hp.participant_capacity {
            let slot = self.participant(&hp, i);
            if !slot.is_free() {
                continue;
            }
            match slot.claim::<P::Pid>(registration_id) {
                // SAFETY: the slot is claimed by this thread.
                Ok(()) => return Ok(unsafe { ParticipantLock::new(self, i) }),
                Err(Error::ConcurrentOperation) => continue,
                Err(e) => return Err(e),
            }
        }
        match self.reap_dead_participant(&hp, registration_id)? {
            // SAFETY: slot reaping registered the slot to this thread.
            Some(i) => Ok(unsafe { ParticipantLock::new(self, i) }),
            None => Err(Error::ParticipantRegistryFull),
        }
    }

    /// Slot reaping for a registration: steals the first dead participant's
    /// slot as `registration_id`, keeping it, and returns its index.
    fn reap_dead_participant(
        self,
        hp: &HotParams,
        registration_id: u32,
    ) -> Result<Option<u32>, Error> {
        for i in 0..hp.participant_capacity {
            if self.participant(hp, i).steal::<P::Pid>(registration_id)? {
                return Ok(Some(i));
            }
        }
        Ok(None)
    }

    /// The parameters.
    #[inline(always)]
    pub fn params(&self) -> &P {
        &self.params
    }

    /// The first byte of the mapping.
    #[inline(always)]
    pub fn as_ptr(&self) -> *mut u8 {
        self.base.as_ptr()
    }

    #[inline(always)]
    pub(crate) fn header(self) -> &'m CacheHeader {
        // SAFETY: the header starts the mapping.
        unsafe { self.base.cast::<CacheHeader>().as_ref() }
    }

    #[inline(always)]
    pub(crate) fn global_epoch(self) -> &'m AtomicU64 {
        &self.header().global_epoch
    }

    /// Participant slot `i < participant_capacity`.
    #[inline(always)]
    pub(crate) fn participant(self, _hp: &HotParams, i: u32) -> &'m ParticipantSlot {
        production_assert!(i < _hp.participant_capacity);
        // SAFETY: slots follow the header, one line each.
        unsafe {
            self.base
                .add(CACHE_LINE * (1 + i as usize))
                .cast::<ParticipantSlot>()
                .as_ref()
        }
    }

    /// Arena `i < 3`.
    #[inline(always)]
    pub(crate) fn arena(self, hp: &HotParams, i: u64) -> ArenaView<'m> {
        production_assert!(i < ARENA_COUNT);
        // SAFETY: arena i is within the mapping.
        unsafe {
            ArenaView::new(
                self.base
                    .add(hp.arenas_offset + i as usize * hp.arena_stride),
                hp,
            )
        }
    }

    /// The arena of `epoch` (`epoch % 3`).
    #[inline(always)]
    pub(crate) fn arena_of(self, hp: &HotParams, epoch: u64) -> ArenaView<'m> {
        self.arena(hp, epoch % ARENA_COUNT)
    }

    /// Reconstructs a view of an initialised mapping (no checks).
    ///
    /// # Safety
    /// `base` must be a mapping initialised by [`Cache::initialize`] with
    /// the same parameters, valid for `'m`.
    #[inline(always)]
    pub unsafe fn from_raw(base: NonNull<u8>, params: P) -> Self {
        Cache {
            base,
            params,
            _m: PhantomData,
        }
    }
}

/// Zeroes `count` words of unpublished shared memory.
///
/// Natively a plain `write_bytes` (a memset; it also pre-faults the
/// mapping). Under GenMC a loop of relaxed atomic stores instead: GenMC's
/// memset handling does not cover every optimised aggregate initialisation
/// (rustc cannot disable the memset builtin), and atomic stores are
/// never merged back into a memset. They happen before any thread exists,
/// so they add no interleavings.
///
/// # Safety
/// `words..words + count` must be valid and unpublished.
#[inline]
unsafe fn fill_zero(words: NonNull<AtomicU64>, count: usize) {
    #[cfg(not(sgc_genmc))]
    // SAFETY: guaranteed by the caller.
    unsafe {
        words.as_ptr().write_bytes(0, count)
    };
    #[cfg(sgc_genmc)]
    for i in 0..count {
        // SAFETY: guaranteed by the caller.
        unsafe { words.add(i).as_ref() }.store(0, Relaxed);
    }
}
