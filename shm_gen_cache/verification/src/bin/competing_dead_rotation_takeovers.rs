//! Two public inserts cannot both take rotation ownership from the same
//! dead participant.
//!
//! # Staged crash state (before any worker exists)
//!
//! One participant (slot 0, PID 1) replaces S -> V0 three times. Each record
//! is 32 bytes, so epoch 2's 96-byte record area becomes full while the
//! table retains one occupied bucket. That participant then acquires
//! rotation ownership of epoch 2 and is declared dead before sealing the
//! arena. A normal insert cannot stop at this point and simulate process
//! death, so the test calls the real `acquire_rotation()` once through
//! `test_access`; it never calls `rotate()` or copies its logic. The dead
//! registration is leaked (a dead process never releases its claim).
//!
//! # Workers
//!
//! Two symmetric workers (PID 2) register and publicly insert A -> V1 and
//! B -> V2:
//!
//! ```text
//!     worker A                         worker B
//!     --------                         --------
//!     reservation finds no space      reservation finds no space
//!     report old owner dead            report old owner dead
//!     CAS owner: old -> A      <|>      CAS owner: old -> B
//! ```
//!
//! At most one takeover CAS can succeed. Its worker rotates to epoch 3 and
//! publishes there. The loser returns `RotationOwnerTimeout` without
//! reserving a record. Both inserts may instead succeed if the second worker
//! reaches the cache after the first has completed rotation.
//!
//! # Properties (after the joins)
//!
//! * every insert succeeds or times out on the rotation owner; at least one
//!   succeeds, at most one times out;
//! * the abandoned owner identity was checked once or twice;
//! * successful keys look up as exactly their values, a timed-out key
//!   misses; one public retry then publishes the missing key and both keys
//!   look up as their values;
//! * epoch 3 finishes with exactly two 32-byte records, epoch 2 stays full
//!   (bump 96, occupancy 1) and sealed, rotation ownership is clear, and
//!   every participant is unpinned.
//!
//! The dead participant's registration state is not asserted: taking over
//! rotation ownership and reaping its participant slot are separate
//! operations.
//!
//! # Witness
//!
//! Public outcomes alone cannot show that both workers examined the
//! abandoned owner: a loser could instead time out after examining the new
//! live owner. The liveness backend (`StagedIdentity`) therefore counts
//! checks of the abandoned PID/start-time pair with a relaxed atomic.
//! Registration has two free slots, and no pin is old enough to block epoch
//! 2 -> 3, so neither operation needs slot reaping or its additional
//! liveness checks. Each initial insert can therefore check that identity at
//! most once: a successful takeover removes the old owner, while a failed
//! takeover immediately returns a timeout. `COMPETING_DEAD_OWNER_CHECKS`
//! selects two such checks and exactly one timeout, proving that both
//! workers competed for the same dead ownership. The counter adds no
//! happens-before edge, and no production hook is used.
//!
//! No `assume` is used.

#![no_std]
#![no_main]

use core::sync::atomic::AtomicU32;
use core::sync::atomic::Ordering::Relaxed;

use genmc_harness::{check, check_ok, padded_words, scope, witness, words_eq};
use shm_gen_cache::test_access::{
    RotationPolicy, acquire_rotation, arena_state, distinct_bucket_hashes, global_epoch,
    participant, pinned_epoch, registration_id, rotation_owner,
};
use shm_gen_cache::{
    Cache, CacheStorage, Error, GetPid, ParticipantLock, StaticParams, output_buffer, static_config,
};

static_config! {
    struct Cfg: StagedIdentity {
        participant_capacity: 3,
        bucket_count: 8,
        max_key_size: 1,
        max_value_size: 1,
        record_area_size: 96,
        max_occupancy: 8,
        reservation_chunk_size: 8, // exact record accounting
    }
}

type Participant = ParticipantLock<'static, Cfg>;
type CacheRef = Cache<'static, Cfg>;

const PARTICIPANTS: u32 = 3;
const BUCKET_COUNT: u32 = 8;
const RECORD_SIZE: u32 = 32;

/// Keys S, A, B and their values V0, V1, V2; index 0 is the setup key.
const KEYS: [&[u8; 1]; 3] = [b"S", b"A", b"B"];
const VALUES: [&[u8; 1]; 3] = [b"0", b"1", b"2"];
const HASHES: [u64; 3] = distinct_bucket_hashes(BUCKET_COUNT);

/// The staged epoch (arena 2) and the one its rotation publishes (arena 0).
const OLD_EPOCH: u64 = 2;
const NEW_EPOCH: u64 = 3;
const OLD_ARENA: u64 = OLD_EPOCH % 3;
const NEW_ARENA: u64 = NEW_EPOCH % 3;

/// The dead setup participant and the live workers.
const DEAD_PID: u32 = 1;
const LIVE_PID: u32 = 2;
const DEAD_START: u64 = 100;
const LIVE_START: u64 = 200;

/// Liveness backend: PID 1 (started at 100) is dead, PID 2 (started at 200)
/// live. The caller is PID 1 during setup only.
struct StagedIdentity;

static CURRENT_PID: AtomicU32 = AtomicU32::new(DEAD_PID);
/// Checks of the abandoned owner's PID/start-time pair.
static DEAD_CHECKS: AtomicU32 = AtomicU32::new(0);

impl GetPid for StagedIdentity {
    fn get_pid() -> u32 {
        CURRENT_PID.load(Relaxed)
    }

    fn get_start_time() -> u64 {
        if Self::get_pid() == DEAD_PID {
            DEAD_START
        } else {
            LIVE_START
        }
    }

    fn is_live(pid: u32) -> Result<bool, Error> {
        check!(pid == DEAD_PID || pid == LIVE_PID);
        Ok(pid == LIVE_PID)
    }

    fn is_live_since(pid: u32, start_time: u64) -> Result<bool, Error> {
        if pid == DEAD_PID {
            check!(start_time == DEAD_START);
            DEAD_CHECKS.fetch_add(1, Relaxed);
            return Ok(false);
        }
        check!(pid == LIVE_PID && start_time == LIVE_START);
        Ok(true)
    }
}

#[derive(Clone, Copy, PartialEq, Eq)]
enum Outcome {
    Unset,
    Success,
    OwnerTimeout,
    OtherError,
}

struct Writer {
    cache: CacheRef,
    key: usize,
    result: Outcome,
}

/// Registers, inserts the writer's key once, and unregisters.
fn insert(writer: &mut Writer) {
    let mut participant = check_ok!(writer.cache.register_participant());
    let k = writer.key;
    writer.result = match participant.insert(HASHES[k], KEYS[k], VALUES[k]) {
        Ok(()) => Outcome::Success,
        Err(Error::RotationOwnerTimeout) => Outcome::OwnerTimeout,
        Err(_) => Outcome::OtherError,
    };
}

#[unsafe(no_mangle)]
extern "C" fn main() -> i32 {
    static STORAGE: <Cfg as StaticParams>::Storage = CacheStorage::new();
    // SAFETY: zeroed static storage, used only through this cache.
    let cache = check_ok!(unsafe { Cache::initialize(STORAGE.as_mut_ptr(), STORAGE.len(), Cfg) });

    // Stage the dead rotation owner (slot 0, PID 1). Its registration is
    // leaked: slot reaping, if any, must come from the production protocol.
    let mut dead = check_ok!(cache.register_participant());
    let dead_registration = registration_id(dead.slot());
    for _ in 0..3 {
        check_ok!(dead.insert(HASHES[0], KEYS[0], VALUES[0]));
    }
    check!(dead.slot_index() == 0);
    core::mem::forget(dead);
    stage_dead_rotation_owner(cache);
    CURRENT_PID.store(LIVE_PID, Relaxed);
    expect_setup(cache, dead_registration);

    let mut writers = [1, 2].map(|key| Writer {
        cache,
        key,
        result: Outcome::Unset,
    });
    scope(|s| {
        let [first_writer, second_writer] = &mut writers;
        let first = s.spawn(insert, first_writer);
        let second = s.spawn_symmetric(insert, second_writer, &first);
        first.join();
        second.join();
    });

    let mut successes = 0;
    let mut timeouts = 0;
    for writer in &writers {
        check!(matches!(
            writer.result,
            Outcome::Success | Outcome::OwnerTimeout
        ));
        successes += (writer.result == Outcome::Success) as u32;
        timeouts += (writer.result == Outcome::OwnerTimeout) as u32;
    }
    check!(successes >= 1 && timeouts <= 1);
    let dead_checks = DEAD_CHECKS.load(Relaxed);
    check!(dead_checks >= 1 && dead_checks <= 2);

    let mut recovery = check_ok!(cache.register_participant());
    for writer in &writers {
        expect_lookup(&mut recovery, writer.key, writer.result == Outcome::Success);
    }
    for writer in &writers {
        if writer.result == Outcome::OwnerTimeout {
            let k = writer.key;
            check_ok!(recovery.insert(HASHES[k], KEYS[k], VALUES[k]));
        }
    }
    expect_lookup(&mut recovery, 1, true);
    expect_lookup(&mut recovery, 2, true);
    expect_final(cache);

    witness!(
        "COMPETING_DEAD_OWNER_CHECKS",
        dead_checks == 2 && timeouts == 1
    );
    0
}

/// Slot 0 takes rotation ownership of the full epoch 2, then (by the
/// caller) dies before sealing it.
fn stage_dead_rotation_owner(cache: CacheRef) {
    check!(global_epoch(cache).load(Relaxed) == OLD_EPOCH);
    check_ok!(acquire_rotation(
        cache,
        0,
        OLD_EPOCH,
        RotationPolicy::WaitForOwner
    ));
}

/// Epoch 2 is full but unsealed, owned by the dead registration; nobody is
/// pinned.
fn expect_setup(cache: CacheRef, dead_registration: u32) {
    check!(global_epoch(cache).load(Relaxed) == OLD_EPOCH);
    let owner = rotation_owner(cache, Relaxed);
    check!(owner.slot() == 0 && owner.registration_id() == dead_registration);
    let current = arena_state(cache, OLD_ARENA, Relaxed);
    check!(current.epoch == OLD_EPOCH as u32 && !current.sealed);
    check!(current.bump == 3 * RECORD_SIZE);
    check!(current.occupancy == Some(1));
    expect_all_unpinned(cache);
}

/// Epoch 3 holds A and B; epoch 2 is sealed and unchanged; ownership is
/// clear; nobody is pinned.
fn expect_final(cache: CacheRef) {
    check!(global_epoch(cache).load(Relaxed) == NEW_EPOCH);
    check!(rotation_owner(cache, Relaxed).registration_id() == 0);
    let old = arena_state(cache, OLD_ARENA, Relaxed);
    check!(old.epoch == OLD_EPOCH as u32 && old.sealed && old.bump == 3 * RECORD_SIZE);
    check!(old.occupancy == Some(1));
    let current = arena_state(cache, NEW_ARENA, Relaxed);
    check!(current.epoch == NEW_EPOCH as u32 && !current.sealed);
    check!(current.bump == 2 * RECORD_SIZE);
    check!(current.occupancy == Some(2));
    expect_all_unpinned(cache);
}

fn expect_all_unpinned(cache: CacheRef) {
    for index in 0..PARTICIPANTS {
        check!(pinned_epoch(participant(cache, index)).load(Relaxed) == 0);
    }
}

/// Key `k` hits with exactly its value, written into the caller's buffer,
/// or (`present == false`) misses.
fn expect_lookup(participant: &mut Participant, k: usize, present: bool) {
    let mut output = output_buffer!(1);
    let output_start = output.bytes().as_ptr();
    let capacity = output.capacity();
    let found = match participant.lookup(HASHES[k], KEYS[k], &mut output) {
        Ok(found) => found.map(|value| (value.as_ptr(), value.len())),
        Err(_) => genmc_harness::fail("lookup failed\0"),
    };
    let Some((value_start, value_len)) = found else {
        check!(!present, "present key hits");
        return;
    };
    check!(present, "absent key misses");
    check!(value_len == capacity);
    check!(value_start == output_start);
    check!(words_eq(output.words(), &padded_words::<1>(VALUES[k])));
}
