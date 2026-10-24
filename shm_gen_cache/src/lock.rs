//! A registered participant.

use core::marker::PhantomData;
use core::sync::atomic::Ordering::Relaxed;

use crate::cache::Cache;
use crate::cache::promotion::PromotionSampler;
use crate::config::Params;
use crate::error::Error;
use crate::occupancy::OccupancyMode;
use crate::output::OutputBuffer;
use crate::participant::{ParticipantSlot, UNPINNED};

/// A participant registration: the right to insert into and look up in a
/// cache. Process-local, bound to the registering thread (hence `!Send`):
/// it must be used and dropped there. Dropping releases the slot; the cache
/// mapping must outlive it.
///
/// Field order is fixed so that the parts lookups and inserts read (cache
/// base and hot parameters, slot) come first.
#[repr(C)]
pub struct ParticipantLock<'m, P: Params> {
    cache: Cache<'m, P>,
    slot: &'m ParticipantSlot,
    slot_index: u32,
    /// This registration's process-local view of the current arena's load
    /// (zero-sized in exact mode).
    estimator: <P::Occupancy as OccupancyMode>::Estimator,
    promotion_sampler: PromotionSampler,
    _not_send: PhantomData<*mut ()>,
}

impl<'m, P: Params> ParticipantLock<'m, P> {
    /// # Safety
    /// Slot `slot_index` of `cache` must be REGISTERED to the calling
    /// thread.
    #[inline]
    pub(crate) unsafe fn new(cache: Cache<'m, P>, slot_index: u32) -> Self {
        let hp = cache.params().hot();
        let slot = cache.participant(&hp, slot_index);
        ParticipantLock {
            cache,
            slot,
            slot_index,
            estimator: Default::default(),
            promotion_sampler: PromotionSampler::new(slot.registration_id::<P::Pid>()),
            _not_send: PhantomData,
        }
    }

    /// Inserts or replaces `key` -> `value`. Use the same hash for a key in
    /// every process. Required work can fail before acceptance
    /// ([`Error::KeyTooLarge`], [`Error::ValueTooLarge`], reservation and
    /// rotation errors); ordinary maintenance errors after it are ignored.
    #[inline]
    pub fn insert(&mut self, hash: u64, key: &[u8], value: &[u8]) -> Result<(), Error> {
        let _unpin = UnpinOnExit(self.slot);
        self.assert_usable();
        let cache = self.cache;
        cache.insert(
            self.slot,
            self.slot_index,
            hash,
            key,
            value,
            &mut self.estimator,
        )
    }

    /// Looks `key` up. A hit is a slice of `output` holding exactly the
    /// value (possibly empty); a miss is `None`. A hit larger than the
    /// buffer's capacity is [`Error::InsufficientCapacity`]. Output may be
    /// modified through the last value word even on a miss or error.
    #[inline]
    pub fn lookup<'o, const W: usize>(
        &mut self,
        hash: u64,
        key: &[u8],
        output: &'o mut OutputBuffer<W>,
    ) -> Result<Option<&'o [u8]>, Error> {
        let capacity = output.capacity();
        // SAFETY: the buffer holds W words and capacity <= 8 * W; the
        // borrow checker prevents key from overlapping it.
        let found =
            unsafe { self.lookup_raw(hash, key, output.words_mut().as_mut_ptr(), W, capacity)? };
        Ok(found.map(|len| &output.bytes()[..len]))
    }

    /// Looks `key` up, writing a hit's value into `output` (whole words; at
    /// most `align8(capacity)` bytes) and returning its length.
    ///
    /// The key must not overlap the `output_words` words of `output`
    /// ([`Error::InvalidArgument`]).
    ///
    /// # Safety
    /// `output` must be valid for writes of `output_words` words, and
    /// `capacity <= 8 * output_words`.
    #[inline(always)]
    pub unsafe fn lookup_raw(
        &mut self,
        hash: u64,
        key: &[u8],
        output: *mut u64,
        output_words: usize,
        capacity: usize,
    ) -> Result<Option<usize>, Error> {
        let _unpin = UnpinOnExit(self.slot);
        self.assert_usable();
        let cache = self.cache;
        // SAFETY: forwarded from the caller.
        unsafe {
            cache.lookup(
                self.slot,
                self.slot_index,
                hash,
                key,
                output,
                output_words,
                capacity,
                &mut self.estimator,
                &mut self.promotion_sampler,
            )
        }
    }

    /// The cache this registration belongs to.
    #[inline(always)]
    pub fn cache(&self) -> Cache<'m, P> {
        self.cache
    }

    /// This registration's slot.
    #[inline(always)]
    pub fn slot(&self) -> &'m ParticipantSlot {
        self.slot
    }

    /// The index of this registration's slot.
    #[inline(always)]
    pub fn slot_index(&self) -> u32 {
        self.slot_index
    }

    #[inline(always)]
    fn assert_usable(&self) {
        production_assert!(self.slot.registration_id::<P::Pid>() != 0);
        production_assert!(
            self.slot
                .pinned_epoch
                .load(core::sync::atomic::Ordering::Relaxed)
                == UNPINNED
        );
    }
}

impl<P: Params> Drop for ParticipantLock<'_, P> {
    #[inline]
    fn drop(&mut self) {
        drop(UnpinOnExit(self.slot));
        self.slot.release_claim::<P::Pid>();
    }
}

/// Preserve the pin boundary even when a helper unwinds and the caller
/// catches the panic while keeping its registration. Normal paths already
/// ran W7, so they only load the zero pin here.
struct UnpinOnExit<'a>(&'a ParticipantSlot);

impl Drop for UnpinOnExit<'_> {
    #[inline]
    fn drop(&mut self) {
        // An own W2 cannot be missed by this later load. R3A can only add
        // its waiting flag to a nonzero pin; unpin clears it and wakes the
        // waiter. If the load reads zero, R3A cannot install a flag over it.
        if self.0.pinned_epoch.load(Relaxed) != UNPINNED {
            self.0.unpin();
        }
    }
}
