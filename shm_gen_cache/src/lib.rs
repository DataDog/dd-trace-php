//! Mutex-free, process-shared, two-generation byte cache.
//!
//! The shared-memory layout is layout version 10. The code is organised
//! around a few compile-time choices:
//!
//! * the configuration is a [`Params`] type: a zero-sized compile-time
//!   configuration ([`static_config!`]) folds to constants, and
//!   [`RuntimeParams`] carries validated values chosen at run time (used by
//!   the C API);
//! * the occupancy accounting is the [`OccupancyMode`] type parameter
//!   ([`Exact`] or `Estimated`), so the arena layout and the occupancy code
//!   paths stay compile-time choices;
//! * shared words holding several fields are `u64` newtypes with explicit
//!   encoders, so the bit positions are fixed by the layout, not by a
//!   compiler's bitfield rules.
//!
//! One mapping holds a header, `participant_capacity` participant slots and
//! three arenas. `global_epoch % 3` selects the current arena; lookups probe
//! it and then the previous generation, promoting hits found there. Inserts
//! pin their participant to the epoch, reserve record bytes (from a private
//! chunk or with one shared `fetch_add`), write the record, publish an index
//! entry and unpin. A rotation seals the current arena, waits for writers
//! pinned to the arena about to be reused (or reaps their dead slots), resets
//! that arena's control word and publishes the next epoch. Arena reuse does
//! not clear the index or the records: stale ones are rejected by their
//! 32-bit epoch stamps.
//!
//! Terminology: "slot reaping" recovers a dead participant's slot; "arena
//! reuse" is rotation reinitialising an old arena.
//!
//! Build variants, see `verification/README.md`:
//! * feature `verify`: verification build: production assertions off, test
//!   hooks and [`test_access`] enabled. Never enable it in production
//!   artifacts;
//! * `--cfg sgc_genmc`: bitcode for GenMC (requires feature `verify`);
//! * `--cfg sgc_genmc_short_waits`: shortens the bounded rotation waits to
//!   two polls, compiling out their clock reads, monitored sleeps, futex
//!   waits and yields, so the model checker explores fewer spin iterations.

#![no_std]
#![deny(unsafe_op_in_unsafe_fn)]

#[cfg(feature = "std")]
extern crate std;

#[cfg(all(sgc_genmc, not(feature = "verify")))]
compile_error!("--cfg sgc_genmc requires feature `verify`");

#[cfg(not(target_endian = "little"))]
compile_error!("shm_gen_cache encodes shared words in little-endian order");

// The layout and the size arithmetic assume 64-bit hosts.
const _: () = assert!(usize::BITS == 64, "shm_gen_cache targets 64-bit hosts only");

#[macro_use]
mod assert;

mod arena;
mod cache;
mod config;
mod error;
mod lock;
mod occupancy;
mod output;
mod participant;
mod pid;
mod util;
mod wait;

#[cfg(all(feature = "ffi", target_os = "linux"))]
mod linux_pid;

#[cfg(feature = "ffi")]
pub mod ffi;

#[cfg(any(feature = "verify", feature = "test-access"))]
pub mod test_access;

/// Layout version of the shared mapping format for this architecture.
///
/// The format is disposable: a mapping written by a different version is
/// discarded and recreated rather than migrated.
pub const LAYOUT_VERSION: u32 = 10;

pub use cache::{Cache, CacheHeader, CacheStorage};
pub use config::{Config, Derived, HotParams, Params, RuntimeParams, StaticParams};
pub use error::Error;
pub use lock::ParticipantLock;
#[cfg(feature = "std")]
pub use occupancy::{Estimated, OccupancyEstimator};
pub use occupancy::{Exact, NoEstimator, OccupancyMode};
pub use output::OutputBuffer;
pub use participant::ParticipantSlot;
pub use pid::{GetPid, NoopGetPid, PlatformGetPid};
pub use util::CACHE_LINE;

#[cfg(all(feature = "ffi", target_os = "linux"))]
pub use linux_pid::LinuxGetPid;

#[doc(hidden)]
pub mod __private {
    //! Items used by the exported macros.
    pub use crate::config::{SelectOccupancy, SelectOccupancyMode};
}
