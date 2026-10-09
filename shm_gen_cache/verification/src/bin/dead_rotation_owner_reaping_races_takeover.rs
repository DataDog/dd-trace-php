//! Reaping and re-registering a dead rotation owner's participant slot must
//! not prevent another participant from taking over that stale ownership.
//!
//! # Staged crash state (before any worker exists)
//!
//! The crash state is "the owner CAS succeeded, the arena is not sealed
//! yet": the dead participant won rotation ownership and died inside
//! `rotate()`. A thread cannot stop in the middle of a synchronous
//! `rotate()`, and a normally finishing thread would also unregister and
//! free its slot, so the state is staged: setup calls the real
//! `acquire_rotation()` once as slot 0, through `test_access`. The test
//! never calls `rotate()`, copies its logic or adds a production hook.
//!
//! Identities (liveness backend `ReusedIdentity`): every participant has
//! PID 1. Slot 0 is registered with start time 100 (the old incarnation);
//! the writer (slot 1) and, later, the replacement have start time 200.
//! PID-only liveness checks always answer "live" (they cannot tell the
//! incarnations apart); start-time checks report start 100 dead once the
//! old incarnation has been declared dead.
//!
//! 1. Slot 0 (start 100) registers; its registration is leaked, since a
//!    dead rotation owner never unregisters (slot reaping owns its cleanup;
//!    no worker uses it).
//! 2. The writer (start 200) registers and publicly inserts K0 and K1.
//! 3. Slot 0 acquires epoch-2 rotation ownership; then the old incarnation
//!    is declared dead.
//! 4. The writer publicly inserts K2. That reaches the occupancy target of
//!    three, and the occupancy-triggered maintenance skips the busy owner,
//!    so the insert succeeds without rotating.
//! 5. Setup check: epoch 2, owner = (slot 0, old registration), the epoch-2
//!    arena unsealed with bump 96 and occupancy 3, both slots unpinned. The
//!    dead owner is unpinned, as the rotation protocol requires; its slot
//!    reaping is independent of arena reuse protection.
//!
//! # Workers
//!
//! * Registrar R registers publicly. All slots are taken, so this takes the
//!   dead slot 0 over and keeps it: R must land in slot 0 with a fresh,
//!   nonzero registration id, unpinned. R then signals
//!   `registration_complete`, waits for `recovery_complete`, re-checks that
//!   its identity is unchanged and unpinned, signals `replacement_verified`
//!   and exits (unregistering).
//! * Writer W publicly inserts K3, which needs a rotation before it can
//!   reserve space, racing R's registration for the stale ownership. W
//!   either takes over and succeeds immediately, or conservatively times
//!   out (`RotationOwnerTimeout`) during the slot handoff: its owner check
//!   can read slot 0 still REGISTERED to the old incarnation, then the
//!   start time that R stored after taking the slot over, a pair the
//!   backend reports live. It then waits for `registration_complete`; after
//!   a timeout it retries the insert once, which must succeed. Finally it
//!   checks its own identity, that K3 looks up as exactly V3, that it is
//!   unpinned and the final state, then signals `recovery_complete`.
//!
//! The waits come after the racing calls, so they do not order R's
//! registration against W's initial insert; they only keep the replacement
//! registered through W's retry and final checks. R always completes its
//! takeover of slot 0; `rotation_owner_reaper_dies_mid_reap` covers a reaper
//! that dies midway.
//!
//! # Properties
//!
//! After the joins: all three signals were raised; the initial insert
//! succeeded or timed out on the owner, and a retry happened (and
//! succeeded) exactly after a timeout; the writer's identity is unchanged
//! and it is unpinned. Final state: epoch 3, no rotation owner, the
//! epoch-2 arena sealed with bump 96 and occupancy 3, the epoch-3 arena
//! unsealed holding K3 alone (bump 32, occupancy 1), both slots unpinned.
//!
//! # Witnesses
//!
//! * `DEAD_OWNER_TAKEOVER`: the initial insert takes over and succeeds.
//! * `OWNER_HANDOFF_TIMEOUT`: the initial insert times out (on the mixed
//!   observation above, the only one that reports the old owner live) and
//!   the retry succeeds.
//!
//! No assumptions, production hooks or extra GenMC variants are used.

#![no_std]
#![no_main]

use core::sync::atomic::Ordering::{Acquire, Relaxed, Release};
use core::sync::atomic::{AtomicBool, AtomicU64};

use genmc_harness::{Lent, check, check_ok, padded_words, scope, witness};
use shm_gen_cache::test_access::{
    RotationPolicy, acquire_rotation, arena_state, distinct_bucket_hashes, global_epoch,
    participant, pinned_epoch, registration_id, rotation_owner,
};
use shm_gen_cache::{
    Cache, CacheStorage, Error, GetPid, ParticipantLock, StaticParams, output_buffer, static_config,
};

static_config! {
    struct Cfg: ReusedIdentity {
        participant_capacity: 2,
        bucket_count: 8,
        max_key_size: 1,
        max_value_size: 1,
        record_area_size: 128,
        max_occupancy: 3,
        reservation_chunk_size: 8, // exact record accounting
    }
}

type Participant = ParticipantLock<'static, Cfg>;
type CacheRef = Cache<'static, Cfg>;

const BUCKET_COUNT: u32 = 8;
const KEYS: [[u8; 1]; 4] = [[0], [1], [2], [3]];
const VALUES: [[u8; 1]; 4] = [[10], [11], [12], [13]];
const HASHES: [u64; 4] = distinct_bucket_hashes(BUCKET_COUNT);

/// The rotated epochs and their arenas (`epoch % 3`).
const OLD_EPOCH: u64 = 2;
const NEW_EPOCH: u64 = 3;
const OLD_ARENA: u64 = 2;
const NEW_ARENA: u64 = 0;

/// Liveness backend: one PID, two incarnations told apart by start time.
struct ReusedIdentity;

const PID: u32 = 1;
const OLD_START: u64 = 100;
const NEW_START: u64 = 200;
/// The caller's start time; switched to the new incarnation during setup.
static CURRENT_START: AtomicU64 = AtomicU64::new(OLD_START);
static OLD_IS_DEAD: AtomicBool = AtomicBool::new(false);

impl GetPid for ReusedIdentity {
    fn get_pid() -> u32 {
        PID
    }

    fn get_start_time() -> u64 {
        CURRENT_START.load(Relaxed)
    }

    fn is_live(pid: u32) -> Result<bool, Error> {
        check!(pid == PID);
        // PID-only checks cannot distinguish the old and current incarnations.
        Ok(true)
    }

    fn is_live_since(pid: u32, start_time: u64) -> Result<bool, Error> {
        check!(pid == PID);
        Ok(!OLD_IS_DEAD.load(Relaxed) || start_time != OLD_START)
    }
}

#[derive(Clone, Copy, PartialEq, Eq)]
enum InsertOutcome {
    Unset,
    Success,
    OwnerTimeout,
}

/// The handshake between the two workers (all release/acquire).
struct Signals {
    registration_complete: AtomicBool,
    recovery_complete: AtomicBool,
    replacement_verified: AtomicBool,
}

struct Registrar<'a> {
    cache: CacheRef,
    signals: &'a Signals,
    old_registration: u32,
}

struct Writer<'a> {
    participant: Lent<'a, Participant>,
    signals: &'a Signals,
    registration: u32,
    initial_insert: InsertOutcome,
    retry_succeeded: bool,
}

/// R: registers through the public API (reaping and reusing slot 0), then
/// holds that registration until W has recovered.
fn register_replacement(registrar: &mut Registrar) {
    let cache = registrar.cache;
    let replacement = check_ok!(cache.register_participant());
    let slot = replacement.slot();
    check!(
        core::ptr::eq(slot, participant(cache, 0)),
        "slot 0 is reused"
    );
    let registration = registration_id(slot);
    check!(registration != 0 && registration != registrar.old_registration);
    check!(pinned_epoch(slot).load(Relaxed) == 0);

    registrar.signals.registration_complete.store(true, Release);
    wait_until(&registrar.signals.recovery_complete);
    check!(registration_id(slot) == registration);
    check!(pinned_epoch(slot).load(Relaxed) == 0);
    registrar.signals.replacement_verified.store(true, Release);
}

/// W: inserts K3, which must rotate past the stale owner; after a handoff
/// timeout, retries once after R's registration has completed.
fn insert_and_recover(writer: &mut Writer) {
    let p = &mut writer.participant;
    let inserted = p.insert(HASHES[3], &KEYS[3], &VALUES[3]);
    writer.initial_insert = match inserted {
        Ok(()) => InsertOutcome::Success,
        Err(error) => {
            check!(error == Error::RotationOwnerTimeout);
            InsertOutcome::OwnerTimeout
        }
    };

    // This wait follows the initial insert, so it cannot constrain that
    // race. It keeps the replacement registered through the retry and the
    // final checks.
    wait_until(&writer.signals.registration_complete);
    if inserted.is_err() {
        writer.retry_succeeded = p.insert(HASHES[3], &KEYS[3], &VALUES[3]).is_ok();
        check!(writer.retry_succeeded);
    }

    check!(registration_id(p.slot()) == writer.registration);
    check_k3_value(p);
    check!(pinned_epoch(p.slot()).load(Relaxed) == 0);
    check_final_state(p.cache());
    writer.signals.recovery_complete.store(true, Release);
}

#[unsafe(no_mangle)]
extern "C" fn main() -> i32 {
    static STORAGE: <Cfg as StaticParams>::Storage = CacheStorage::new();
    // SAFETY: zeroed static storage, used only through this cache.
    let cache = check_ok!(unsafe { Cache::initialize(STORAGE.as_mut_ptr(), STORAGE.len(), Cfg) });

    // Slot 0, old incarnation. A dead rotation owner cannot unregister, so
    // the registration is leaked; no worker uses it.
    let dead = check_ok!(cache.register_participant());
    let old_registration = registration_id(dead.slot());
    core::mem::forget(dead);

    CURRENT_START.store(NEW_START, Relaxed);
    let mut writer_lock = check_ok!(cache.register_participant());
    let writer_slot = writer_lock.slot();
    let writer_registration = registration_id(writer_slot);
    check_ok!(writer_lock.insert(HASHES[0], &KEYS[0], &VALUES[0]));
    check_ok!(writer_lock.insert(HASHES[1], &KEYS[1], &VALUES[1]));

    stage_slot_zero_as_rotation_owner(cache);
    OLD_IS_DEAD.store(true, Relaxed);
    check_ok!(writer_lock.insert(HASHES[2], &KEYS[2], &VALUES[2]));
    check_setup_state(cache, old_registration);

    let signals = Signals {
        registration_complete: AtomicBool::new(false),
        recovery_complete: AtomicBool::new(false),
        replacement_verified: AtomicBool::new(false),
    };
    let mut registrar = Registrar {
        cache,
        signals: &signals,
        old_registration,
    };
    let mut writer = Writer {
        participant: Lent::new(&mut writer_lock),
        signals: &signals,
        registration: writer_registration,
        initial_insert: InsertOutcome::Unset,
        retry_succeeded: false,
    };
    scope(|s| {
        let r = s.spawn(register_replacement, &mut registrar);
        let w = s.spawn(insert_and_recover, &mut writer);
        w.join();
        r.join();
    });
    let (initial_insert, retry_succeeded) = (writer.initial_insert, writer.retry_succeeded);

    check!(signals.registration_complete.load(Relaxed));
    check!(signals.recovery_complete.load(Relaxed));
    check!(signals.replacement_verified.load(Relaxed));
    check!(matches!(
        initial_insert,
        InsertOutcome::Success | InsertOutcome::OwnerTimeout
    ));
    check!(retry_succeeded == (initial_insert == InsertOutcome::OwnerTimeout));
    check!(registration_id(writer_slot) == writer_registration);
    check!(pinned_epoch(writer_slot).load(Relaxed) == 0);
    check_final_state(cache);

    witness!(
        "DEAD_OWNER_TAKEOVER",
        initial_insert == InsertOutcome::Success
    );
    witness!(
        "OWNER_HANDOFF_TIMEOUT",
        initial_insert == InsertOutcome::OwnerTimeout && retry_succeeded
    );
    0
}

fn wait_until(condition: &AtomicBool) {
    while !condition.load(Acquire) {}
}

/// The real `acquire_rotation()` of epoch 2, as slot 0 (with the
/// `WaitForOwner` policy): the post-owner-CAS, pre-seal crash state.
fn stage_slot_zero_as_rotation_owner(cache: CacheRef) {
    let epoch = global_epoch(cache).load(Relaxed);
    check!(epoch == OLD_EPOCH);
    check_ok!(acquire_rotation(
        cache,
        0,
        epoch,
        RotationPolicy::WaitForOwner
    ));
}

/// K3 looks up as exactly V3, written into the caller's buffer.
fn check_k3_value(participant: &mut Participant) {
    let mut output = output_buffer!(1);
    let output_start = output.bytes().as_ptr();
    let capacity = output.capacity();
    let (value_start, value_len) = match participant.lookup(HASHES[3], &KEYS[3], &mut output) {
        Ok(Some(value)) => (value.as_ptr(), value.len()),
        _ => (core::ptr::null(), 0),
    };
    check!(!value_start.is_null(), "K3 hits");
    check!(value_len == capacity);
    check!(value_start == output_start);
    check!(output.words()[0] == padded_words::<1>(&VALUES[3])[0]);
}

/// The state just before the workers start.
fn check_setup_state(cache: CacheRef, old_registration: u32) {
    check!(global_epoch(cache).load(Relaxed) == OLD_EPOCH);
    let owner = rotation_owner(cache, Relaxed);
    check!(owner.slot() == 0 && owner.registration_id() == old_registration);
    let current = arena_state(cache, OLD_ARENA, Relaxed);
    check!(current.epoch == OLD_EPOCH as u32 && !current.sealed && current.bump == 96);
    check!(current.occupancy == Some(3));
    check_both_slots_unpinned(cache);
}

/// The state once W has recovered: rotated to epoch 3, K3 alone in it.
fn check_final_state(cache: CacheRef) {
    check!(global_epoch(cache).load(Relaxed) == NEW_EPOCH);
    check!(rotation_owner(cache, Relaxed).registration_id() == 0);
    let old = arena_state(cache, OLD_ARENA, Relaxed);
    check!(old.epoch == OLD_EPOCH as u32 && old.sealed && old.bump == 96);
    check!(old.occupancy == Some(3));
    let current = arena_state(cache, NEW_ARENA, Relaxed);
    check!(current.epoch == NEW_EPOCH as u32 && !current.sealed && current.bump == 32);
    check!(current.occupancy == Some(1));
    check_both_slots_unpinned(cache);
}

fn check_both_slots_unpinned(cache: CacheRef) {
    check!(pinned_epoch(participant(cache, 0)).load(Relaxed) == 0);
    check!(pinned_epoch(participant(cache, 1)).load(Relaxed) == 0);
}
