//! Insertion: reservation, record store and index publication.

use core::sync::atomic::Ordering::{Acquire, Relaxed, Release, SeqCst};
use core::sync::atomic::{AtomicU64, fence};

use super::Cache;
use super::rotation::RotationPolicy;
use crate::arena::ctl::CtlWord;
use crate::arena::records::{RecordHeader, RecordLengths};
use crate::arena::{ArenaView, PutStatus, table_put};
use crate::config::{HotParams, Params};
use crate::error::Error;
use crate::occupancy::OccupancyMode;
use crate::participant::ParticipantSlot;
use crate::util::{emit_bytes_relaxed, emit_words_relaxed};

#[cfg(feature = "verify")]
use crate::pid::GetPid;

/// The value bytes of a store: the caller's bytes (insert) or the padded
/// words a lookup copied (promotion). A type parameter rather than a runtime
/// variant: each store is monomorphised for its source, with the same
/// stores and widths.
pub(crate) trait ValueSource: Copy {
    /// Bytes of the value.
    fn logical_size(self) -> usize;

    /// Appends the value to `words` from word index `w`; returns the next
    /// free index.
    ///
    /// # Safety
    /// `words[w..w + ceil(logical_size / 8))` must be valid record words.
    unsafe fn emit(self, words: *const AtomicU64, w: u32) -> u32;
}

/// Caller bytes: whole words, then a zero-padded tail composed without
/// reading past the value.
#[derive(Clone, Copy)]
pub(crate) struct ByteValue<'a>(pub(crate) &'a [u8]);

impl ValueSource for ByteValue<'_> {
    #[inline(always)]
    fn logical_size(self) -> usize {
        self.0.len()
    }

    #[inline(always)]
    unsafe fn emit(self, words: *const AtomicU64, w: u32) -> u32 {
        // SAFETY: guaranteed by the caller; the slice is readable.
        unsafe { emit_bytes_relaxed(words, w, self.0.as_ptr(), self.0.len()) }
    }
}

/// Already-padded words (a validated lookup copy), stored whole so the
/// final logical word is not narrowed back to a byte-sized tail copy.
#[derive(Clone, Copy)]
pub(crate) struct PaddedValue {
    pub(crate) words: *const u64,
    pub(crate) logical_size: usize,
}

impl ValueSource for PaddedValue {
    #[inline(always)]
    fn logical_size(self) -> usize {
        self.logical_size
    }

    #[inline(always)]
    unsafe fn emit(self, words: *const AtomicU64, w: u32) -> u32 {
        // SAFETY: guaranteed by the caller; `self.words` holds
        // align8(logical_size) bytes (lookup's output).
        unsafe { emit_words_relaxed(words, w, self.words, self.logical_size.div_ceil(8)) }
    }
}

impl<P: Params> Cache<'_, P> {
    /// Inserts or replaces `key`. Required work can fail before acceptance;
    /// ordinary maintenance errors after it are ignored.
    #[inline]
    pub(crate) fn insert(
        self,
        slot: &ParticipantSlot,
        slot_index: u32,
        hash: u64,
        key: &[u8],
        value: &[u8],
        estimator: &mut <P::Occupancy as OccupancyMode>::Estimator,
    ) -> Result<(), Error> {
        let hp = self.params.hot();
        if key.len() > hp.max_key_size as usize {
            return Err(Error::KeyTooLarge);
        }
        if value.len() > hp.max_value_size as usize {
            return Err(Error::ValueTooLarge);
        }
        self.store(
            slot,
            slot_index,
            hash,
            key,
            ByteValue(value),
            false,
            estimator,
        )
    }

    /// Writes a record for `key` and indexes it.
    ///
    /// Before acceptance, required reservation/rotation errors propagate.
    /// After acceptance, rotation is best-effort maintenance: ordinary
    /// errors cannot turn a published insert or promotion into failure.
    /// Every return leaves the participant unpinned; structural corruption
    /// is never suppressed.
    #[inline]
    #[allow(clippy::too_many_arguments)]
    pub(crate) fn store<V: ValueSource>(
        self,
        slot: &ParticipantSlot,
        slot_index: u32,
        hash: u64,
        key: &[u8],
        value: V,
        promotion: bool,
        estimator: &mut <P::Occupancy as OccupancyMode>::Estimator,
    ) -> Result<(), Error> {
        let hp = self.params.hot();
        let lengths = RecordLengths::new(key.len() as u32, value.logical_size() as u32);
        let len = lengths.payload_size();
        loop {
            // reserve() leaves the participant pinned on success, unpinned
            // on error.
            let (record_offset, e) = self.reserve(&hp, slot, slot_index, len, estimator)?;
            let arena = self.arena_of(&hp, e);
            let words = arena.words_at(record_offset);
            // SAFETY (the stores below): reserve() returned
            // record_size(len) bytes at record_offset, which hold the
            // header, the lengths and the padded key and value.
            unsafe { (*words.add(1)).store(lengths.0, Relaxed) };
            let w: u32 = 2;
            let w = unsafe { emit_bytes_relaxed(words, w, key.as_ptr(), key.len()) }; // W4
            let _ = unsafe { value.emit(words, w) }; // W4
            // W5: publish the header after the payload.
            unsafe { (*words).store(RecordHeader::new(e as u32, len).0, Release) };

            let result = self.publish(
                &hp,
                arena,
                hash,
                key,
                record_offset,
                e,
                promotion,
                estimator,
            );

            // W6: stay pinned through table publication and occupancy
            // updates.
            #[cfg(feature = "verify")]
            <P::Pid as GetPid>::after_record_publication(slot);
            slot.unpin();
            match result {
                PutStatus::Ok => return Ok(()),
                PutStatus::Full => {
                    // Publication is complete. Maintenance can defer to
                    // another owner or fail its pin/backend check without
                    // failing this accepted insert or promotion. Structural
                    // errors are preserved: rotate returns Corrupt when its
                    // slot reaping finds the state of a slot it claimed
                    // changed under it.
                    return match self.rotate(slot, slot_index, e, RotationPolicy::SkipBusyOwner) {
                        Err(Error::Corrupt) => Err(Error::Corrupt),
                        _ => Ok(()),
                    };
                }
                PutStatus::FullRejected => {
                    // No table bucket accepted this record (it stays
                    // unindexed). Rotation is required before a fresh
                    // reservation can retry publication.
                    match self.rotate(slot, slot_index, e, RotationPolicy::WaitForOwner) {
                        Ok(()) | Err(Error::ConcurrentOperation) => {}
                        Err(err) => return Err(err),
                    }
                }
            }
        }
    }

    /// Indexes the record at `record_offset` and reports whether this
    /// participant sees the arena at its occupancy target.
    #[inline(always)]
    #[allow(clippy::too_many_arguments)]
    fn publish(
        self,
        hp: &HotParams,
        arena: ArenaView<'_>,
        hash: u64,
        key: &[u8],
        record_offset: u32,
        epoch: u64,
        promotion: bool,
        estimator: &mut <P::Occupancy as OccupancyMode>::Estimator,
    ) -> PutStatus {
        table_put(
            arena,
            hp,
            hash,
            key,
            record_offset,
            epoch,
            promotion,
            |new_entry, home_occupied| {
                <P::Occupancy as OccupancyMode>::entry_published(
                    arena,
                    hp,
                    epoch,
                    estimator,
                    new_entry,
                    home_occupied,
                )
            },
        )
    }

    /// Reserves `record_size(len)` bytes in the current arena and pins the
    /// participant to its epoch. Returns the record offset and the epoch;
    /// on error the participant is unpinned.
    #[inline(always)]
    fn reserve(
        self,
        hp: &HotParams,
        slot: &ParticipantSlot,
        slot_index: u32,
        len: u32,
        estimator: &mut <P::Occupancy as OccupancyMode>::Estimator,
    ) -> Result<(u32, u64), Error> {
        let capacity = hp.record_area_size as u64;
        let chunk_size = hp.chunk_size as u64;
        let n = RecordHeader::record_size(len);
        loop {
            let e = self.global_epoch().load(Acquire); // W1
            // Observes the chosen epoch without adding inter-thread
            // ordering.
            #[cfg(feature = "verify")]
            <P::Pid as GetPid>::reservation_epoch_hook(slot, e);
            slot.pinned_epoch.store(e, Release); // W2: pin

            // This fence is doing two things:
            // 1. With R2 before every rotation's pin scan, it makes the pin
            //    visible even for a write into an existing chunk, which has
            //    no reservation RMW. A control load that sees this
            //    incarnation unsealed is coherence-ordered before its seal.
            //    That seal happens-before R2 of the rotation that reuses this
            //    arena, via epoch/ownership handoffs. Hence W3 precedes that
            //    R2 in the seq_cst order (the store-buffering argument), and
            //    its subsequent scan reads this pin or a later value. A later
            //    unpin or next pin is a release store after our record
            //    writes; a reaper's clear imports the dead owner's writes.
            //    Reuse therefore either waits for this writer or happens
            //    after it.
            // 2. It synchronises with the acquire fence D6 in probe() to
            //    establish the edge W3 -> D5 -> D6 -> V2, which lets a lookup
            //    reject payload words it copied from a recycled arena. The
            //    lookup catches this by rereading the epoch at the end (V2,
            //    seqlock style), so V2 must observe the epoch increment that
            //    allowed the reuse. W3 is also a release fence: the
            //    recycling writer read the new epoch (W1) before its release
            //    fence, and its payload stores follow the fence. If the
            //    lookup's relaxed copy (D5) reads any of these relaxed
            //    stores, its acquire fence (D6) synchronises-with W3, so the
            //    epoch load at W1 happens-before the epoch load V2, and
            //    read-read coherence forces V2 to read that epoch or a later
            //    one.
            fence(SeqCst); // W3

            let arena = self.arena_of(hp, e);
            let stamp = e as u32;
            let ctl = CtlWord(arena.ctl().load(Relaxed));
            'attempt: {
                // A chunk belongs to one full epoch and may only be used
                // while this check still sees that arena incarnation
                // unsealed. The global bump may already be full: this
                // participant's tail was claimed earlier.
                if ctl.epoch() != stamp
                    || ctl.sealed()
                    || <P::Occupancy as OccupancyMode>::target_reached(arena, hp, e, estimator)
                {
                    break 'attempt;
                }
                if slot.chunk_epoch.load(Relaxed) == e {
                    let cursor = slot.chunk_cursor.load(Relaxed);
                    if slot.chunk_end.load(Relaxed).wrapping_sub(cursor) as u64 >= n {
                        slot.chunk_cursor
                            .store(cursor.wrapping_add(n as u32), Relaxed);
                        return Ok((cursor, e));
                    }
                }
                if ctl.bump() as u64 + n > capacity {
                    break 'attempt;
                }
                // Claim a chunk for a smaller record when it fits; otherwise
                // claim exactly the record. A stale precheck can leave an
                // unindexed hole beyond capacity, in a sealed arena or in a
                // newer incarnation. The next precheck of this participant
                // sees its add or a later value (coherence), so it cannot add
                // again to that incarnation without catching up to its
                // epoch. At most two failed adds per participant and
                // incarnation are possible; the configuration checks bound
                // the overshoot.
                let claim = if n < chunk_size && ctl.bump() as u64 + chunk_size <= capacity {
                    chunk_size
                } else {
                    n
                };
                // B1: reserve record space.
                let old = CtlWord(
                    arena
                        .ctl()
                        .fetch_add(CtlWord::bump_increment(claim as u32), Relaxed),
                );
                if old.epoch() == stamp && !old.sealed() && old.bump() as u64 + n <= capacity {
                    if claim > n {
                        slot.chunk_epoch.store(e, Relaxed);
                        slot.chunk_cursor
                            .store(old.bump().wrapping_add(n as u32), Relaxed);
                        // Clipped: a raced precheck may claim past capacity.
                        let end = (old.bump() as u64 + claim).min(capacity);
                        slot.chunk_end.store(end as u32, Relaxed);
                    }
                    return Ok((old.bump(), e));
                }
                // A failed add: its bytes are lost (overshoot), never rolled
                // back.
            }

            // rotate_and_retry: test-local pruning of abandoned attempts;
            // adds no synchronisation.
            #[cfg(feature = "verify")]
            <P::Pid as GetPid>::reservation_retry_hook(slot);
            slot.unpin();
            match self.rotate(slot, slot_index, e, RotationPolicy::WaitForOwner) {
                Ok(()) | Err(Error::ConcurrentOperation) => {}
                Err(err) => return Err(err),
            }
        }
    }
}
