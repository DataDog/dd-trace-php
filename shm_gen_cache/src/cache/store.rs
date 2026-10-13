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
            None,
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
    ///
    /// `promotion` is the epoch whose arena the promoting lookup probed
    /// first, and missed. A promotion is published only there, and never
    /// rotates before it is published: it is dropped, successfully, if the
    /// epoch has moved on or the arena has no room for it. Once published,
    /// it may trigger a rotation at the occupancy target, like an insert. A
    /// newer value of the key in that arena wins, since a promotion
    /// replaces no entry (see table_put()), and one in a later arena is
    /// probed first. Published in a later arena, the promotion would shadow
    /// a newer value it cannot see.
    #[inline]
    #[allow(clippy::too_many_arguments)]
    pub(crate) fn store<V: ValueSource>(
        self,
        slot: &ParticipantSlot,
        slot_index: u32,
        hash: u64,
        key: &[u8],
        value: V,
        promotion: Option<u64>,
        estimator: &mut <P::Occupancy as OccupancyMode>::Estimator,
    ) -> Result<(), Error> {
        let hp = self.params.hot();
        let lengths = RecordLengths::new(key.len() as u32, value.logical_size() as u32);
        let len = lengths.payload_size();
        loop {
            // reserve() leaves the participant pinned on success, unpinned
            // on error or for a dropped promotion.
            let Some((record_offset, e)) =
                self.reserve(&hp, slot, slot_index, len, promotion, estimator)?
            else {
                return Ok(());
            };
            let arena = self.arena_of(&hp, e);
            let words = arena.words_at(record_offset);
            // SAFETY (the stores below): reserve() returned
            // record_size(len) bytes at record_offset, which hold the
            // header, the lengths and the padded key and value.
            unsafe { (*words.add(1)).store(lengths.0, Relaxed) }; // W4
            let w: u32 = 2;
            let w = unsafe { emit_bytes_relaxed(words, w, key.as_ptr(), key.len()) }; // W4
            let _ = unsafe { value.emit(words, w) }; // W4
            // W5: the header, after the payload. Relaxed: readers reach a
            // record only through the index, whose release (I2) follows.
            unsafe { (*words).store(RecordHeader::new(e as u32, len).0, Relaxed) };

            let result = self.publish(
                &hp,
                arena,
                hash,
                key,
                record_offset,
                e,
                promotion.is_some(),
                estimator,
            );

            // Stay pinned through I2 and W6 (the occupancy update in
            // publish()); W7 unpins.
            #[cfg(feature = "verify")]
            <P::Pid as GetPid>::after_record_publication(slot);
            slot.unpin(); // W7
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
                    // reservation can retry publication; a promotion is
                    // dropped instead.
                    if promotion.is_some() {
                        return Ok(());
                    }
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
                // W6: occupancy accounting, after I2.
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
    /// on error the participant is unpinned. With `only_epoch` (a
    /// promotion), reserves only in that epoch and never rotates: `None`,
    /// unpinned, if the current epoch is another or its arena has no room.
    #[inline(always)]
    #[allow(clippy::too_many_arguments)]
    fn reserve(
        self,
        hp: &HotParams,
        slot: &ParticipantSlot,
        slot_index: u32,
        len: u32,
        only_epoch: Option<u64>,
        estimator: &mut <P::Occupancy as OccupancyMode>::Estimator,
    ) -> Result<Option<(u32, u64)>, Error> {
        let capacity = hp.record_area_size as u64;
        let chunk_size = hp.chunk_size as u64;
        let n = RecordHeader::record_size(len);
        loop {
            let e = self.global_epoch().load(Acquire); // W1
            // Observes the chosen epoch without adding inter-thread
            // ordering.
            #[cfg(feature = "verify")]
            <P::Pid as GetPid>::reservation_epoch_hook(slot, e);
            if only_epoch.is_some_and(|only| only != e) {
                return Ok(None);
            }
            slot.pinned_epoch.store(e, Release); // W2: pin

            // W3. It does two things (docs/invariants.html, PN3, HT1):
            // 1. It is the store-buffering partner of the W3 that precedes
            //    the pin scan (R3) of the rotation that reuses this arena, so
            //    the scan sees this pin even for a write into an existing
            //    chunk, which makes no RMW on the control word.
            // 2. As a release fence, it synchronises with the acquire fence
            //    D6 of a lookup whose loads read this attempt's stores, so
            //    V2 sees this epoch and rejects a copy from a recycled arena.
            fence(SeqCst); // W3

            let arena = self.arena_of(hp, e);
            let stamp = e as u32;
            let ctl = CtlWord(arena.ctl().load(Relaxed)); // B0
            'attempt: {
                // B0 (with the control load above): the occupancy target
                // and the incarnation check. A chunk belongs to one full
                // epoch and may only be used while this check still sees
                // that arena incarnation unsealed. The global bump may
                // already be full: this participant's tail was claimed
                // earlier.
                if ctl.epoch() != stamp
                    || ctl.sealed()
                    || <P::Occupancy as OccupancyMode>::target_reached(arena, hp, e, estimator)
                {
                    break 'attempt;
                }
                // H1: cut the record from this slot's chunk.
                if slot.chunk_epoch.load(Relaxed) == e {
                    let cursor = slot.chunk_cursor.load(Relaxed);
                    if slot.chunk_end.load(Relaxed).wrapping_sub(cursor) as u64 >= n {
                        slot.chunk_cursor
                            .store(cursor.wrapping_add(n as u32), Relaxed);
                        return Ok(Some((cursor, e)));
                    }
                }
                if ctl.bump() as u64 + n > capacity {
                    break 'attempt;
                }
                // Claim a chunk for a smaller record when it fits; otherwise
                // claim exactly the record. Racing adds or a seal after B0
                // can make the add fail, leaving an unindexed hole beyond
                // capacity or in a sealed arena. The add still lands in this
                // incarnation, and a slot fails at most one add per
                // incarnation, so the configuration bounds the overshoot
                // (docs/invariants.html, SP1, SP2).
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
                        // H2: keep the rest of the chunk.
                        slot.chunk_epoch.store(e, Relaxed);
                        slot.chunk_cursor
                            .store(old.bump().wrapping_add(n as u32), Relaxed);
                        // Clipped: a raced precheck may claim past capacity.
                        let end = (old.bump() as u64 + claim).min(capacity);
                        slot.chunk_end.store(end as u32, Relaxed);
                    }
                    return Ok(Some((old.bump(), e)));
                }
                // A failed add: its bytes are lost (overshoot), never rolled
                // back.
            }

            // rotate_and_retry: test-local pruning of abandoned attempts;
            // adds no synchronisation.
            #[cfg(feature = "verify")]
            <P::Pid as GetPid>::reservation_retry_hook(slot);
            slot.unpin(); // W7
            if only_epoch.is_some() {
                return Ok(None);
            }
            match self.rotate(slot, slot_index, e, RotationPolicy::WaitForOwner) {
                Ok(()) | Err(Error::ConcurrentOperation) => {}
                Err(err) => return Err(err),
            }
        }
    }
}
