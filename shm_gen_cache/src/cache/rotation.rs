//! Rotation and rotation ownership.

use core::sync::atomic::Ordering::{AcqRel, Acquire, Relaxed, Release, SeqCst};
use core::sync::atomic::fence;

use super::{ARENA_COUNT, Cache};
use crate::arena::ctl::CtlWord;
use crate::config::{HotParams, Params};
use crate::error::Error;
use crate::occupancy::OccupancyMode;
use crate::participant::{ParticipantSlot, ROTATION_WAITING, UNPINNED, next_registration_id};
#[cfg(feature = "verify")]
use crate::pid::GetPid;
use crate::wait::{BoundedWait, Pause, WordHalf, wake_waiters};

/// How a rotation waits for the rotation owner.
#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub enum RotationPolicy {
    /// Wait for the owner (bounded by time), then take over a dead one.
    WaitForOwner,
    /// One CAS; [`Error::ConcurrentOperation`] if someone else owns it.
    SkipBusyOwner,
}

/// The rotation owner word: `{slot: bits 0-31, registration_id: bits
/// 32-63}`; registration id zero means no owner.
#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub struct RotationOwner(pub u64);

impl RotationOwner {
    /// No owner.
    pub const NONE: RotationOwner = RotationOwner(0);

    #[inline(always)]
    /// The owner word of `registration_id` in `slot`.
    pub const fn new(slot: u32, registration_id: u32) -> Self {
        RotationOwner(slot as u64 | (registration_id as u64) << 32)
    }

    #[inline(always)]
    /// The owner's slot.
    pub const fn slot(self) -> u32 {
        self.0 as u32
    }

    #[inline(always)]
    /// The owner's registration id (zero: no owner).
    pub const fn registration_id(self) -> u32 {
        (self.0 >> 32) as u32
    }
}

impl<'m, P: Params> Cache<'m, P> {
    /// Moves the cache from epoch `e` to `e + 1`, as the participant in slot
    /// `slot_index` (which must be unpinned).
    ///
    /// Seals the current arena, waits for every writer still pinned to the
    /// arena about to be reused (or reaps its dead slot), resets that
    /// arena's control word (and exact counter) and publishes the next
    /// epoch. [`Error::ConcurrentOperation`] means another rotation moved
    /// the epoch first. On any error after acquiring ownership the current
    /// arena stays sealed and a later rotator repeats the scan.
    pub(crate) fn rotate(
        self,
        slot: &ParticipantSlot,
        slot_index: u32,
        e: u64,
        policy: RotationPolicy,
    ) -> Result<(), Error> {
        let hp = self.params.hot();
        production_assert!(slot.pinned_epoch.load(Relaxed) == UNPINNED);
        self.acquire_rotation(&hp, slot, slot_index, e, policy)?;
        let result = self.rotate_owned(&hp, e);
        self.release_rotation();
        result
    }

    /// The part of [`Self::rotate`] run under rotation ownership, which the
    /// caller releases afterwards.
    fn rotate_owned(self, hp: &HotParams, e: u64) -> Result<(), Error> {
        // Check under exclusive ownership, not just before acquiring
        // rotation. A prior owner may have published e + 1 and died before
        // releasing it. The ownership/death handoff already orders
        // completed epoch updates.
        // R0: recheck the epoch under ownership.
        if self.global_epoch().load(Relaxed) != e {
            return Err(Error::ConcurrentOperation);
        }
        let cur_arena = self.arena_of(hp, e);
        let next_arena = self.arena_of(hp, e + 1);
        let mut cur_ctl = cur_arena.ctl().load(Relaxed);

        loop {
            if CtlWord(cur_ctl).epoch() != e as u32 {
                return Err(Error::ConcurrentOperation);
            }
            if CtlWord(cur_ctl).sealed() {
                // A prior owner sealed it, then died or returned an error.
                // An error handoff is release/acquire through the rotation
                // owner; a dead-owner takeover follows registration_is_live()
                // == false: either is_live(false), whose backend contract
                // imports the dead incarnation's final shared-memory
                // operations, or an acquire of a later state of its slot,
                // which continues the release sequence of a reaper's steal
                // of the slot, published only after the reaper's own
                // is_live(false). Both paths carry the sealing RMW's acquired
                // reservation history despite this relaxed control load, and
                // we don't need the handoff provided by the CAS below.
                break;
            }
            // R1: retire the current arena. Acquire imports prior
            // reservation RMW history through epoch and ownership handoffs.
            // Writers into existing chunks have no RMW here: their pins are
            // protected by W3/R2 instead.
            match cur_arena.ctl().compare_exchange(
                cur_ctl,
                cur_ctl | CtlWord::SEALED,
                Acquire,
                Relaxed,
            ) {
                Ok(_) => break,
                Err(actual) => cur_ctl = actual,
            }
        }

        // R2. The scan below must read the pin of every writer that may
        // still write into arena (e + 1) % 3, which this rotation reuses, or
        // a later value of that pin. Such a writer pinned e - 2, executed W3
        // and then saw that incarnation unsealed. A later value is an unpin
        // or next pin, released after the writer's record writes, or a
        // reaper's clear, which imports a dead writer's writes. Either way
        // reuse waits for the writer or happens after it.
        //
        // By [atomics.order]/4.4 on the pin, the scan reads that pin or a
        // later value if some seq_cst fence F before the scan follows the
        // writer's W3 in the seq_cst order S. Otherwise, a scan reading an
        // older value would put F before the writer's W3 in S.
        //
        // The writer's W3 precedes F in S if seal(e - 2) happens-before F.
        // By the same rule on the control word: the writer's W3
        // happens-before its control load, which saw the arena unsealed and
        // so is coherence-ordered before the seal. R1 sealed arena e
        // instead, whose writers the scan does not wait for; the seal that
        // matters is older.
        //
        // That seal happens-before every fence this rotator executes after
        // loading e:
        //
        //   seal(e - 2) --> R5 publishing e - 1 --> ... --> R5 publishing e
        //     --rf/sw--> W1 of this rotator (acquire, read e)
        //     --sb--> W3 (in reserve()) --sb--> R2 --sb--> scan
        //
        // Each rotation seals before publishing its epoch, and a takeover
        // rotator imports a dead owner's seal through the liveness handoff
        // described above. Every rotation starts from the epoch its
        // predecessor released.
        //
        // Both W3 and R2 qualify as F. R2 is currently redundant because
        // every caller calls reserve() before rotate(). R2 keeps rotate()
        // correct for a future caller that does not. Rotation is rare, so the
        // fence is cheap.
        fence(SeqCst);
        for i in 0..hp.participant_capacity {
            let participant = self.participant(hp, i);
            let mut wait = BoundedWait::new();
            // R3: wait for it to be unpinned. Only pins at e - 2 or older
            // block: they write into the arena being reused.
            loop {
                // Possibly with our own ROTATION_WAITING from a previous
                // pause.
                let word = participant.pinned_epoch.load(Acquire);
                let pinned_epoch = word & !ROTATION_WAITING;
                if pinned_epoch == UNPINNED || e < pinned_epoch.wrapping_add(2) {
                    break;
                }
                // Before blocking in a futex, flag the pin, so that the
                // unpin wakes us; fails if the pinner wrote its slot.
                let announce = || {
                    participant
                        .pinned_epoch
                        .compare_exchange(word, pinned_epoch | ROTATION_WAITING, Relaxed, Relaxed)
                        .is_ok()
                };
                // The pinner's next write to its slot (an unpin or a new
                // pin) changes the low half and ends this wait. The flag is
                // in the high half, so announcing does not.
                let watched = WordHalf::low(&participant.pinned_epoch).changed_from(word);
                if wait.pause_until_announced(watched, announce) == Pause::Continue {
                    continue;
                }
                let error = match self.reap_pinner(participant) {
                    // Reload with acquire even after successful reaping
                    // (the exhausted wait is not restarted).
                    Ok(true) => continue,
                    // Keep the arena sealed, but let another rotator retry
                    // its scan after a timeout or backend failure.
                    Ok(false) => Error::ArenaReuseTimeout,
                    Err(error) => error,
                };
                return Err(error);
            }
        }
        // R4: arena reuse resets only the control word (and the exact
        // counter); the index and records are rejected by their stamps.
        if !<P::Occupancy as OccupancyMode>::ESTIMATES {
            next_arena.exact_occupancy().store(0, Relaxed);
        }
        next_arena
            .ctl()
            .store(CtlWord::new((e + 1) as u32, false, 0).0, Relaxed);
        self.global_epoch().store(e + 1, Release); // R5
        #[cfg(feature = "verify")]
        <P::Pid as GetPid>::after_epoch_publication();
        Ok(())
    }

    /// Slot reaping by a rotation (R3): steals a dead pinner's slot, which
    /// clears its pin, then releases it. Returns whether it reaped
    /// the slot. Until the release, the rotator holds a second registration.
    fn reap_pinner(self, participant: &ParticipantSlot) -> Result<bool, Error> {
        let registration_id = next_registration_id(&self.header().registration_counter);
        if !participant.steal::<P::Pid>(registration_id)? {
            return Ok(false);
        }
        participant.release_claim::<P::Pid>();
        Ok(true)
    }

    /// The half of the owner word that every release changes (the
    /// registration id), on which ownership waiters block.
    #[inline(always)]
    fn owner_futex_word(self) -> WordHalf<'m> {
        WordHalf::high(&self.header().rotation_owner)
    }

    /// Releases rotation ownership and wakes the participants waiting for
    /// it.
    #[inline]
    fn release_rotation(self) {
        self.header()
            .rotation_owner
            .store(RotationOwner::NONE.0, Release); // O4: release ownership
        wake_waiters(self.owner_futex_word());
    }

    /// Becomes the rotation owner for epoch `e`.
    /// [`Error::ConcurrentOperation`] if the epoch moved (or, skipping a
    /// busy owner, if someone owns it); [`Error::RotationOwnerTimeout`]
    /// after the wait budget if the owner is live or the takeover fails.
    pub(crate) fn acquire_rotation(
        self,
        hp: &HotParams,
        slot: &ParticipantSlot,
        slot_index: u32,
        e: u64,
        policy: RotationPolicy,
    ) -> Result<(), Error> {
        production_assert!(slot_index < hp.participant_capacity);
        let owner_word = &self.header().rotation_owner;
        let desired = RotationOwner::new(slot_index, slot.registration_id::<P::Pid>());
        if policy == RotationPolicy::SkipBusyOwner {
            if self.global_epoch().load(Relaxed) != e {
                return Err(Error::ConcurrentOperation);
            }
            // One strong CAS both checks and claims ownership. A separate
            // precheck followed by the waiting path could race into a wait.
            // O2: take ownership from NONE.
            return match owner_word.compare_exchange(
                RotationOwner::NONE.0,
                desired.0,
                AcqRel,
                Acquire,
            ) {
                Ok(_) => Ok(()),
                Err(_) => Err(Error::ConcurrentOperation),
            };
        }
        let mut wait = BoundedWait::new();

        loop {
            if self.global_epoch().load(Relaxed) != e {
                return Err(Error::ConcurrentOperation);
            }
            // See the precondition of registration_is_live().
            let owner = RotationOwner(owner_word.load(Acquire)); // O1
            if owner.registration_id() == 0 {
                // O2: take ownership from NONE.
                if owner_word
                    .compare_exchange(owner.0, desired.0, AcqRel, Acquire)
                    .is_ok()
                {
                    return Ok(());
                }
                // A failed CAS on an empty owner does not pause.
                continue;
            }
            // The owner ends this wait by releasing ownership, which zeroes
            // the registration id. Its epoch publication, on the same cache
            // line, ends a monitored sleep, but not a futex block; nor does
            // a release that the same participant follows with a new
            // acquisition before this pause compares the id (owner word
            // ABA), which only delays the waiter until that rotation's
            // release, or until its budget ends.
            let watched = self.owner_futex_word().changed_from(owner.0);
            if wait.pause_until(watched) == Pause::Continue {
                continue;
            }
            production_assert!(owner.slot() < hp.participant_capacity);
            let live = self
                .participant(hp, owner.slot())
                .registration_is_live::<P::Pid>(owner.registration_id())?;
            // O3: takeover of a dead owner.
            if !live
                && owner_word
                    .compare_exchange(owner.0, desired.0, AcqRel, Acquire)
                    .is_ok()
            {
                return Ok(());
            }
            return Err(Error::RotationOwnerTimeout);
        }
    }
}

const _: () = assert!(ARENA_COUNT == 3);
