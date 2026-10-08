//! Recovery when a rotation owner dies after publishing the new epoch but
//! before releasing rotation ownership.
//!
//! # Setup
//!
//! Main (the coordinator, also writer W) replaces S -> V0 twice. The two
//! 48-byte records fill epoch 2 while retaining one occupied bucket.
//!
//! # Workers
//!
//! * Owner O publicly inserts A -> VA. Its reservation finds no space, so O
//!   owns the epoch-2 rotation. The `after_epoch_publication` hook stops O
//!   right after
//!   production publishes epoch 3 and before it clears the owner word. O
//!   performs no shared access after that point and never releases its
//!   participant registration.
//! * Main starts contender C after passively observing that O owns the
//!   rotation, then acts as writer W once O has stopped. C inserts C -> VC.
//!
//! A selected execution proceeds as follows:
//!
//! ```text
//! owner O                    writer W                 contender C
//! -------                    --------                 -----------
//! claim rotation for e=2                              read e=2 and O
//! seal arena 2
//! initialize arena 0
//! publish global epoch 3
//! stop permanently
//!                            insert B -> VB in e=3
//!                                                     report O dead
//!                                                     CAS O -> C
//!                                                     recheck 2 != 1
//!                                                     release ownership
//!                                                     retry in e=3
//!                                                     insert C -> VC
//! ```
//!
//! # Liveness backend
//!
//! `CrashIdentity` maps the three threads to PIDs 1 (O), 2 (C) and 3 (main)
//! with distinct start times. It reports O dead only after O was abandoned
//! *and* W's public insert has returned, and only to C (the only thread that
//! can ask then). Thus a successful insert by C after that death check
//! proves that C won the stale takeover CAS: a failed CAS would return
//! `RotationOwnerTimeout`, while the post-claim epoch mismatch returns the
//! internal `ConcurrentOperation` that the reservation consumes as a retry.
//! No observation of the recheck itself is needed.
//!
//! # Properties
//!
//! The recheck must prevent C from repeating the already completed 2 -> 3
//! rotation. Repeating it would reset arena 0 after B was published,
//! allowing C to overwrite B's record. Public lookups therefore require
//! exactly VB, and VC exactly when C succeeded; C may only succeed or time
//! out on rotation ownership. A must miss because O stopped before its
//! reservation retried. In the selected executions, C must have succeeded
//! and the final metadata must show both 48-byte records in epoch 3 (arena
//! 0), the sealed epoch-2 arena with its one surviving record, clear
//! rotation ownership and no pins.
//!
//! # Assumption and witness
//!
//! Main assumes C's dead-owner check happened (`selected`); that check is
//! possible only after B was published. Natively `assume` is a no-op and
//! native scheduling need not reach the selection, so main returns early
//! and native success is only a smoke check; GenMC establishes
//! reachability. Witness `DEAD_OWNER_EPOCH_MISMATCH_RETRY` follows every
//! check and selects exactly those executions.
//!
//! The only internal observation used to guide the workers is a relaxed
//! read of the owner word: it starts C while O's rotation may still be
//! before epoch publication. Rotation, insertion, retry, and lookup all use
//! production code and public operations.
//!
//! O is joined only under GenMC, where an abandoned thread has finished;
//! natively it stays parked until the process exits.

#![no_std]
#![no_main]

use core::cell::UnsafeCell;
use core::mem::MaybeUninit;
use core::sync::atomic::AtomicBool;
use core::sync::atomic::Ordering::{Acquire, Relaxed, Release};

use genmc_harness::{
    ThreadId, abandon_thread, assume, check, check_ok, current_thread, padded_words, scope,
    witness, words_eq,
};
use shm_gen_cache::test_access::{
    arena_state, distinct_bucket_hashes, global_epoch, participant, pinned_epoch, rotation_owner,
};
use shm_gen_cache::{
    Cache, CacheStorage, Error, GetPid, ParticipantLock, StaticParams, output_buffer, static_config,
};

const BUCKET_COUNT: u32 = 8;

static_config! {
    struct Cfg: CrashIdentity {
        participant_capacity: 3,
        bucket_count: BUCKET_COUNT,
        max_key_size: 10,
        max_value_size: 10,
        record_area_size: 96,
        max_occupancy: 8,
        reservation_chunk_size: 8, // exact record accounting
    }
}

type Participant = ParticipantLock<'static, Cfg>;
type CacheRef = Cache<'static, Cfg>;

struct Record {
    hash: u64,
    key: &'static [u8; 10],
    value: &'static [u8; 10],
}

const HASHES: [u64; 4] = distinct_bucket_hashes(BUCKET_COUNT);

/// Main's setup record (inserted twice).
const S: Record = Record {
    hash: HASHES[0],
    key: b"01234567S0",
    value: b"0000000000",
};
/// The owner's record: never published.
const A: Record = Record {
    hash: HASHES[1],
    key: b"01234567A1",
    value: b"AAAAAAAAAA",
};
/// The writer's record, published after the epoch-3 publication.
const B: Record = Record {
    hash: HASHES[2],
    key: b"01234567B2",
    value: b"BBBBBBBBBB",
};
/// The contender's record.
const C: Record = Record {
    hash: HASHES[3],
    key: b"01234567C3",
    value: b"CCCCCCCCCC",
};

// ---------------------------------------------------------------------------
// Liveness backend
// ---------------------------------------------------------------------------

const OWNER_PID: u32 = 1;
const CONTENDER_PID: u32 = 2;
const COORDINATOR_PID: u32 = 3;
const OWNER_START: u64 = 100;
const CONTENDER_START: u64 = 200;
const COORDINATOR_START: u64 = 300;

/// Thread identities, by PID; and the scenario's progress flags.
struct CrashIdentity;

static COORDINATOR_THREAD: ThreadSlot = ThreadSlot::new();
static OWNER_THREAD: ThreadSlot = ThreadSlot::new();
static CONTENDER_THREAD: ThreadSlot = ThreadSlot::new();
/// Released once `OWNER_THREAD` (resp. `CONTENDER_THREAD`) is set; the
/// worker acquires it before its first `get_pid`.
static OWNER_IDENTITY_READY: AtomicBool = AtomicBool::new(false);
static CONTENDER_IDENTITY_READY: AtomicBool = AtomicBool::new(false);
/// O reached its crash point (released before it stops).
static OWNER_ABANDONED: AtomicBool = AtomicBool::new(false);
/// W's insert of B has returned.
static RECORD_PUBLISHED: AtomicBool = AtomicBool::new(false);
/// C was told that O is dead: the selected executions.
static CONTENDER_REPORTED_OWNER_DEAD: AtomicBool = AtomicBool::new(false);

impl GetPid for CrashIdentity {
    fn get_pid() -> u32 {
        let me = current_thread();
        if COORDINATOR_THREAD.is(me) {
            COORDINATOR_PID
        } else if OWNER_THREAD.is(me) {
            OWNER_PID
        } else {
            check!(CONTENDER_THREAD.is(me));
            CONTENDER_PID
        }
    }

    fn get_start_time() -> u64 {
        match Self::get_pid() {
            OWNER_PID => OWNER_START,
            CONTENDER_PID => CONTENDER_START,
            _ => COORDINATOR_START,
        }
    }

    fn is_live(pid: u32) -> Result<bool, Error> {
        check!((OWNER_PID..=COORDINATOR_PID).contains(&pid));
        Ok(true)
    }

    fn is_live_since(pid: u32, start_time: u64) -> Result<bool, Error> {
        let genuine = matches!(
            (pid, start_time),
            (OWNER_PID, OWNER_START)
                | (CONTENDER_PID, CONTENDER_START)
                | (COORDINATOR_PID, COORDINATOR_START)
        );
        check!(genuine);
        if pid != OWNER_PID {
            return Ok(true);
        }
        let abandoned = OWNER_ABANDONED.load(Acquire);
        let published = RECORD_PUBLISHED.load(Acquire);
        if !abandoned || !published {
            return Ok(true);
        }
        check!(Self::get_pid() == CONTENDER_PID);
        CONTENDER_REPORTED_OWNER_DEAD.store(true, Relaxed);
        Ok(false)
    }

    /// O's crash point: right after it published epoch 3.
    fn after_epoch_publication() {
        if Self::get_pid() != OWNER_PID {
            return;
        }
        OWNER_ABANDONED.store(true, Release);
        abandon_thread();
    }
}

/// A thread identity in a plain, non-atomic variable, written once (before a
/// release flag that publishes it) and then only read.
struct ThreadSlot(UnsafeCell<MaybeUninit<ThreadId>>);

// SAFETY: the single write happens-before every read (see `set`).
unsafe impl Sync for ThreadSlot {}

impl ThreadSlot {
    const fn new() -> Self {
        ThreadSlot(UnsafeCell::new(MaybeUninit::uninit()))
    }

    /// Sets the identity. Must happen-before every `is` (main writes it
    /// before releasing the thread's ready flag, or before any other
    /// thread exists).
    fn set(&self, id: ThreadId) {
        // SAFETY: no concurrent access, as documented.
        unsafe { (*self.0.get()).write(id) };
    }

    /// Whether this is `id`. The slot must have been `set`.
    fn is(&self, id: ThreadId) -> bool {
        // SAFETY: set and published before, as documented.
        unsafe { (*self.0.get()).assume_init() == id }
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

struct Owner {
    cache: CacheRef,
}

struct Contender {
    cache: CacheRef,
    result: Outcome,
}

/// O: registers, then inserts A, which rotates and stops at the crash
/// point. Its registration is never released.
fn rotate_and_abandon(owner: &mut Owner) {
    wait_until(&OWNER_IDENTITY_READY);
    let mut participant = check_ok!(owner.cache.register_participant());
    let _ = participant.insert(A.hash, A.key, A.value);
    check!(false, "rotation owner returned after crash point");
}

/// C: registers and inserts C, possibly taking over O's stale rotation.
fn insert_as_contender(contender: &mut Contender) {
    wait_until(&CONTENDER_IDENTITY_READY);
    let mut participant = check_ok!(contender.cache.register_participant());
    contender.result = match participant.insert(C.hash, C.key, C.value) {
        Ok(()) => Outcome::Success,
        Err(Error::RotationOwnerTimeout) => Outcome::OwnerTimeout,
        Err(_) => Outcome::OtherError,
    };
}

#[unsafe(no_mangle)]
extern "C" fn main() -> i32 {
    static STORAGE: <Cfg as StaticParams>::Storage = CacheStorage::new();
    COORDINATOR_THREAD.set(current_thread());
    // SAFETY: zeroed static storage, used only through this cache.
    let cache = check_ok!(unsafe { Cache::initialize(STORAGE.as_mut_ptr(), STORAGE.len(), Cfg) });
    let mut writer = check_ok!(cache.register_participant());
    check_ok!(writer.insert(S.hash, S.key, S.value));
    check_ok!(writer.insert(S.hash, S.key, S.value));

    let mut owner = Owner { cache };
    let mut contender = Contender {
        cache,
        result: Outcome::Unset,
    };
    scope(|s| {
        let owner_thread = s.spawn(rotate_and_abandon, &mut owner);
        OWNER_THREAD.set(owner_thread.id());
        OWNER_IDENTITY_READY.store(true, Release);
        while rotation_owner(cache, Relaxed).registration_id() == 0 {}

        let contender_thread = s.spawn(insert_as_contender, &mut contender);
        CONTENDER_THREAD.set(contender_thread.id());
        CONTENDER_IDENTITY_READY.store(true, Release);

        wait_until(&OWNER_ABANDONED);
        check_ok!(writer.insert(B.hash, B.key, B.value));
        RECORD_PUBLISHED.store(true, Release);

        contender_thread.join();
        if cfg!(sgc_genmc) {
            owner_thread.join();
        } else {
            // SAFETY: O is parked forever in abandon_thread, past its last
            // access to `owner`.
            unsafe { owner_thread.leak() };
        }
    });
    let result = contender.result;

    check!(matches!(result, Outcome::Success | Outcome::OwnerTimeout));
    check_lookup(&mut writer, &A, false);
    check_lookup(&mut writer, &B, true);
    check_lookup(&mut writer, &C, result == Outcome::Success);

    let selected = CONTENDER_REPORTED_OWNER_DEAD.load(Relaxed);
    assume(selected);
    if !selected {
        return 0;
    }
    check!(result == Outcome::Success);
    check_selected_state(cache);

    witness!("DEAD_OWNER_EPOCH_MISMATCH_RETRY", selected);
    0
}

fn wait_until(flag: &AtomicBool) {
    while !flag.load(Acquire) {}
}

/// `r` misses, or hits with exactly its value written into the caller's
/// buffer.
fn check_lookup(participant: &mut Participant, r: &Record, present: bool) {
    let mut output = output_buffer!(10);
    let output_start = output.bytes().as_ptr();
    let capacity = output.capacity();
    let (value_start, value_len) = match check_ok!(participant.lookup(r.hash, r.key, &mut output)) {
        Some(value) => (value.as_ptr(), value.len()),
        None => (core::ptr::null(), 0),
    };
    if !present {
        check!(value_start.is_null());
        return;
    }
    check!(!value_start.is_null());
    check!(value_len == capacity);
    check!(value_start == output_start);
    check!(words_eq(output.words(), &padded_words::<2>(r.value)));
}

/// Epoch 3 holds B and C; the sealed epoch-2 arena keeps its one live
/// record; no ownership or pins remain (O stopped unpinned).
fn check_selected_state(cache: CacheRef) {
    check!(global_epoch(cache).load(Relaxed) == 3);
    check!(rotation_owner(cache, Relaxed).registration_id() == 0);
    let old = arena_state(cache, 2, Relaxed);
    check!(old.epoch == 2 && old.sealed && old.bump == 96);
    check!(old.occupancy == Some(1));
    let current = arena_state(cache, 0, Relaxed);
    check!(current.epoch == 3 && !current.sealed && current.bump == 96);
    check!(current.occupancy == Some(2));
    for index in 0..3 {
        check!(pinned_epoch(participant(cache, index)).load(Relaxed) == 0);
    }
}
