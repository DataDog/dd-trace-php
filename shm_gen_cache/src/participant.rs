//! Participant slots: registration, release and slot reaping.

use core::sync::atomic::Ordering::{Acquire, Relaxed, Release};
use core::sync::atomic::{AtomicU32, AtomicU64};

use crate::error::Error;
use crate::pid::GetPid;

/// Pin value of a participant that is not inserting.
pub(crate) const UNPINNED: u64 = 0;

/// Set on a pin by a rotation about to block on it in a futex: the
/// participant's unpin, an exchange, then sees it and wakes the rotation.
/// Outside the futex's (low) half, so setting it leaves the futex value
/// unchanged; epochs never reach it.
pub(crate) const ROTATION_WAITING: u64 = 1 << 63;

/// Returns the next registration id; zero is reserved, including after
/// wraparound.
#[inline]
pub(crate) fn next_registration_id(counter: &AtomicU32) -> u32 {
    loop {
        let id = counter.fetch_add(1, Relaxed);
        if id != 0 {
            return id;
        }
    }
}

/// A participant slot's state word.
///
/// Encoding (little-endian):
///
/// | state        | bits                                                   |
/// |--------------|--------------------------------------------------------|
/// | FREE         | `0`                                                    |
/// | INITIALIZING | bit 0 = 1, bits 1-31 pid, bits 32-63 registration id  |
/// | REGISTERED   | bit 0 = 0, bits 1-31 pid, bits 32-63 registration id  |
///
/// pid and registration id are non-zero in the last two. A claim goes
/// through INITIALIZING to REGISTERED, from FREE ([`ParticipantSlot::claim`])
/// or from a dead claim, which slot reaping steals
/// ([`ParticipantSlot::steal`]). After initialisation, every write of the
/// state is a read-modify-write.
#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub(crate) struct State(pub(crate) u64);

/// A decoded [`State`].
#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub(crate) enum StateKind {
    Free,
    Initializing { pid: u32, registration_id: u32 },
    Registered { pid: u32, registration_id: u32 },
}

impl State {
    pub(crate) const FREE: State = State(0);
    const PID_MASK: u64 = 0x7fff_ffff;

    #[inline(always)]
    pub(crate) fn initializing(pid: u32, registration_id: u32) -> State {
        State(Self::registered(pid, registration_id).0 | 1)
    }

    #[inline(always)]
    pub(crate) fn registered(pid: u32, registration_id: u32) -> State {
        production_assert!(pid != 0 && registration_id != 0);
        State((pid as u64 & Self::PID_MASK) << 1 | (registration_id as u64) << 32)
    }

    #[inline(always)]
    pub(crate) const fn pid(self) -> u32 {
        ((self.0 >> 1) & Self::PID_MASK) as u32
    }

    #[inline(always)]
    pub(crate) const fn registration_id(self) -> u32 {
        (self.0 >> 32) as u32
    }

    #[inline(always)]
    pub(crate) const fn kind(self) -> StateKind {
        if self.0 == State::FREE.0 {
            StateKind::Free
        } else if self.0 & 1 != 0 {
            StateKind::Initializing {
                pid: self.pid(),
                registration_id: self.registration_id(),
            }
        } else {
            StateKind::Registered {
                pid: self.pid(),
                registration_id: self.registration_id(),
            }
        }
    }
}

/// One participant's shared slot: a cache line of its own, so its pin and
/// chunk stores cannot invalidate a neighbouring participant's state.
///
/// The reservation chunk (`chunk_*`) is the unused tail `[cursor, end)` of
/// a participant-owned record-area chunk of epoch `chunk_epoch`. Only the
/// registered owner accesses it, after validating the full epoch and the
/// arena's unsealed incarnation. It is reset by every claim, including a
/// reaper's steal: a replacement must never inherit a departed owner's
/// partially consumed tail. Relaxed atomics match the other shared slot
/// metadata across death and registration handoffs.
#[cfg_attr(target_arch = "aarch64", repr(C, align(128)))]
#[cfg_attr(not(target_arch = "aarch64"), repr(C, align(64)))]
pub struct ParticipantSlot {
    pub(crate) pinned_epoch: AtomicU64,
    pub(crate) state: AtomicU64,
    /// On Linux, field 22 (`starttime`) of `/proc/<tid>/stat`; on Windows,
    /// the thread's creation FILETIME; zero if unknown.
    pub(crate) thread_disambiguation: AtomicU64,
    pub(crate) chunk_epoch: AtomicU64,
    pub(crate) chunk_cursor: AtomicU32,
    pub(crate) chunk_end: AtomicU32,
}

const _: () = {
    use core::mem::{align_of, offset_of, size_of};
    assert!(size_of::<ParticipantSlot>() == crate::CACHE_LINE);
    assert!(align_of::<ParticipantSlot>() == crate::CACHE_LINE);
    assert!(offset_of!(ParticipantSlot, pinned_epoch) == 0);
    assert!(offset_of!(ParticipantSlot, state) == 8);
    assert!(offset_of!(ParticipantSlot, thread_disambiguation) == 16);
    assert!(offset_of!(ParticipantSlot, chunk_epoch) == 24);
    assert!(offset_of!(ParticipantSlot, chunk_cursor) == 32);
    assert!(offset_of!(ParticipantSlot, chunk_end) == 36);
};

impl ParticipantSlot {
    /// Unpins, with release semantics. An exchange rather than a store, to
    /// see a [`ROTATION_WAITING`] set by a rotation blocked on this pin and
    /// wake it: its flag is either seen here or its compare-exchange fails.
    /// Slot reaping ([`Self::steal`]) clears a dead participant's pin with a
    /// store instead, and wakes the rotation whether or not it was flagged.
    #[inline(always)]
    pub(crate) fn unpin(&self) {
        if self.pinned_epoch.swap(UNPINNED, Release) & ROTATION_WAITING != 0 {
            self.wake_rotation();
        }
    }

    #[cold]
    #[inline(never)]
    fn wake_rotation(&self) {
        crate::wait::wake_waiters(crate::wait::WordHalf::low(&self.pinned_epoch));
    }

    /// Only used while initialising an unpublished cache mapping.
    #[inline]
    pub(crate) fn initialize(&self) {
        self.pinned_epoch.store(UNPINNED, Relaxed);
        self.state.store(State::FREE.0, Relaxed);
        self.thread_disambiguation.store(0, Relaxed);
        self.reset_chunk();
    }

    #[inline(always)]
    fn reset_chunk(&self) {
        self.chunk_epoch.store(0, Relaxed);
        self.chunk_cursor.store(0, Relaxed);
        self.chunk_end.store(0, Relaxed);
    }

    /// Claims this FREE slot for the calling thread. Fails with
    /// [`Error::ConcurrentOperation`] if it is not FREE.
    pub(crate) fn claim<Pid: GetPid>(&self, registration_id: u32) -> Result<(), Error> {
        let pid = Pid::get_pid();
        let start_time = Pid::get_start_time();
        let initializing = State::initializing(pid, registration_id);
        if self
            .state
            .compare_exchange(State::FREE.0, initializing.0, Acquire, Relaxed) // P1
            .is_err()
        {
            return Err(Error::ConcurrentOperation);
        }
        // A concurrent death check may observe a replacement's start time.
        // Publish the FREE/claim handoff through that observation as well.
        self.thread_disambiguation.store(start_time, Release); // T1
        production_assert!(self.pinned_epoch.load(Relaxed) == UNPINNED);
        self.reset_chunk();

        let registered = State::registered(pid, registration_id);
        if self
            .state
            .compare_exchange(initializing.0, registered.0, Release, Relaxed) // P2
            .is_err()
        {
            return Err(Error::Corrupt);
        }
        Ok(())
    }

    /// Releases the calling thread's registration (REGISTERED -> FREE).
    /// Aborts the process if the slot is not registered to the caller. The
    /// chunk is not reset here (the next claim resets it).
    pub(crate) fn release_claim<Pid: GetPid>(&self) {
        let current = State(self.state.load(Relaxed));
        let StateKind::Registered { pid, .. } = current.kind() else {
            crate::assert::fatal("released a participant slot that is not registered");
        };
        if pid != Pid::get_pid() {
            crate::assert::fatal("released a participant slot registered to another thread");
        }
        production_assert!(self.pinned_epoch.load(Relaxed) == UNPINNED);
        if self
            .state
            .compare_exchange(current.0, State::FREE.0, Release, Relaxed) // P3
            .is_err()
        {
            crate::assert::fatal("participant slot changed state during release");
        }
    }

    /// Slot reaping: if this slot's registration (or claim in progress) is
    /// dead, steals the slot for the calling thread as registration
    /// `registration_id`, clears the dead pin and wakes a rotation that may
    /// be blocked on it. Returns whether it stole the slot; the slot is then
    /// REGISTERED to the caller, as after [`Self::claim`].
    ///
    /// The steal is a claim of the dead slot, in the same two steps as
    /// [`Self::claim`]. A reaper that dies midway therefore leaves a claim of
    /// its own, which the next reaper finds dead and steals in turn. It
    /// shares the claim's one gap: while the slot is INITIALIZING, only the
    /// claimant's TID identifies it. If the reaper dies then and a live
    /// thread reuses the TID, the slot cannot be reaped (nor its dead pin
    /// cleared) until that thread exits.
    pub(crate) fn steal<Pid: GetPid>(&self, registration_id: u32) -> Result<bool, Error> {
        let current = State(self.state.load(Acquire));
        let dead_start_time = self.thread_disambiguation.load(Relaxed);
        let is_zombie = match current.kind() {
            StateKind::Registered { pid, .. } => !Pid::is_live_since(pid, dead_start_time)?,
            // The start time is not available yet.
            StateKind::Initializing { pid, .. } => !Pid::is_live(pid)?,
            StateKind::Free => false,
        };
        if !is_zombie {
            return Ok(false);
        }
        let pid = Pid::get_pid();
        let start_time = Pid::get_start_time();
        let initializing = State::initializing(pid, registration_id);
        // Release publishes the death verdict to every acquire of this state
        // or of a later one (see registration_is_live()). No acquire: every
        // claim's state word is unique (a fresh registration id), so a
        // successful exchange reads the very write the load above acquired.
        if self
            .state
            .compare_exchange(current.0, initializing.0, Release, Relaxed) // P4
            .is_err()
        {
            return Ok(false);
        }
        // As in claim(), a concurrent death check may pair the dead claim's
        // state with this start time: publish the verdict through that
        // observation as well.
        self.thread_disambiguation.store(start_time, Release); // T2
        let registered = State::registered(pid, registration_id);
        if self
            .state
            .compare_exchange(initializing.0, registered.0, Release, Relaxed) // P5
            .is_err()
        {
            return Err(Error::Corrupt);
        }
        // Clears the dead pin. The release stands in for the unpin() that
        // the dead participant never made: R3, the only acquiring reader of
        // the pin, must import the record writes the dead participant made
        // under it before reusing the arena. The release sequence of the pin
        // store (W2) does not cover them, since they follow it. The only
        // route is: the dead participant's writes -> the backend's verdict
        // -> this release -> R3's acquire of the cleared pin -> arena reuse.
        //
        // A store, not an exchange: the wake below does not depend on the
        // flag, and an acquire would import nothing the verdict did not. A
        // rotation flagging the pin concurrently either flags it before this
        // store, and is woken below, or fails its compare-exchange.
        self.pinned_epoch.store(UNPINNED, Release);
        // Wakes a rotation blocked on the dead pin even if the pin carried no
        // ROTATION_WAITING: a reaper that died after clearing the pin may
        // have overwritten the flag without waking the rotation, and only the
        // reaper of that reaper's slot is left to wake it. When the reaper is
        // that rotation itself (R3 after its budget), it is not blocked, and
        // the wake is one wasted system call.
        self.wake_rotation();
        self.reset_chunk();
        Ok(true)
    }

    /// Whether the slot is FREE.
    #[inline]
    pub(crate) fn is_free(&self) -> bool {
        self.state.load(Relaxed) == State::FREE.0
    }

    /// The owner's registration id. Only the registered owner may ask for
    /// its own identity.
    #[inline]
    pub(crate) fn registration_id<Pid: GetPid>(&self) -> u32 {
        let current = State(self.state.load(Relaxed));
        production_assert!(matches!(current.kind(), StateKind::Registered { .. }));
        production_assert!(current.pid() == Pid::get_pid());
        current.registration_id()
    }

    /// Whether `registration` still owns this slot and may run.
    ///
    /// The caller must first acquire the rotation-owner publication. This
    /// prevents observing a state from before that owner's registration.
    ///
    /// The comments below use the memory model's relations: sb
    /// (sequenced before, i.e. program order in one thread), sw
    /// (synchronises with: an acquire reads a release, or a later value
    /// in its release sequence) and hb (happens before: the transitive
    /// closure of sb and sw).
    pub(crate) fn registration_is_live<Pid: GetPid>(
        &self,
        registration: u32,
    ) -> Result<bool, Error> {
        // P6: the slot state, with acquire.
        match State(self.state.load(Acquire)).kind() {
            // A matching INITIALIZING state cannot follow a published owner;
            // conservatively wait if the precondition is violated.
            StateKind::Initializing {
                registration_id, ..
            } if registration_id == registration => Ok(true),
            StateKind::Registered {
                pid,
                registration_id,
            } if registration_id == registration => {
                // We need to prevent the two scenarios described in the next
                // arm (two rotation owners, and a takeover blind to a dead
                // owner's work) when this returns false and the caller takes
                // ownership away from `registration` (O3).
                //
                // Here P6 cannot do it: it read `registration`'s own
                // REGISTERED word, so it synchronises only with that
                // registration's claim (P2/P5), not with whatever may have
                // ended it since. Liveness is decided from the start time P7
                // reads instead:
                //
                // - `registration`'s own (its claim's T1/T2): the Pid backend
                //   checks that process, and a "dead" answer guarantees that
                //   the process's final writes hb the answer. No problem there.
                // - a later claim's: the slot was claimed again after P6.
                //   With `registration`'s PID, this start time names a
                //   process that never ran, which is_live_since() reports
                //   dead. The later claim is either
                //   - a steal: the reaper's "dead" verdict sb P4 sb T2, and
                //     T2 (release) sw P7 (acquire), so the verdict hb P7,
                //     and so do the dead process's final writes;
                //   - an ordinary claim of the slot after `registration`
                //     released it: O4 sb P3 sw P1 sb T1 sw P7, so O4 hb P7.
                //     In other words, the unlock of the rotation hb P7.
                //
                // P7 sb the caller's takeover (O3), so the dead process's
                // final writes (among them its R5 and R1) and the departed
                // owner's O4 hb O3; the next arm explains why O3 needs
                // each of them.
                // P7: the slot's start time, with acquire.
                Pid::is_live_since(pid, self.thread_disambiguation.load(Acquire))
            }
            // We need to prevent two scenarios when this returns false and
            // the caller takes rotation ownership away from `registration`
            // with O3, the CAS `rotation_owner`: O -> me in
            // acquire_rotation(), where O is the owner value naming
            // `registration` that the caller loaded at O1:
            //
            // 1. Two owners. `registration` left normally: it released
            //    ownership (O4, `rotation_owner` := NONE, a plain store), then
            //    the slot (P3). If O3 preceded O4 in `rotation_owner`'s
            //    modification order, O3 would read O and succeed, and O4 would
            //    then reset `rotation_owner` to NONE while the caller rotates,
            //    so a second rotator could acquire ownership (O2) too:
            //
            //      rotation_owner, in        written by
            //      modification order
            //      O                         registration (its O2)
            //      me                        caller's O3, which read O
            //      NONE                      registration's O4, late
            //      other                     another rotator's O2
            //
            //    After its O3 the caller believes it owns rotation. After
            //    the other rotator's O2, that rotator believes so too, and
            //    both rotate at once.
            // 2. A takeover blind to the dead owner's work. A reaper found
            //    `registration` dead and stole the slot (P4). The new owner
            //    builds on two writes the dead owner may have made under
            //    ownership: R5, its publication of the next epoch, which R0
            //    must see to stop; and R1, its seal of the current arena
            //    with the writers' reservations (B1) that R1 acquired, since
            //    rotate_owned() does not repeat R1 on an arena it finds
            //    sealed.
            //
            // Here P6 read FREE, or another registration's claim:
            // `registration` is over.
            //
            // Both are prevented if what ended `registration` hb O3, and P6
            // provides that edge. `registration` ended with a release CAS of
            // `state`, P3 or P4. Every later write of `state` (P1-P5) is a
            // read-modify-write, so the value P6 reads is in the release
            // sequence of that CAS, which therefore sw P6. P6 sb O3, so:
            //
            // 1. O4 sb P3 sw P6 sb O3, so O4 hb O3. A write that hb a
            //    read-modify-write precedes it in modification order, so O3
            //    reads NONE (or a later owner), not O, and fails.
            // 2. The reaper's "dead" verdict sb P4 sw P6 sb O3, and the dead
            //    process's final writes, R5 and R1 among them, hb the
            //    verdict, so they hb O3.
            _ => Ok(false),
        }
    }
}
