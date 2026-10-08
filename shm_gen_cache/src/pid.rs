//! Participant identity and liveness.

use crate::error::Error;
use crate::participant::ParticipantSlot;

/// Identity and liveness backend for participants.
///
/// An `is_live` result of `Ok(false)` must identify an incarnation that
/// cannot execute again. It must also provide synchronisation with that
/// incarnation's final shared-memory writes. Timeouts alone do not prove
/// death. [`GetPid::is_live_since`] must distinguish PID/TID reuse via the
/// start time. Synchronisation failures must be returned as errors, not
/// hidden as a live/dead answer.
///
/// The three hook methods are test instrumentation. They are called only in
/// verification builds (feature `verify`), add no synchronisation, and
/// default to no-ops.
pub trait GetPid {
    /// The caller's identity: non-zero and below 2^31.
    fn get_pid() -> u32;
    /// The caller's start time, distinguishing reuse of its identity; zero
    /// means unknown.
    fn get_start_time() -> u64;
    /// Whether `pid` may still run (start time unknown).
    fn is_live(pid: u32) -> Result<bool, Error>;
    /// Whether the incarnation of `pid` that started at `start_time` (zero:
    /// unknown) may still run.
    fn is_live_since(pid: u32, start_time: u64) -> Result<bool, Error>;

    /// Called by a reservation right after it loads the epoch (W1).
    #[inline(always)]
    fn reservation_epoch_hook(_slot: &ParticipantSlot, _epoch: u64) {}
    /// Called before a reservation attempt is abandoned for a rotation.
    #[inline(always)]
    fn reservation_retry_hook(_slot: &ParticipantSlot) {}
    /// Called by a rotation after it publishes the new epoch (R5) and
    /// before it releases ownership.
    #[inline(always)]
    fn after_epoch_publication() {}
}

/// Single-process backend: every participant is pid 1 and always live, so
/// slots are never reaped.
#[derive(Clone, Copy, Debug, Default)]
pub struct NoopGetPid;

impl GetPid for NoopGetPid {
    #[inline(always)]
    fn get_pid() -> u32 {
        1
    }
    #[inline(always)]
    fn get_start_time() -> u64 {
        0
    }
    #[inline(always)]
    fn is_live(_pid: u32) -> Result<bool, Error> {
        Ok(true)
    }
    #[inline(always)]
    fn is_live_since(_pid: u32, _start_time: u64) -> Result<bool, Error> {
        Ok(true)
    }
}

/// The backend used by [`RuntimeParams`](crate::RuntimeParams) by default:
/// [`LinuxGetPid`](crate::LinuxGetPid) on Linux and `WindowsGetPid` on
/// Windows, with feature `ffi`; [`NoopGetPid`] elsewhere (development
/// platforms only: it cannot detect dead participants).
#[cfg(all(feature = "ffi", target_os = "linux"))]
pub type PlatformGetPid = crate::linux_pid::LinuxGetPid;
/// The backend used by [`RuntimeParams`](crate::RuntimeParams) by default:
/// `LinuxGetPid` on Linux and [`WindowsGetPid`](crate::WindowsGetPid) on
/// Windows, with feature `ffi`; [`NoopGetPid`] elsewhere (development
/// platforms only: it cannot detect dead participants).
#[cfg(all(feature = "ffi", windows))]
pub type PlatformGetPid = crate::windows_pid::WindowsGetPid;
/// The backend used by [`RuntimeParams`](crate::RuntimeParams) by default:
/// `LinuxGetPid` on Linux and `WindowsGetPid` on Windows, with feature
/// `ffi`; [`NoopGetPid`] elsewhere (development platforms only: it cannot
/// detect dead participants).
#[cfg(not(all(feature = "ffi", any(target_os = "linux", windows))))]
pub type PlatformGetPid = NoopGetPid;
