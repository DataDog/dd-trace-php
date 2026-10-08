//! Participant-slot reuse cannot let a contender take rotation ownership
//! from a live owner that has already completed normally.
//!
//! # Setup
//!
//! Main (PID 4) inserts S -> V0 twice. The two 48-byte records fill epoch
//! 2's 96-byte record area while one bucket stays occupied. Then three
//! workers run:
//!
//! * Owner O (PID 1): registers, waits until C has registered too, inserts
//!   A -> V1, unregisters, then announces that its slot is released.
//! * Contender C (PID 3): registers, waits until O has registered, inserts
//!   B -> V2, announces that its insert returned, then unregisters.
//! * Replacement R (PID 2): waits until O has released its slot, registers
//!   (only O's old slot can be free while C is registered) and keeps that
//!   registration until C's insert has returned.
//!
//! Both inserts run out of record space, so both must request rotation.
//!
//! # The interleaving of interest
//!
//! It crosses two incarnations of O's slot:
//!
//! ```text
//! owner O                 replacement R             contender C
//! -------                 -------------             -----------
//! publish rotation owner
//!                                                   read O as owner
//!                                                   read O's REGISTERED
//! finish rotation
//! clear owner word
//! publish slot FREE
//!                         claim O's slot
//!                         publish R's start time
//!                                                   read R's start time
//!                                                   report O/R as dead
//!                                                   CAS old owner -> C
//! ```
//!
//! O and R are both live, but the liveness backend (`ActorIdentity`)
//! reports the mixed pair (O's PID, R's start time) as dead and counts how
//! often it was asked about it. Reading R's release-published start time
//! must also import the preceding release of O's slot and, before that,
//! O's release of the owner word. So C's takeover CAS cannot still observe
//! O as owner: it fails, which production reports as
//! `RotationOwnerTimeout`. If C instead succeeded after observing the mixed
//! pair, the ordering between participant identity publication and
//! recovery would be broken.
//!
//! # Checked after the joins
//!
//! The observation goes through public insert results, not a recovery
//! hook:
//!
//! * R registered; each insert succeeded or timed out on the rotation
//!   owner, and at least one succeeded;
//! * the mixed pair was checked at most once, and if it was, O succeeded
//!   and C timed out;
//! * every successful key reads back exactly, every timed-out key misses;
//!   public retries by a fresh registration then publish the missing keys,
//!   after which both keys read back;
//! * final metadata: epoch 3, no rotation owner, the old arena (epoch 2)
//!   sealed and full with the seed, the current arena (epoch 3) holding
//!   exactly the two records, and no pinned slot.
//!
//! # Witness
//!
//! `MIXED_IDENTITY_TAKEOVER_REJECTED`: the mixed pair is checked and C's
//! insert then times out.
//!
//! No assumes, production hooks or extra GenMC variants are used.

#![no_std]
#![no_main]

use core::cell::UnsafeCell;
use core::mem::MaybeUninit;
use core::sync::atomic::AtomicBool;
use core::sync::atomic::AtomicU32;
use core::sync::atomic::Ordering::{Acquire, Relaxed, Release};

use genmc_harness::{
    ThreadId, check, check_ok, current_thread, padded_words, scope, witness, words_eq,
};
use shm_gen_cache::test_access::{
    arena_state, distinct_bucket_hashes, global_epoch, participant, pinned_epoch, rotation_owner,
};
use shm_gen_cache::{
    Cache, CacheStorage, Error, GetPid, ParticipantLock, StaticParams, output_buffer, static_config,
};

static_config! {
    struct Cfg: ActorIdentity {
        participant_capacity: 2,
        bucket_count: 8,
        max_key_size: 10,
        max_value_size: 10,
        record_area_size: 96,
        max_occupancy: 8,
        reservation_chunk_size: 8, // exact record accounting
    }
}

type Participant = ParticipantLock<'static, Cfg>;
type CacheRef = Cache<'static, Cfg>;

const BUCKET_COUNT: u32 = 8;
/// One 48-byte record (header + 10-byte key + 10-byte value).
const RECORD_SIZE: u32 = 48;

/// A key, its value and a hash whose home bucket no other entry shares.
struct Entry {
    hash: u64,
    key: &'static [u8; 10],
    value: &'static [u8; 10],
}

const HASHES: [u64; 3] = distinct_bucket_hashes::<3>(BUCKET_COUNT);
const SEED: Entry = Entry {
    hash: HASHES[0],
    key: b"01234567S0",
    value: b"0000000000",
};
const OWNER_ENTRY: Entry = Entry {
    hash: HASHES[1],
    key: b"01234567A1",
    value: b"ABCDEFGHIJ",
};
const CONTENDER_ENTRY: Entry = Entry {
    hash: HASHES[2],
    key: b"01234567B2",
    value: b"abcdefghij",
};

// ---------------------------------------------------------------------------
// Liveness backend
// ---------------------------------------------------------------------------

const OWNER_PID: u32 = 1;
const REPLACEMENT_PID: u32 = 2;
const CONTENDER_PID: u32 = 3;
const SETUP_PID: u32 = 4;
const OWNER_START: u64 = 100;
const REPLACEMENT_START: u64 = 200;
const CONTENDER_START: u64 = 300;
const SETUP_START: u64 = 400;

/// Each actor is identified by its thread. Every actor is live, but the
/// mixed identity (O's PID with R's start time) is reported dead.
struct ActorIdentity;

/// How many times the mixed identity was checked.
static MIXED_CHECKS: AtomicU32 = AtomicU32::new(0);

/// The actors' threads. Main writes them before releasing
/// `STEPS.identities_ready`; workers read them only after acquiring it.
static COORDINATOR_THREAD: ThreadCell = ThreadCell::new();
static OWNER_THREAD: ThreadCell = ThreadCell::new();
static CONTENDER_THREAD: ThreadCell = ThreadCell::new();
static REPLACEMENT_THREAD: ThreadCell = ThreadCell::new();

impl GetPid for ActorIdentity {
    fn get_pid() -> u32 {
        let me = current_thread();
        if me == COORDINATOR_THREAD.get() {
            return SETUP_PID;
        }
        if me == OWNER_THREAD.get() {
            return OWNER_PID;
        }
        if me == CONTENDER_THREAD.get() {
            return CONTENDER_PID;
        }
        check!(me == REPLACEMENT_THREAD.get());
        REPLACEMENT_PID
    }

    fn get_start_time() -> u64 {
        match Self::get_pid() {
            OWNER_PID => OWNER_START,
            REPLACEMENT_PID => REPLACEMENT_START,
            CONTENDER_PID => CONTENDER_START,
            _ => SETUP_START,
        }
    }

    fn is_live(pid: u32) -> Result<bool, Error> {
        check!((OWNER_PID..=SETUP_PID).contains(&pid));
        Ok(true)
    }

    fn is_live_since(pid: u32, start_time: u64) -> Result<bool, Error> {
        if pid == OWNER_PID && start_time == REPLACEMENT_START {
            MIXED_CHECKS.fetch_add(1, Relaxed);
            return Ok(false);
        }
        let genuine = matches!(
            (pid, start_time),
            (OWNER_PID, OWNER_START)
                | (REPLACEMENT_PID, REPLACEMENT_START)
                | (CONTENDER_PID, CONTENDER_START)
                | (SETUP_PID, SETUP_START)
        );
        check!(genuine);
        Ok(true)
    }
}

/// A thread identity published by main through a later release/acquire
/// (a plain, non-atomic variable).
struct ThreadCell(UnsafeCell<MaybeUninit<ThreadId>>);

// SAFETY: written only by main, before the workers may read it (see the
// statics above).
unsafe impl Sync for ThreadCell {}

impl ThreadCell {
    const fn new() -> Self {
        Self(UnsafeCell::new(MaybeUninit::uninit()))
    }

    fn set(&self, thread: ThreadId) {
        // SAFETY: main is the only writer, and no reader runs concurrently.
        unsafe { (*self.0.get()).write(thread) };
    }

    fn get(&self) -> ThreadId {
        // SAFETY: set happens before every read (see the statics above).
        unsafe { (*self.0.get()).assume_init() }
    }
}

// ---------------------------------------------------------------------------
// Workers
// ---------------------------------------------------------------------------

#[derive(Clone, Copy, PartialEq, Eq)]
enum Outcome {
    Unset,
    Success,
    OwnerTimeout,
    OtherError,
}

/// Coordination between main and the three workers. A static rather than
/// a local of main: rustc initialises such a local's flags with one 7-byte
/// `memset`, which GenMC does not promote to the atomic stores the flags'
/// loads would need to read from.
struct Steps {
    identities_ready: AtomicBool,
    registered: AtomicU32,
    owner_released: AtomicBool,
    contender_done: AtomicBool,
}

static STEPS: Steps = Steps {
    identities_ready: AtomicBool::new(false),
    registered: AtomicU32::new(0),
    owner_released: AtomicBool::new(false),
    contender_done: AtomicBool::new(false),
};

struct Inserter {
    cache: CacheRef,
    result: Outcome,
}

struct Replacement {
    cache: CacheRef,
    registered: bool,
}

fn run_owner(owner: &mut Inserter) {
    wait_until(&STEPS.identities_ready);
    {
        let mut participant = check_ok!(owner.cache.register_participant());
        STEPS.registered.fetch_add(1, Release);
        wait_until_both_registered();
        owner.result = insert(&mut participant, &OWNER_ENTRY);
    } // unregisters
    STEPS.owner_released.store(true, Release);
}

fn run_contender(contender: &mut Inserter) {
    wait_until(&STEPS.identities_ready);
    let mut participant = check_ok!(contender.cache.register_participant());
    STEPS.registered.fetch_add(1, Release);
    wait_until_both_registered();
    contender.result = insert(&mut participant, &CONTENDER_ENTRY);
    STEPS.contender_done.store(true, Release);
    drop(participant);
}

fn run_replacement(replacement: &mut Replacement) {
    wait_until(&STEPS.identities_ready);
    wait_until(&STEPS.owner_released);
    let participant = check_ok!(replacement.cache.register_participant());
    replacement.registered = true;
    wait_until(&STEPS.contender_done);
    drop(participant);
}

fn wait_until(condition: &AtomicBool) {
    while !condition.load(Acquire) {}
}

fn wait_until_both_registered() {
    while STEPS.registered.load(Acquire) != 2 {}
}

fn insert(participant: &mut Participant, entry: &Entry) -> Outcome {
    match participant.insert(entry.hash, entry.key, entry.value) {
        Ok(()) => Outcome::Success,
        Err(Error::RotationOwnerTimeout) => Outcome::OwnerTimeout,
        Err(_) => Outcome::OtherError,
    }
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

#[unsafe(no_mangle)]
extern "C" fn main() -> i32 {
    static STORAGE: <Cfg as StaticParams>::Storage = CacheStorage::new();
    COORDINATOR_THREAD.set(current_thread());
    // SAFETY: zeroed static storage, used only through this cache.
    let cache = check_ok!(unsafe { Cache::initialize(STORAGE.as_mut_ptr(), STORAGE.len(), Cfg) });
    {
        let mut setup = check_ok!(cache.register_participant());
        check_ok!(setup.insert(SEED.hash, SEED.key, SEED.value));
        check_ok!(setup.insert(SEED.hash, SEED.key, SEED.value));
    }

    let mut owner = Inserter {
        cache,
        result: Outcome::Unset,
    };
    let mut contender = Inserter {
        cache,
        result: Outcome::Unset,
    };
    let mut replacement = Replacement {
        cache,
        registered: false,
    };
    scope(|s| {
        let owner_thread = s.spawn(run_owner, &mut owner);
        let contender_thread = s.spawn(run_contender, &mut contender);
        let replacement_thread = s.spawn(run_replacement, &mut replacement);
        OWNER_THREAD.set(owner_thread.id());
        CONTENDER_THREAD.set(contender_thread.id());
        REPLACEMENT_THREAD.set(replacement_thread.id());
        STEPS.identities_ready.store(true, Release);

        // Reverse get_pid()'s comparison order, so that every compared
        // (native) thread ID stays valid until its last possible use.
        replacement_thread.join();
        contender_thread.join();
        owner_thread.join();
    });
    let (owner, contender) = (owner.result, contender.result);

    check!(replacement.registered);
    check!(owner == Outcome::Success || owner == Outcome::OwnerTimeout);
    check!(contender == Outcome::Success || contender == Outcome::OwnerTimeout);
    check!((owner == Outcome::Success) as u32 + (contender == Outcome::Success) as u32 >= 1);

    let mixed_checks = MIXED_CHECKS.load(Relaxed);
    check!(mixed_checks <= 1);
    if mixed_checks == 1 {
        check!(owner == Outcome::Success);
        check!(contender == Outcome::OwnerTimeout);
    }

    let mut recovery = check_ok!(cache.register_participant());
    expect_lookup(&mut recovery, &OWNER_ENTRY, owner == Outcome::Success);
    expect_lookup(
        &mut recovery,
        &CONTENDER_ENTRY,
        contender == Outcome::Success,
    );
    if owner == Outcome::OwnerTimeout {
        check_ok!(recovery.insert(OWNER_ENTRY.hash, OWNER_ENTRY.key, OWNER_ENTRY.value));
    }
    if contender == Outcome::OwnerTimeout {
        check_ok!(recovery.insert(
            CONTENDER_ENTRY.hash,
            CONTENDER_ENTRY.key,
            CONTENDER_ENTRY.value
        ));
    }
    expect_lookup(&mut recovery, &OWNER_ENTRY, true);
    expect_lookup(&mut recovery, &CONTENDER_ENTRY, true);
    expect_final_state(cache);

    witness!(
        "MIXED_IDENTITY_TAKEOVER_REJECTED",
        mixed_checks == 1 && contender == Outcome::OwnerTimeout
    );
    0
}

/// `entry` holds exactly its value if `present`, and misses otherwise.
fn expect_lookup(reader: &mut Participant, entry: &Entry, present: bool) {
    let mut output = output_buffer!(10);
    let hit = check_ok!(reader.lookup(entry.hash, entry.key, &mut output))
        .map(|hit| (hit.as_ptr(), hit.len()));
    if !present {
        check!(hit.is_none());
        return;
    }
    let (data, len) = check_ok!(hit);
    check!(len == output.capacity());
    check!(data == output.words().as_ptr().cast());
    check!(words_eq(output.words(), &padded_words::<2>(entry.value)));
}

/// One rotation happened (epoch 2 -> 3, arena 2 -> arena 0): the old arena
/// is full with the seed, the current one holds both new records, and
/// nobody owns rotation or is pinned.
fn expect_final_state(cache: CacheRef) {
    check!(global_epoch(cache).load(Relaxed) == 3);
    check!(rotation_owner(cache, Relaxed).registration_id() == 0);

    let old = arena_state(cache, 2, Relaxed);
    check!(old.epoch == 2 && old.sealed && old.bump == 2 * RECORD_SIZE);
    check!(old.occupancy == Some(1));

    let current = arena_state(cache, 0, Relaxed);
    check!(current.epoch == 3 && !current.sealed && current.bump == 2 * RECORD_SIZE);
    check!(current.occupancy == Some(2));

    for i in 0..2 {
        check!(pinned_epoch(participant(cache, i)).load(Relaxed) == 0);
    }
}
