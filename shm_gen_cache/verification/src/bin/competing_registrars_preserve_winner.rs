//! Two registrars competing for one dead participant slot must grant exactly
//! one usable registration. Losing a slot-reaping or claim race must neither
//! invalidate the winner's registration nor disturb its accepted insertion.
//!
//! # Staged state (before any worker exists)
//!
//! The cache has a single participant slot. Setup registers it through the
//! public API and leaks the (unpinned) registration: a dead incarnation
//! cannot unregister itself, so public registration must reap the slot
//! eventually; no live worker ever uses the abandoned registration.
//!
//! The deterministic liveness backend (`ReusedIdentity`) models a
//! replacement incarnation with the *same* PID but a different start time:
//! the old identity (start time 100) is dead and the new one (200) is live.
//! PID-only checks (made while a slot is initializing) report live. So the
//! registration tags, not the PID, are what distinguish stale slot-reaping
//! snapshots. Only setup changes the start time (before the spawns); both
//! workers run as the same immutable replacement incarnation.
//!
//! # Workers
//!
//! Two symmetric workers each call `register_participant()` once.
//!
//! * The winner immediately records its own registration id (non-zero and
//!   different from the abandoned one) and publicly inserts K -> V, while the
//!   other call may still be finishing a stale slot-reaping attempt. It then
//!   announces completion and waits for both completion notices (an ordinary
//!   acquire wait loop, no timeout and no model-only assumption) before
//!   checking that its identity is unchanged and that K reads back exactly
//!   as V. Its registration stays active on its own thread until those checks
//!   finish.
//! * The loser must get `ParticipantRegistryFull`, and then only announces
//!   completion.
//!
//! # After the joins
//!
//! Exactly one worker succeeded and the other found the registry full, and
//! both announced completion.
//!
//! # Limits
//!
//! The one-byte K/V record fills the sole 32-byte record area in epoch 2.
//! Eight buckets and an occupancy target of eight prevent maintenance
//! rotation, so the insert and readback need no arena reuse or promotion.
//! No witnesses, assumptions, production hooks or extra GenMC variants.

#![no_std]
#![no_main]

use core::sync::atomic::Ordering::{Acquire, Relaxed, Release};
use core::sync::atomic::{AtomicU32, AtomicU64};

use genmc_harness::{check, check_ok, padded_words, scope};
use shm_gen_cache::test_access::{registered_state, state_word};
use shm_gen_cache::{
    Cache, CacheStorage, Error, GetPid, ParticipantLock, StaticParams, output_buffer, static_config,
};

static_config! {
    struct Cfg: ReusedIdentity {
        participant_capacity: 1,
        bucket_count: 8,
        max_key_size: 1,
        max_value_size: 1,
        record_area_size: 32,
        max_occupancy: 8,
        reservation_chunk_size: 8, // exact record accounting
    }
}

type Participant = ParticipantLock<'static, Cfg>;
type CacheRef = Cache<'static, Cfg>;

const HASH: u64 = 0;
const KEY: &[u8] = &[1];
const VALUE: &[u8] = &[42];

/// The PID shared by the dead incarnation and its replacement.
const PID: u32 = 1;
const ABANDONED_START: u64 = 100;
const REPLACEMENT_START: u64 = 200;

/// Liveness backend: one PID, reused. The incarnation that started at
/// `ABANDONED_START` is dead; every other one is live.
struct ReusedIdentity;

/// The caller's start time: the abandoned incarnation's during setup only.
static CURRENT_START: AtomicU64 = AtomicU64::new(ABANDONED_START);

impl GetPid for ReusedIdentity {
    fn get_pid() -> u32 {
        PID
    }

    fn get_start_time() -> u64 {
        CURRENT_START.load(Relaxed)
    }

    fn is_live(pid: u32) -> Result<bool, Error> {
        check!(pid == PID);
        Ok(true)
    }

    fn is_live_since(pid: u32, start_time: u64) -> Result<bool, Error> {
        check!(pid == PID);
        // A stale state may accompany replacement metadata. Only the old
        // pair identifies the dead incarnation; any other snapshot is
        // conservatively live.
        Ok(start_time != ABANDONED_START)
    }
}

#[derive(Clone, Copy, PartialEq, Eq)]
enum Outcome {
    Unset,
    Success,
    Full,
}

struct Registrar<'a> {
    cache: CacheRef,
    abandoned_id: u32,
    /// Completion notices, one per worker.
    completed: &'a AtomicU32,
    result: Outcome,
}

fn register_and_use(registrar: &mut Registrar) {
    let completed = registrar.completed;
    let mut registration = match registrar.cache.register_participant() {
        Ok(registration) => registration,
        Err(error) => {
            check!(error == Error::ParticipantRegistryFull);
            registrar.result = Outcome::Full;
            completed.fetch_add(1, Release);
            return;
        }
    };

    let id = own_registration_id(&registration);
    check!(id != 0 && id != registrar.abandoned_id);
    check!(registration.insert(HASH, KEY, VALUE).is_ok());
    registrar.result = Outcome::Success;
    completed.fetch_add(1, Release);
    wait_for_both_registrations(completed);
    check!(own_registration_id(&registration) == id);
    check_value(&mut registration);
}

#[unsafe(no_mangle)]
extern "C" fn main() -> i32 {
    static STORAGE: <Cfg as StaticParams>::Storage = CacheStorage::new();
    // SAFETY: zeroed static storage, used only through this cache.
    let cache = check_ok!(unsafe { Cache::initialize(STORAGE.as_mut_ptr(), STORAGE.len(), Cfg) });

    // Stage the abandoned registration; a dead owner never releases it.
    let abandoned = check_ok!(cache.register_participant());
    let abandoned_id = own_registration_id(&abandoned);
    core::mem::forget(abandoned);
    // Only the setup identity changes; workers are the replacement.
    CURRENT_START.store(REPLACEMENT_START, Relaxed);

    let completed = AtomicU32::new(0);
    let registrar = || Registrar {
        cache,
        abandoned_id,
        completed: &completed,
        result: Outcome::Unset,
    };
    let (mut first, mut second) = (registrar(), registrar());
    scope(|s| {
        let t1 = s.spawn(register_and_use, &mut first);
        let t2 = s.spawn_symmetric(register_and_use, &mut second, &t1);
        t1.join();
        t2.join();
    });

    check!(
        matches!(
            (first.result, second.result),
            (Outcome::Success, Outcome::Full) | (Outcome::Full, Outcome::Success)
        ),
        "exactly one registrar wins"
    );
    check!(completed.load(Relaxed) == 2);
    0
}

/// Both completion notices follow their registration calls; the live
/// registration stays on its worker while the other registrar finishes.
fn wait_for_both_registrations(completed: &AtomicU32) {
    while completed.load(Acquire) != 2 {}
}

/// The caller's own registration id:
/// one relaxed load of the slot state, which must be REGISTERED to us.
fn own_registration_id(registration: &Participant) -> u32 {
    let state = state_word(registration.slot()).load(Relaxed);
    let id = (state >> 32) as u32;
    check!(
        state == registered_state(PID, id),
        "registered to the caller"
    );
    id
}

/// K looks up as exactly V, written into the caller's buffer.
fn check_value(registration: &mut Participant) {
    let mut output = output_buffer!(1);
    let output_start = output.bytes().as_ptr();
    let capacity = output.capacity();
    let (value_start, value_len) = match registration.lookup(HASH, KEY, &mut output) {
        Ok(Some(value)) => (value.as_ptr(), value.len()),
        _ => (core::ptr::null(), 0),
    };
    check!(!value_start.is_null(), "K hits");
    check!(value_len == capacity);
    check!(value_start == output_start);
    check!(output.words()[0] == padded_words::<1>(VALUE)[0]);
}
