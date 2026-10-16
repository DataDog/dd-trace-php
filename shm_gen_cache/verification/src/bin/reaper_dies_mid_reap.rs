//! A rotation blocked by dead pinners whose slot reapers also died mid-reap
//! reaps those slots again and reuses the arena.
//!
//! # Staged crash state (before any worker exists)
//!
//! Slots 0 and 1 hold two registrations of PID 1 (start 101), pinned in
//! epoch 2 and abandoned: a dead participant never unpins or unregisters,
//! so its registrations are leaked. Slot 0's pin carries a stale
//! `ROTATION_WAITING`, as an earlier rotation that announced itself, then
//! gave up, leaves it. A slot reaper then stole each slot and died before
//! clearing PID 1's pin. A thread cannot stop in the middle of a
//! synchronous reap, so `test_access::stage_interrupted_reap()` stages the
//! two crash points that leave the pin set:
//!
//! * slot 0: reaper PID 3 (start 303) died right after stealing the slot,
//!   before publishing its start time: the slot is INITIALIZING(PID 3, id),
//!   still with PID 1's start time and PID 1's pin;
//! * slot 1: reaper PID 4 (start 404) died after registering the slot to
//!   itself, but before clearing PID 1's pin: REGISTERED(PID 4, id').
//!
//! Neither reaper pinned anything: the pin is PID 1's, and only a reap of
//! the dead reaper's claim can clear it.
//!
//! Slot 2 is registered by the live rotator B (PID 2, start 202). The
//! `StagedDeath` backend reports PIDs 1, 3 and 4 dead and PID 2 live,
//! without synchronising anything. B is the only worker, so every start
//! time it asks about is that of the PID it pairs it with, which the backend
//! checks. The cache starts empty at epoch 2, and every one-byte key/value
//! record fills its whole 32-byte arena.
//!
//! # Worker
//!
//! Rotator B inserts K -> VK (epoch 2), F -> VF twice (rotating to epochs 3
//! and 4, which an epoch-2 pin does not block) and G -> VG, whose rotation
//! to epoch 5 recycles arena 2 and so waits for both dead pins. Each wait's
//! budget ends (two spin polls under GenMC, 5 ms natively) and B must reap
//! the slot itself: it finds the dead reaper's claim dead (by PID alone for
//! slot 0's INITIALIZING claim, by PID and start time for slot 1's), steals
//! the slot, which clears the pin, and releases it.
//!
//! # Properties
//!
//! After the join: B's four inserts succeeded; B's own registration is
//! unchanged; slots 0 and 1 are FREE; every pin is clear (no leftover
//! `ROTATION_WAITING`); arenas 0, 1 and 2 hold epochs 3, 4 and 5 with one
//! record each; nobody owns rotation; a public lookup of G returns exactly
//! VG.
//!
//! A slot that a dead reaper left unreapable would instead make every
//! rotation to epoch 5 time out on its dead pin.
//!
//! # Witness
//!
//! None: B is the only worker, so the program has a single execution, which
//! the safety check covers.
//!
//! No `assume` is used.

#![no_std]
#![no_main]

use core::sync::atomic::AtomicU32;
use core::sync::atomic::Ordering::{Relaxed, Release, SeqCst};

use genmc_harness::{Lent, check, check_ok, padded_words, scope};
use shm_gen_cache::test_access::{
    ARENAS, ROTATION_WAITING, ReapCrash, arena_state, global_epoch, is_free, participant,
    pinned_epoch, registration_id, rotation_owner, stage_interrupted_reap,
};
use shm_gen_cache::{
    Cache, CacheStorage, Error, GetPid, ParticipantLock, StaticParams, output_buffer, static_config,
};

static_config! {
    struct Cfg: StagedDeath {
        participant_capacity: 3,
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
const FIRST_KEY: &[u8] = &[1];
const FILLER_KEY: &[u8] = &[2];
const FINAL_KEY: &[u8] = &[3];
const FIRST_VALUE: &[u8] = &[11];
const FILLER_VALUE: &[u8] = &[22];
const FINAL_VALUE: &[u8] = &[33];
const FINAL_WORDS: [u64; 1] = padded_words(FINAL_VALUE);

const PARTICIPANTS: u32 = 3;
/// Every record exactly fills an arena's record area.
const RECORD_BYTES: u32 = 32;
/// The arena holding epoch 2 (the dead pins') and later epoch 5.
const RECYCLED_ARENA: u64 = 2;
/// The dead pinners' slots: slots 0 and 1.
const DEAD_SLOTS: u32 = 2;
/// The epoch the dead participants stay pinned to.
const DEAD_PIN: u64 = 2;

const DEAD_PID: u32 = 1;
const LIVE_PID: u32 = 2;
/// The dead reapers of slots 0 and 1.
const DEAD_CLAIMANT_PID: u32 = 3;
const DEAD_REGISTRANT_PID: u32 = 4;
/// A PID's start time, which tells the incarnations apart.
const fn start_time(pid: u32) -> u64 {
    100 * pid as u64 + pid as u64
}

/// Liveness backend: PIDs 1, 3 and 4 are dead, PID 2 live. Only setup
/// changes the caller's PID; the worker runs as PID 2.
struct StagedDeath;

static CALLER: AtomicU32 = AtomicU32::new(DEAD_PID);

impl GetPid for StagedDeath {
    fn get_pid() -> u32 {
        CALLER.load(Relaxed)
    }

    fn get_start_time() -> u64 {
        start_time(Self::get_pid())
    }

    fn is_live(pid: u32) -> Result<bool, Error> {
        check!((DEAD_PID..=DEAD_REGISTRANT_PID).contains(&pid));
        Ok(pid == LIVE_PID)
    }

    fn is_live_since(pid: u32, start: u64) -> Result<bool, Error> {
        // Only B asks: no concurrent reap can pair a PID with another
        // incarnation's start time.
        check!(start == start_time(pid));
        Self::is_live(pid)
    }
}

struct Rotator<'a> {
    participant: Lent<'a, Participant>,
    /// Successful inserts.
    successes: u32,
}

/// B: fill epoch 2, rotate twice, then reuse the dead pinners' arena.
fn rotate_and_reuse(rotator: &mut Rotator) {
    let p = &mut rotator.participant;
    let records = [
        (FIRST_KEY, FIRST_VALUE),
        (FILLER_KEY, FILLER_VALUE),
        (FILLER_KEY, FILLER_VALUE),
        (FINAL_KEY, FINAL_VALUE),
    ];
    for (key, value) in records {
        if p.insert(HASH, key, value).is_ok() {
            rotator.successes += 1;
        }
    }
}

#[unsafe(no_mangle)]
extern "C" fn main() -> i32 {
    static STORAGE: <Cfg as StaticParams>::Storage = CacheStorage::new();
    // SAFETY: zeroed static storage, used only through this cache.
    let cache = check_ok!(unsafe { Cache::initialize(STORAGE.as_mut_ptr(), STORAGE.len(), Cfg) });

    stage_dead_pinners_and_reapers(cache);
    CALLER.store(LIVE_PID, Relaxed);
    let mut rotator = check_ok!(cache.register_participant());
    let rotator_registration = registration_id(rotator.slot());

    let mut b = Rotator {
        participant: Lent::new(&mut rotator),
        successes: 0,
    };
    scope(|s| s.spawn(rotate_and_reuse, &mut b).join());
    check!(b.successes == 4);
    check!(registration_id(rotator.slot()) == rotator_registration);
    for slot in 0..DEAD_SLOTS {
        check!(is_free(participant(cache, slot)));
    }
    expect_unpinned(cache);

    expect_joined_state(cache);
    expect_final_lookup(&mut rotator);
    expect_unpinned(cache);
    0
}

/// Slots 0 and 1: PID 1's leaked registrations, pinned in epoch 2 (slot 0
/// with a stale `ROTATION_WAITING`), then left mid-reap by the dead reapers
/// PID 3 and PID 4.
fn stage_dead_pinners_and_reapers(cache: CacheRef) {
    let claimed = stage_dead_pinner(cache, 0, DEAD_PIN | ROTATION_WAITING);
    stage_interrupted_reap(
        cache,
        claimed,
        DEAD_CLAIMANT_PID,
        start_time(DEAD_CLAIMANT_PID),
        ReapCrash::Claimed,
    );
    let registered = stage_dead_pinner(cache, 1, DEAD_PIN);
    stage_interrupted_reap(
        cache,
        registered,
        DEAD_REGISTRANT_PID,
        start_time(DEAD_REGISTRANT_PID),
        ReapCrash::Registered,
    );
}

/// Registers PID 1 in slot `slot` and leaks the registration, pinned with
/// `pin`; returns the slot.
fn stage_dead_pinner(cache: CacheRef, slot: u32, pin: u64) -> u32 {
    let abandoned = check_ok!(cache.register_participant());
    let dead = abandoned.slot();
    check!(core::ptr::eq(dead, participant(cache, slot)));
    core::mem::forget(abandoned);
    pinned_epoch(dead).store(pin, Release);
    slot
}

/// The state after the join: arenas 0, 1 and 2 hold epochs 3, 4 (sealed,
/// full) and 5 (open); every arena holds exactly one record and nobody owns
/// rotation.
fn expect_joined_state(cache: CacheRef) {
    check!(global_epoch(cache).load(Relaxed) == 5);
    check!(rotation_owner(cache, Relaxed).registration_id() == 0);
    for index in 0..ARENAS {
        let arena = arena_state(cache, index, Relaxed);
        let recycled = index == RECYCLED_ARENA;
        check!(arena.epoch == if recycled { 5 } else { index as u32 + 3 });
        check!(arena.sealed == !recycled);
        check!(arena.bump == RECORD_BYTES);
        check!(arena.occupancy == Some(1));
    }
}

fn expect_final_lookup(participant: &mut Participant) {
    let mut output = output_buffer!(1);
    let output_start = output.words().as_ptr().cast::<u8>();
    let value = check_ok!(check_ok!(participant.lookup(HASH, FINAL_KEY, &mut output)));
    check!(value.len() == FINAL_VALUE.len());
    check!(value.as_ptr() == output_start);
    // Compare whole words: lookups store whole words, and a byte read of
    // one would be a mixed-size access to GenMC.
    check!(genmc_harness::words_eq(output.words(), &FINAL_WORDS));
}

/// Every pin is clear: no participant is pinned, and no
/// `ROTATION_WAITING` flag survived the reaping.
fn expect_unpinned(cache: CacheRef) {
    for slot in 0..PARTICIPANTS {
        check!(pinned_epoch(participant(cache, slot)).load(SeqCst) == 0);
    }
}
