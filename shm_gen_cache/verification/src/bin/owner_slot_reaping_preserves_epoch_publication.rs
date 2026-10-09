//! A rotation owner that dies after publishing the new epoch, before
//! releasing ownership, and whose slot a registrar reaps: a contender that
//! learns of the death only from the reaped slot takes ownership over
//! without repeating the completed rotation.
//!
//! `dead_owner_epoch_publication_preserves_reservation` covers the same
//! crash with the backend reporting the death to the contender itself.
//! Here the backend never tells the contender that the owner is dead. Its
//! owner check can then pass only through the slot reaper's claim, so the
//! verdict, and the owner's final writes, must reach it through the slot:
//!
//! * the reaper's claim in the state word: `registration_is_live()` treats
//!   any other registration as proof that the owner has ended, so the
//!   reaper's claiming exchange must release its verdict;
//! * the reaper's start time, which a contender reading the owner's state
//!   may pair with the owner's PID: that incarnation never ran, so the
//!   backend reports it dead without synchronising anything, and the
//!   release of the start time must carry the verdict instead.
//!
//! Without either release, the contender could read the stale epoch 2
//! after its ownership takeover, repeat the 2 -> 3 rotation and reset arena
//! 0 after main published B there.
//!
//! # Setup
//!
//! Main (the coordinator, also writer W) replaces S -> V0 twice. The two
//! 48-byte records fill epoch 2 while retaining one occupied bucket. Main
//! registers in slot 0; the three slots fill up with O's and C's
//! registrations.
//!
//! # Workers
//!
//! * Owner O publicly inserts A -> VA. Its reservation finds no space, so O
//!   owns the epoch-2 rotation. The `after_epoch_publication` hook stops O
//!   right after production publishes epoch 3 and before it clears the
//!   owner word. O performs no shared access after that point and never
//!   releases its participant registration.
//! * Contender C starts once it passively observes that O owns the
//!   rotation, registers and inserts C -> VC, which may need the epoch-2
//!   rotation: it waits for the owner, then checks its liveness. It keeps
//!   its registration.
//! * Registrar R registers once C has. The registry is full, so it reaps
//!   O's slot if it finds O dead (and fails with
//!   `ParticipantRegistryFull` otherwise), keeping the slot.
//! * Main waits until O has stopped, then inserts B -> VB into epoch 3.
//!
//! # Liveness backend
//!
//! `CrashIdentity` maps the four threads to PIDs 1 (O), 2 (C), 3 (main) and
//! 4 (R), with start times 100 times the PID. It reports O's incarnation
//! (PID 1, start 100) dead only to R, and only once O has stopped; that
//! answer acquires O's final writes (through `OWNER_ABANDONED`), as the
//! `GetPid` contract requires. PID 1 paired with another start time is an
//! incarnation that never ran: it is reported dead to anyone, without
//! synchronisation, and C's question about one is recorded. Every other
//! PID is live.
//!
//! # Properties
//!
//! C may only succeed or time out on rotation ownership; R may only reap
//! O's slot or find the registry full. Public lookups require exactly VB,
//! VC exactly when C succeeded, and A to miss (O stopped before its
//! reservation retried). The final metadata: epoch 3; arena 0 holds epoch
//! 3, unsealed, with B and C if C succeeded; the sealed epoch-2 arena keeps
//! its one surviving record; no pins. If R reaped, it holds O's slot under
//! a fresh registration id.
//!
//! # Witnesses
//!
//! C took ownership over (the owner word ends up clear) and:
//! * `TAKEOVER_THROUGH_REAPER_CLAIM`: its owner check read R's claim;
//! * `TAKEOVER_THROUGH_REAPER_START_TIME`: its owner check read O's state
//!   with R's start time.
//!
//! No `assume` is used. The only internal observation guiding the workers
//! is C's relaxed read of the owner word, which starts C while O's rotation
//! may still be before epoch publication.
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
    ThreadId, abandon_thread, check, check_ok, current_thread, padded_words, scope, witness,
    words_eq,
};
use shm_gen_cache::test_access::{
    arena_state, distinct_bucket_hashes, global_epoch, participant, pinned_epoch, registration_id,
    rotation_owner,
};
use shm_gen_cache::{
    Cache, CacheStorage, Error, GetPid, ParticipantLock, ParticipantSlot, StaticParams,
    output_buffer, static_config,
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
const REGISTRAR_PID: u32 = 4;
/// A PID's start time, which tells the incarnations apart.
const fn start_time(pid: u32) -> u64 {
    100 * pid as u64
}

/// Thread identities, by PID; and the scenario's progress flags.
struct CrashIdentity;

static COORDINATOR_THREAD: ThreadSlot = ThreadSlot::new();
static OWNER_THREAD: ThreadSlot = ThreadSlot::new();
static CONTENDER_THREAD: ThreadSlot = ThreadSlot::new();
static REGISTRAR_THREAD: ThreadSlot = ThreadSlot::new();
/// Released once every worker identity is set; each worker acquires it
/// before its first `get_pid`.
static IDENTITIES_READY: AtomicBool = AtomicBool::new(false);
/// O reached its crash point (released before it stops).
static OWNER_ABANDONED: AtomicBool = AtomicBool::new(false);
/// C registered, so the registry is full.
static CONTENDER_REGISTERED: AtomicBool = AtomicBool::new(false);
/// C asked about PID 1 with another incarnation's start time (R's).
static CONTENDER_PAIRED_REAPER_START: AtomicBool = AtomicBool::new(false);

impl GetPid for CrashIdentity {
    fn get_pid() -> u32 {
        let me = current_thread();
        if COORDINATOR_THREAD.is(me) {
            COORDINATOR_PID
        } else if OWNER_THREAD.is(me) {
            OWNER_PID
        } else if CONTENDER_THREAD.is(me) {
            CONTENDER_PID
        } else {
            check!(REGISTRAR_THREAD.is(me));
            REGISTRAR_PID
        }
    }

    fn get_start_time() -> u64 {
        start_time(Self::get_pid())
    }

    fn is_live(pid: u32) -> Result<bool, Error> {
        // Only for a claim in progress, which no thread abandons.
        check!((OWNER_PID..=REGISTRAR_PID).contains(&pid));
        Ok(true)
    }

    fn is_live_since(pid: u32, start: u64) -> Result<bool, Error> {
        check!((OWNER_PID..=REGISTRAR_PID).contains(&pid));
        if pid != OWNER_PID {
            check!(start == start_time(pid));
            return Ok(true);
        }
        if start != start_time(OWNER_PID) {
            // O's state paired with R's start time: no such incarnation.
            check!(start == start_time(REGISTRAR_PID));
            if Self::get_pid() == CONTENDER_PID {
                CONTENDER_PAIRED_REAPER_START.store(true, Relaxed);
            }
            return Ok(false);
        }
        if Self::get_pid() != REGISTRAR_PID {
            return Ok(true);
        }
        Ok(!OWNER_ABANDONED.load(Acquire))
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
    /// before releasing `IDENTITIES_READY`, or before any other thread
    /// exists).
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
    /// O's slot and registration id, set before it stops.
    slot: Option<&'static ParticipantSlot>,
    registration: u32,
}

struct Contender {
    cache: CacheRef,
    result: Outcome,
}

struct Registrar {
    cache: CacheRef,
    /// The slot R reaped, if it did.
    slot: Option<&'static ParticipantSlot>,
    registration: u32,
}

/// O: registers, then inserts A, which rotates and stops at the crash
/// point. Its registration is never released.
fn rotate_and_abandon(owner: &mut Owner) {
    wait_until(&IDENTITIES_READY);
    let mut participant = check_ok!(owner.cache.register_participant());
    owner.slot = Some(participant.slot());
    owner.registration = registration_id(participant.slot());
    let _ = participant.insert(A.hash, A.key, A.value);
    check!(false, "rotation owner returned after crash point");
}

/// C: registers and inserts C, possibly taking over O's stale rotation. The
/// registration is kept (leaked).
fn insert_as_contender(contender: &mut Contender) {
    wait_until(&IDENTITIES_READY);
    while rotation_owner(contender.cache, Relaxed).registration_id() == 0 {}
    let mut participant = check_ok!(contender.cache.register_participant());
    CONTENDER_REGISTERED.store(true, Release);
    contender.result = match participant.insert(C.hash, C.key, C.value) {
        Ok(()) => Outcome::Success,
        Err(Error::RotationOwnerTimeout) => Outcome::OwnerTimeout,
        Err(_) => Outcome::OtherError,
    };
    // Keep the registry full for R.
    core::mem::forget(participant);
}

/// R: registers into the full registry, reaping O's slot if it finds O
/// dead; the registration is kept (leaked) for main to check.
fn register_as_registrar(registrar: &mut Registrar) {
    wait_until(&IDENTITIES_READY);
    wait_until(&CONTENDER_REGISTERED);
    match registrar.cache.register_participant() {
        Ok(lock) => {
            registrar.slot = Some(lock.slot());
            registrar.registration = registration_id(lock.slot());
            core::mem::forget(lock);
        }
        Err(error) => check!(error == Error::ParticipantRegistryFull),
    }
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

    let mut owner = Owner {
        cache,
        slot: None,
        registration: 0,
    };
    let mut contender = Contender {
        cache,
        result: Outcome::Unset,
    };
    let mut registrar = Registrar {
        cache,
        slot: None,
        registration: 0,
    };
    scope(|s| {
        let owner_thread = s.spawn(rotate_and_abandon, &mut owner);
        let contender_thread = s.spawn(insert_as_contender, &mut contender);
        let registrar_thread = s.spawn(register_as_registrar, &mut registrar);
        OWNER_THREAD.set(owner_thread.id());
        CONTENDER_THREAD.set(contender_thread.id());
        REGISTRAR_THREAD.set(registrar_thread.id());
        IDENTITIES_READY.store(true, Release);

        wait_until(&OWNER_ABANDONED);
        check_ok!(writer.insert(B.hash, B.key, B.value));

        contender_thread.join();
        registrar_thread.join();
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
    check_final_state(cache, result == Outcome::Success);

    let reaped = registrar.slot.is_some();
    if let Some(slot) = registrar.slot {
        check!(core::ptr::eq(slot, check_ok!(owner.slot)));
        check!(registrar.registration != 0 && registrar.registration != owner.registration);
        check!(registration_id(slot) == registrar.registration);
    }

    // C released the ownership it took over; O never released its own.
    let took_over = rotation_owner(cache, Relaxed).registration_id() == 0;
    let paired = CONTENDER_PAIRED_REAPER_START.load(Relaxed);
    witness!(
        "TAKEOVER_THROUGH_REAPER_CLAIM",
        took_over && reaped && !paired
    );
    witness!("TAKEOVER_THROUGH_REAPER_START_TIME", took_over && paired);
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

/// Epoch 3 holds B, and C if it succeeded; the sealed epoch-2 arena keeps
/// its one live record; no pins remain (O stopped unpinned).
fn check_final_state(cache: CacheRef, contender_inserted: bool) {
    check!(global_epoch(cache).load(Relaxed) == 3);
    let records = 1 + contender_inserted as u32;
    let current = arena_state(cache, 0, Relaxed);
    check!(current.epoch == 3 && !current.sealed && current.bump == 48 * records);
    check!(current.occupancy == Some(records));
    let old = arena_state(cache, 2, Relaxed);
    check!(old.epoch == 2 && old.sealed && old.bump == 96);
    check!(old.occupancy == Some(1));
    for index in 0..3 {
        check!(pinned_epoch(participant(cache, index)).load(Relaxed) == 0);
    }
}
