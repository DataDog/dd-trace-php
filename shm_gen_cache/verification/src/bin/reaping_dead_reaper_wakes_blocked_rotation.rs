//! Reaping a dead pinner's slot that a dead reaper left mid-reap wakes a
//! rotation blocked on its pin.
//!
//! Built with `sgc_genmc_futex_model` (README.md), like
//! `reaping_wakes_blocked_rotation`, whose setup this extends: a rotation's
//! wait for a pin flags the pin (`ROTATION_WAITING`), then blocks in a model
//! of the futex that has no timeout. In the model the rotation never
//! exhausts its budget to reap the slot itself, so only a registrar's reap
//! can end the wait. GenMC runs with `-check-liveness`: an execution in
//! which the rotation blocks and nothing wakes it is a violation, where
//! natively it would only delay the rotation until its budget ends.
//!
//! # Staged crash state (before any worker exists)
//!
//! Slot 0 is registered by PID 1 (start 101), pinned in epoch 2 and
//! abandoned (its registration is leaked). A slot reaper, PID 3 (start
//! 303), then stole slot 0 and died right after its first compare-exchange,
//! before publishing its start time or clearing PID 1's pin. A thread
//! cannot stop in the middle of a synchronous reap, so
//! `test_access::stage_interrupted_reap()` stages that state:
//! INITIALIZING(PID 3, id), with a fresh registration id, PID 1's start
//! time and PID 1's pin.
//! (`reaping_dead_registered_reaper_wakes_blocked_rotation` stages a reaper
//! that died with the slot registered to itself, and
//! `reaping_dead_reaper_redelivers_lost_wake` one that died after clearing
//! the pin.)
//!
//! The dead pin still protects arena 2, and only a reap of the dead
//! reaper's claim can clear it. Slot 1 is registered by the live rotator B
//! (PID 2, start 202). The `StagedDeath` backend reports PIDs 1 and 3 dead
//! and PID 2 live, without synchronising anything. The cache starts empty
//! at epoch 2, and every one-byte key/value record fills its whole 32-byte
//! arena.
//!
//! # Workers
//!
//! * Rotator B inserts K -> VK (epoch 2), F -> VF twice (rotating to epochs
//!   3 and 4, which an epoch-2 pin does not block) and G -> VG, whose
//!   rotation to epoch 5 recycles arena 2 and so waits for the dead pin.
//! * Registrar R (PID 2) registers: the registry is full, so it must steal
//!   slot 0 from the dead reaper (found dead by PID alone, as the slot
//!   is INITIALIZING), which clears the dead pin and wakes B, and keep it.
//!
//! # Properties
//!
//! After the joins: R registered in slot 0 with a fresh registration id,
//! B's four inserts succeeded, both pins are clear (no leftover
//! `ROTATION_WAITING`), arenas 0, 1 and 2 hold epochs 3, 4 and 5 with one
//! record each, and a public lookup of G returns exactly VG.
//!
//! A slot that a dead reaper left unreapable would instead leave R with a
//! full registry and B blocked on the dead pin.
//!
//! # Witnesses
//!
//! After every check, from the model's count of woken waits:
//! * `BLOCKED_THEN_WOKEN`: B blocked on the dead pin and R's reap woke it;
//! * `REAPED_BEFORE_BLOCKING`: R reaped before B blocked (B's R3 loop saw
//!   the cleared pin, or its announcement's compare-exchange failed, or the
//!   futex word no longer held the pin). The model has no spin polls.
//!
//! Natively, with the real waits and their 5 ms budget, B may also exhaust
//! its budget and reap slot 0 itself; R then registers in the freed slot
//! without reaping. The checks hold either way.
//!
//! No `assume` is used.

#![no_std]
#![no_main]

use core::sync::atomic::AtomicU32;
use core::sync::atomic::Ordering::{Relaxed, Release, SeqCst};

use genmc_harness::{Lent, check, check_ok, padded_words, scope, witness};
use shm_gen_cache::test_access::{
    ARENAS, ReapCrash, arena_state, global_epoch, participant, pinned_epoch, registration_id,
    rotation_owner, stage_interrupted_reap,
};
use shm_gen_cache::{
    Cache, CacheStorage, Error, GetPid, ParticipantLock, ParticipantSlot, StaticParams,
    output_buffer, static_config,
};

static_config! {
    struct Cfg: StagedDeath {
        participant_capacity: 2,
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

/// Every record exactly fills an arena's record area.
const RECORD_BYTES: u32 = 32;
/// The arena holding epoch 2 (the dead pin's) and later epoch 5.
const RECYCLED_ARENA: u64 = 2;
/// The dead pinner's slot.
const DEAD_SLOT: u32 = 0;
/// The epoch the dead participant stays pinned to.
const DEAD_PIN: u64 = 2;

const DEAD_PID: u32 = 1;
const LIVE_PID: u32 = 2;
const DEAD_REAPER_PID: u32 = 3;
/// A PID's start time, which tells the incarnations apart.
const fn start_time(pid: u32) -> u64 {
    100 * pid as u64 + pid as u64
}

/// Liveness backend: PIDs 1 and 3 are dead, PID 2 live. Only setup changes
/// the caller's PID; every thread of the race runs as PID 2.
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
        check!(pid == DEAD_PID || pid == LIVE_PID || pid == DEAD_REAPER_PID);
        Ok(pid == LIVE_PID)
    }

    fn is_live_since(pid: u32, _start_time: u64) -> Result<bool, Error> {
        // A reap can pair a PID with another incarnation's start time.
        // No PID's liveness changes here, so ignore the time.
        Self::is_live(pid)
    }
}

struct Rotator<'a> {
    participant: Lent<'a, Participant>,
    /// Successful inserts. A count rather than per-insert results: copying
    /// an array of one-byte results whole would be a mixed-size access to
    /// GenMC.
    successes: u32,
}

struct Registrar {
    cache: CacheRef,
    /// The slot R registered in, if it registered.
    slot: Option<&'static ParticipantSlot>,
    registration: u32,
}

/// B: fill epoch 2, rotate twice, then reuse the dead pinner's arena.
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

/// R: register, reaping the dead slot; the registration is kept (leaked)
/// for main to check.
fn register(registrar: &mut Registrar) {
    if let Ok(lock) = registrar.cache.register_participant() {
        let slot = lock.slot();
        registrar.registration = registration_id(slot);
        registrar.slot = Some(slot);
        core::mem::forget(lock);
    }
}

#[unsafe(no_mangle)]
extern "C" fn main() -> i32 {
    static STORAGE: <Cfg as StaticParams>::Storage = CacheStorage::new();
    // SAFETY: zeroed static storage, used only through this cache.
    let cache = check_ok!(unsafe { Cache::initialize(STORAGE.as_mut_ptr(), STORAGE.len(), Cfg) });

    let dead_registration = stage_dead_pinner_and_reaper(cache);
    let dead = participant(cache, DEAD_SLOT);
    CALLER.store(LIVE_PID, Relaxed);

    let mut rotator = check_ok!(cache.register_participant());
    let mut b = Rotator {
        participant: Lent::new(&mut rotator),
        successes: 0,
    };
    let mut r = Registrar {
        cache,
        slot: None,
        registration: 0,
    };
    scope(|s| {
        let tb = s.spawn(rotate_and_reuse, &mut b);
        let tr = s.spawn(register, &mut r);
        tb.join();
        tr.join();
    });
    check!(b.successes == 4);
    // R replaced the dead reaper's claim in slot 0.
    let replacement = check_ok!(r.slot);
    check!(core::ptr::eq(replacement, dead));
    check!(r.registration != 0 && r.registration != dead_registration);
    check!(registration_id(replacement) == r.registration);
    expect_unpinned(replacement, rotator.slot());

    expect_joined_state(cache);
    expect_final_lookup(&mut rotator);
    expect_unpinned(replacement, rotator.slot());

    let woken_waits = woken_waits();
    witness!("BLOCKED_THEN_WOKEN", woken_waits == 1);
    witness!("REAPED_BEFORE_BLOCKING", woken_waits == 0);
    0
}

/// Slot 0: PID 1's leaked registration, pinned in epoch 2, then left
/// mid-reap by the dead reaper PID 3. Returns PID 1's registration id.
fn stage_dead_pinner_and_reaper(cache: CacheRef) -> u32 {
    // The dead participant never runs its lock's drop: production reaping
    // owns the cleanup, so the registration is leaked.
    let abandoned = check_ok!(cache.register_participant());
    let dead = abandoned.slot();
    check!(core::ptr::eq(dead, participant(cache, DEAD_SLOT)));
    core::mem::forget(abandoned);
    let dead_registration = registration_id(dead);
    pinned_epoch(dead).store(DEAD_PIN, Release);
    stage_interrupted_reap(
        cache,
        DEAD_SLOT,
        DEAD_REAPER_PID,
        start_time(DEAD_REAPER_PID),
        ReapCrash::Claimed,
    );
    dead_registration
}

/// The model's count of woken waits; zero natively, where there is no
/// model.
fn woken_waits() -> u32 {
    #[cfg(sgc_genmc)]
    let waits = shm_gen_cache::test_access::futex_model_woken_waits();
    #[cfg(not(sgc_genmc))]
    let waits = 0;
    waits
}

/// The state after the joins: arenas 0, 1 and 2 hold epochs 3, 4 (sealed,
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

/// Both pins are clear: no participant is pinned, and no
/// `ROTATION_WAITING` flag survived the reaping.
fn expect_unpinned(replacement: &ParticipantSlot, rotator: &ParticipantSlot) {
    check!(pinned_epoch(replacement).load(SeqCst) == 0);
    check!(pinned_epoch(rotator).load(SeqCst) == 0);
}
