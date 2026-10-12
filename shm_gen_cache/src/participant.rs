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

/// A registration id (non-zero): what tells one claim of a slot from the
/// next.
#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub(crate) struct RegistrationId(pub(crate) u32);

/// What [`ParticipantSlot::steal`] found in the slot.
#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub(crate) enum Steal {
    /// A dead claim, which the caller now holds as a registration of its
    /// own.
    Stolen,
    /// A dead claim, but the state moved on before the steal; the failed
    /// exchange acquired the later state.
    Lost,
    /// A claim that may still run.
    Live,
    /// A claim of another registration than the expected one, left alone.
    Other,
    /// No claim.
    Free,
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
        // Relaxed: the start time carries no handoff. A reaper pairs it with
        // the state it acquired and acts only through an exchange of that
        // state, which fails if this claim came in between (see steal()).
        self.thread_disambiguation.store(start_time, Relaxed); // T1
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
    /// dead, steals the slot for the calling thread as the registration that
    /// `registration_id()` returns, clears the dead pin and wakes a rotation
    /// that may be blocked on it. With `expected`, only a claim of that
    /// registration is examined: any other is [`Steal::Other`], without a
    /// question to the backend. After [`Steal::Stolen`] the slot is
    /// REGISTERED to the caller, as after [`Self::claim`]. The id is drawn
    /// only once the slot is found dead, so that checking a live slot spends
    /// none.
    ///
    /// The state is loaded with acquire, and a failed steal acquires the
    /// state it found instead, so every outcome synchronises with the
    /// release that wrote the state it reports: the claim that started a
    /// live registration, or whatever ended the registrations before it (a
    /// release P3, or a reaper's P4 after its own "dead" verdict).
    ///
    /// The start time carries no ordering. It is loaded relaxed, and a
    /// steal acts on it only through the exchange of the state loaded
    /// before it, which confirms that the start time belonged to that
    /// claim. Not an earlier claim's: that store sb the earlier claim's
    /// registering exchange, which hb the claim the load acquired. Not a
    /// later claim's: that claim's state exchange reads the steal's write
    /// or a later one, so the steal hb the claim's start-time store, and a
    /// load sb the steal cannot read it. A "dead" verdict acted on is
    /// therefore about the claim the steal replaces, whose final writes hb
    /// the verdict (`GetPid` contract).
    ///
    /// The steal is a claim of the dead slot, in the same two steps as
    /// [`Self::claim`]. A reaper that dies midway therefore leaves a claim of
    /// its own, which the next reaper finds dead and steals in turn. It
    /// shares the claim's one gap: while the slot is INITIALIZING, only the
    /// claimant's TID identifies it. If the reaper dies then and a live
    /// thread reuses the TID, the slot cannot be reaped (nor its dead pin
    /// cleared) until that thread exits.
    pub(crate) fn steal<Pid: GetPid>(
        &self,
        expected: Option<RegistrationId>,
        registration_id: impl FnOnce() -> u32,
    ) -> Result<Steal, Error> {
        let current = State(self.state.load(Acquire));
        let (pid, id, registered) = match current.kind() {
            StateKind::Free => return Ok(Steal::Free),
            StateKind::Registered {
                pid,
                registration_id,
            } => (pid, registration_id, true),
            StateKind::Initializing {
                pid,
                registration_id,
            } => (pid, registration_id, false),
        };
        if expected.is_some_and(|expected| expected != RegistrationId(id)) {
            return Ok(Steal::Other);
        }
        let dead_start_time = self.thread_disambiguation.load(Relaxed);
        let live = if registered {
            Pid::is_live_since(pid, dead_start_time)?
        } else {
            // The start time is not available yet.
            Pid::is_live(pid)?
        };
        if live {
            return Ok(Steal::Live);
        }
        let pid = Pid::get_pid();
        let start_time = Pid::get_start_time();
        let registration_id = registration_id();
        let initializing = State::initializing(pid, registration_id);
        // Release publishes the death verdict to every acquire of this state
        // or of a later one. No acquire on success: every claim's state word
        // is unique (a fresh registration id), so a successful exchange reads
        // the very write the load above acquired. A failure acquires the
        // later state (see the outcomes above).
        if self
            .state
            .compare_exchange(current.0, initializing.0, Release, Acquire) // P4
            .is_err()
        {
            return Ok(Steal::Lost);
        }
        // Relaxed, as in claim().
        self.thread_disambiguation.store(start_time, Relaxed); // T2
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
        Ok(Steal::Stolen)
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
}
