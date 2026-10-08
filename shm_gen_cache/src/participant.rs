//! Participant slots: registration, release and slot reaping.

use core::sync::atomic::Ordering::{AcqRel, Acquire, Relaxed, Release};
use core::sync::atomic::{AtomicU32, AtomicU64};

use crate::error::Error;
use crate::pid::GetPid;

/// Pin value of a participant that is not inserting.
pub(crate) const UNPINNED: u64 = 0;

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
/// | REAPING      | `1` (a reserved pid-zero encoding, distinct from FREE  |
/// |              | and every valid claim)                                 |
/// | INITIALIZING | bit 0 = 1, bits 1-31 pid, bits 32-63 registration id  |
/// | REGISTERED   | bit 0 = 0, bits 1-31 pid, bits 32-63 registration id  |
///
/// pid and registration id are non-zero in the last two.
#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub(crate) struct State(pub(crate) u64);

/// A decoded [`State`].
#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub(crate) enum StateKind {
    Free,
    Reaping,
    Initializing { pid: u32, registration_id: u32 },
    Registered { pid: u32, registration_id: u32 },
}

impl State {
    pub(crate) const FREE: State = State(0);
    pub(crate) const REAPING: State = State(1);
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
        } else if self.0 == State::REAPING.0 {
            StateKind::Reaping
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
/// arena's unsealed incarnation. It is reset on claim: a replacement must
/// never inherit a departed owner's partially consumed tail. Relaxed
/// atomics match the other shared slot metadata across death and
/// registration handoffs.
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
            .compare_exchange(State::FREE.0, initializing.0, Acquire, Relaxed)
            .is_err()
        {
            return Err(Error::ConcurrentOperation);
        }
        // A concurrent death check may observe a replacement's start time.
        // Publish the FREE/claim handoff through that observation as well.
        self.thread_disambiguation.store(start_time, Release);
        production_assert!(self.pinned_epoch.load(Relaxed) == UNPINNED);
        self.reset_chunk();

        let registered = State::registered(pid, registration_id);
        if self
            .state
            .compare_exchange(initializing.0, registered.0, Release, Relaxed)
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
            .compare_exchange(current.0, State::FREE.0, Release, Relaxed)
            .is_err()
        {
            crate::assert::fatal("participant slot changed state during release");
        }
    }

    /// Slot reaping: if this slot's registration (or claim in progress) is
    /// dead, clears its pin and frees it. Returns whether it was reaped.
    pub(crate) fn release_zombie_claim<Pid: GetPid>(&self) -> Result<bool, Error> {
        let current = State(self.state.load(Acquire));
        let start_time = self.thread_disambiguation.load(Relaxed);
        let is_zombie = match current.kind() {
            StateKind::Registered { pid, .. } => !Pid::is_live_since(pid, start_time)?,
            // The start time is not available yet.
            StateKind::Initializing { pid, .. } => !Pid::is_live(pid)?,
            StateKind::Free => false,
            // Another reaper owns this slot.
            // TODO: identifiable reaping ownership and safe
            // takeover if the reaper dies before clearing the pin or
            // publishing FREE.
            StateKind::Reaping => false,
        };
        if !is_zombie {
            return Ok(false);
        }
        if self
            .state
            .compare_exchange(current.0, State::REAPING.0, Relaxed, Relaxed)
            .is_err()
        {
            return Ok(false);
        }
        self.pinned_epoch.swap(UNPINNED, AcqRel);
        self.state.store(State::FREE.0, Release);
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
    pub(crate) fn registration_is_live<Pid: GetPid>(
        &self,
        registration: u32,
    ) -> Result<bool, Error> {
        match State(self.state.load(Acquire)).kind() {
            StateKind::Free => Ok(false),
            // REAPING does not publish the death check or cleanup. Wait for
            // the reaper's release of FREE before taking over.
            StateKind::Reaping => Ok(true),
            // A matching INITIALIZING state cannot follow a published owner;
            // conservatively wait if the precondition is violated.
            StateKind::Initializing {
                registration_id, ..
            } if registration_id == registration => Ok(true),
            StateKind::Registered {
                pid,
                registration_id,
            } if registration_id == registration => {
                Pid::is_live_since(pid, self.thread_disambiguation.load(Acquire))
            }
            _ => Ok(false),
        }
    }
}
