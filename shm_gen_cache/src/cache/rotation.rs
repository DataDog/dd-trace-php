//! Rotation and rotation ownership.

use core::sync::atomic::Ordering::{AcqRel, Acquire, Relaxed, Release, SeqCst};
use core::sync::atomic::fence;

use super::{ARENA_COUNT, Cache};
use crate::arena::ctl::CtlWord;
use crate::config::{HotParams, Params};
use crate::error::Error;
use crate::occupancy::OccupancyMode;
use crate::participant::{
    ParticipantSlot, ROTATION_WAITING, RegistrationId, Steal, UNPINNED, next_registration_id,
};
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
    /// Seals the current arena, fences (R1F), waits for every writer still
    /// pinned to the arena about to be reused (or reaps its dead slot),
    /// certifies the just-sealed generation `e` as a promotion source if
    /// the same scan saw no pin of `e` (R3C; pins of `e` are neither
    /// awaited nor reaped), resets the reused arena's control word (and
    /// exact counter) and publishes the next epoch.
    /// [`Error::ConcurrentOperation`] means another rotation moved the
    /// epoch first. On any error after acquiring ownership the current
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
        let _owner = ReleaseRotation(self);
        self.rotate_owned(&hp, e)
    }

    /// The part of [`Self::rotate`] run under rotation ownership, which the
    /// caller releases afterwards.
    fn rotate_owned(self, hp: &HotParams, e: u64) -> Result<(), Error> {
        // R0: recheck the epoch under ownership, not just before acquiring
        // it: a prior owner may have published e + 1 and died before
        // releasing ownership. The ownership handoff orders its R5 before
        // this load (docs/invariants.html, OW2, EP1).
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
                // A prior tenure sealed it (Z(e)), then died or returned an
                // error. The ownership handoff, including a takeover through
                // reap_owner(), makes that seal happen-before this tenure's
                // R1F and R5 despite this relaxed load (docs/invariants.html,
                // OW1, OW2, EP3).
                break;
            }
            // R1: seal the current arena, keeping its stamp and bump.
            // Relaxed: what matters is that the seal happens-before this
            // tenure's own R1F (for the readiness scan) and the R1F of the
            // rotator that later reuses this arena, which program order and
            // the ownership handoffs provide whatever the seal's own
            // ordering (docs/invariants.html, EP2, EP3).
            match cur_arena.ctl().compare_exchange(
                cur_ctl,
                cur_ctl | CtlWord::SEALED,
                Relaxed,
                Relaxed,
            ) {
                Ok(_) => break,
                Err(actual) => cur_ctl = actual,
            }
        }

        // R1F. The seal happens-before it, so it is the store-buffering
        // partner of every writer's W3 for both scans below: the reuse
        // scan of the arena about to be reused, and the readiness scan of
        // the just-sealed e, in which an accepted e writer's pin or a
        // later value must appear (docs/invariants.html, PN3, QU1, RD1).
        fence(SeqCst);
        let mut promotion_ready = true;
        for i in 0..hp.participant_capacity {
            let participant = self.participant(hp, i);
            let mut wait = BoundedWait::new();
            // R3: wait for it to be unpinned. Only pins at e - 2 or older
            // block: they write into the arena being reused
            // (docs/invariants.html, QU1).
            loop {
                // Possibly with our own ROTATION_WAITING from a previous
                // pause.
                let word = participant.pinned_epoch.load(Acquire);
                let pinned_epoch = word & !ROTATION_WAITING;
                if pinned_epoch == e {
                    // Never wait for or reap writers in the newly sealed
                    // generation. Promotions will occasionally recheck.
                    promotion_ready = false;
                }
                if pinned_epoch == UNPINNED || e < pinned_epoch.wrapping_add(2) {
                    break;
                }
                // R3A: before blocking in a futex, flag the pin, so that the
                // unpin wakes us; fails if the pinner wrote its slot. An RMW
                // keeping the pinned epoch, so it stays in the release
                // sequence of the pin store it replaces (docs/invariants.html,
                // PN1).
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
        // R3C: no slot was pinned to e, so every writer of e has finished
        // (docs/invariants.html, RD1).
        if promotion_ready {
            self.mark_promotion_ready(hp, e);
        }
        // R4: arena reuse resets only the control word (and the exact
        // counter); the index and records are rejected by their stamps
        // (docs/invariants.html, A6).
        if !<P::Occupancy as OccupancyMode>::ESTIMATES {
            next_arena.exact_occupancy().store(0, Relaxed);
        }
        next_arena
            .ctl()
            .store(CtlWord::new((e + 1) as u32, false, 0).0, Relaxed);
        #[cfg(feature = "verify")]
        <P::Pid as GetPid>::after_arena_reset();
        self.global_epoch().store(e + 1, Release); // R5
        #[cfg(feature = "verify")]
        <P::Pid as GetPid>::after_epoch_publication();
        Ok(())
    }

    /// Slot reaping by a rotation (R3): steals a dead pinner's slot, which
    /// clears its pin, then releases it. Returns whether it reaped
    /// the slot. Until the release, the rotator holds a second registration.
    fn reap_pinner(self, participant: &ParticipantSlot) -> Result<bool, Error> {
        let counter = &self.header().registration_counter;
        if participant.steal::<P::Pid>(None, || next_registration_id(counter))? != Steal::Stolen {
            return Ok(false);
        }
        participant.release_claim::<P::Pid>();
        Ok(true)
    }

    /// Slot reaping by a rotation-owner takeover: whether `owner`, which the
    /// caller read in the rotation-owner word (O1, with acquire), is over,
    /// so that the caller may take ownership away from it (O3). Steals and
    /// releases the owner's slot if it still holds the owner's dead claim.
    ///
    /// Every outcome but `Live` synchronises with what ended `owner`'s
    /// registration (its P3, a reaper's P4, or, for `Stolen`, its death
    /// through the dead verdict), so everything `owner` did happens before
    /// O3: there is one owner at a time, and the new owner sees the dead
    /// owner's R1 and R5 (docs/invariants.html, OW1, OW2).
    ///
    /// Only `owner`'s own claim is examined. Another registration's claim
    /// already shows that `owner` is over: the takeover neither asks the
    /// backend about it nor steals it, which is left to registrations and
    /// pin scans. Counting a live one as `owner` being live would block
    /// rotation for good once a reaper keeps a dead owner's slot: the owner
    /// word would name the dead owner forever.
    fn reap_owner(self, hp: &HotParams, owner: RotationOwner) -> Result<bool, Error> {
        production_assert!(owner.slot() < hp.participant_capacity);
        let participant = self.participant(hp, owner.slot());
        let expected = Some(RegistrationId(owner.registration_id()));
        let counter = &self.header().registration_counter;
        match participant.steal::<P::Pid>(expected, || next_registration_id(counter))? {
            Steal::Live => Ok(false),
            Steal::Stolen => {
                participant.release_claim::<P::Pid>();
                Ok(true)
            }
            Steal::Lost | Steal::Other | Steal::Free => Ok(true),
        }
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
            // O0: epoch precheck.
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
                Relaxed,
            ) {
                Ok(_) => Ok(()),
                Err(_) => Err(Error::ConcurrentOperation),
            };
        }
        let mut wait = BoundedWait::new();

        loop {
            // O0: epoch precheck.
            if self.global_epoch().load(Relaxed) != e {
                return Err(Error::ConcurrentOperation);
            }
            // Acquire: reap_owner() relies on it (docs/invariants.html, OW1).
            let owner = RotationOwner(owner_word.load(Acquire)); // O1
            if owner.registration_id() == 0 {
                // O2: take ownership from NONE.
                if owner_word
                    .compare_exchange(owner.0, desired.0, AcqRel, Relaxed)
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
            if !self.reap_owner(hp, owner)? {
                return Err(Error::RotationOwnerTimeout);
            }
            // O3: take over a dead owner. Release only: reap_owner() already
            // ordered everything `owner` did before this point; the release
            // serves the next taker's O1 (docs/invariants.html, OW1, OW2).
            if owner_word
                .compare_exchange(owner.0, desired.0, Release, Relaxed)
                .is_ok()
            {
                return Ok(());
            }
            return Err(Error::RotationOwnerTimeout);
        }
    }
}

/// A backend panic must release O4 before unwinding can drop the owning
/// registration (P3), or a later caller could take over a live tenure.
struct ReleaseRotation<'m, P: Params>(Cache<'m, P>);

impl<P: Params> Drop for ReleaseRotation<'_, P> {
    #[inline]
    fn drop(&mut self) {
        self.0.release_rotation();
    }
}

const _: () = assert!(ARENA_COUNT == 3);
