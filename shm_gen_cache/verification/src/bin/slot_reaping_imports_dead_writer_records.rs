//! A rotation that reuses an arena after a registrar cleared a dead
//! writer's pin there must order the dead writer's record writes before
//! the reuse.
//!
//! A writer that dies pinned leaves its pin to slot reaping, and its record
//! in the arena that pin protects. A rotation's pin scan that reads the
//! reaper's clear must import the dead writer's final writes, as it would
//! through the writer's own unpin: the reaper found the writer dead (which
//! the backend answer synchronises with its final writes), and clears the
//! pin with a release. The release sequence of the writer's pin store does
//! not suffice: it carries only the writes before the pin, not the record
//! written under it. Without the reaper's release, the rotation could reuse
//! the arena with the dead writer's record words not yet ordered before its
//! own, and a record written into the reused arena could read back as the
//! dead writer's.
//!
//! The writer dies while the workers run, not in a staged state: a death
//! staged before the spawns would be ordered before every worker by the
//! spawns themselves, whether or not the reaper publishes anything.
//!
//! # Setup
//!
//! The cache starts empty at epoch 2. Every one-byte key/value record fills
//! its whole 32-byte arena.
//!
//! # Workers
//!
//! * Writer W (PID 1) registers in slot 0 and inserts X -> VX into the
//!   epoch-2 arena (arena 2), which it fills. The `after_record_publication`
//!   hook stops W right after it published X, before it unpins: W stays
//!   pinned in epoch 2 and never releases its registration.
//! * Rotator T (PID 2) registers in slot 1 once W has, then rotates from
//!   epoch 2 to 3 and from 3 to 4 (through `test_access::rotate()`, which
//!   an epoch-2 pin does not block) and from 4 to 5, which reuses arena 2
//!   and so waits for W's pin, or for a later value of it. If that last
//!   rotation succeeds, T inserts Z -> VZ, which lands in the reused arena
//!   at the offset of W's record X, and looks it up. T keeps its
//!   registration.
//! * Registrar R (PID 3) registers once W has stopped and T has registered.
//!   The registry is full, so it must take W's slot over, which clears W's
//!   pin, and keep it, unless T reaped it first: R then claims it FREE, or
//!   finds the registry full while T still holds it.
//!
//! X and Z have distinct home buckets, so T's insert and lookup of Z never
//! read X's index entry, which would order X's writes before them.
//!
//! # Liveness backend
//!
//! `CrashIdentity` maps the three workers and main to PIDs 1 (W), 2 (T), 3
//! (R) and 4 (main), with start times 100 times the PID. It reports W dead
//! to anyone once W has stopped; that answer acquires W's final writes
//! (through `WRITER_ABANDONED`), as the `GetPid` contract requires. PID 1
//! paired with another start time (that of T or R, which a concurrent reap
//! may read after their takeover of the slot) never ran: it is reported
//! dead without synchronisation. Every other PID is live.
//!
//! # Properties
//!
//! T's first two rotations succeed; the last one may time out on W's pin
//! (W not stopped yet, or R holding W's slot). If it succeeded: T's insert
//! of Z succeeded, Z looks up as exactly VZ and X misses, and the reused
//! arena holds epoch 5 with Z alone (bump 32, occupancy 1). R registered in
//! slot 0 with a fresh registration id, unless T reaped W's slot itself.
//! Nobody owns rotation, and both pins end clear.
//!
//! # Assumption and witness
//!
//! W's `reservation_epoch_hook` assumes that W selects epoch 2, and its
//! `reservation_retry_hook` that its reservation there succeeds (T may
//! have sealed arena 2 first). Otherwise W would rotate itself, or pin
//! another epoch, beyond what this program checks.
//!
//! * `REUSED_AFTER_REGISTRAR_CLEAR`: T's last rotation succeeded without T
//!   learning that W is dead, so it reused arena 2 past W's pin without
//!   reaping it itself, after R took W's slot over.
//!
//! W is joined only under GenMC, where an abandoned thread has finished;
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
    RotationPolicy, arena_state, distinct_bucket_hashes, global_epoch, participant, pinned_epoch,
    registration_id, rotate, rotation_owner,
};
use shm_gen_cache::{
    Cache, CacheStorage, Error, GetPid, ParticipantLock, ParticipantSlot, StaticParams,
    output_buffer, static_config,
};

const BUCKET_COUNT: u32 = 8;

static_config! {
    struct Cfg: CrashIdentity {
        participant_capacity: 2,
        bucket_count: BUCKET_COUNT,
        max_key_size: 1,
        max_value_size: 1,
        record_area_size: 32,
        max_occupancy: 8,
        reservation_chunk_size: 8, // exact record accounting
    }
}

type Participant = ParticipantLock<'static, Cfg>;
type CacheRef = Cache<'static, Cfg>;

const HASHES: [u64; 2] = distinct_bucket_hashes(BUCKET_COUNT);
const WRITER_HASH: u64 = HASHES[0];
const ROTATOR_HASH: u64 = HASHES[1];
const X_KEY: &[u8] = &[1];
const Z_KEY: &[u8] = &[3];
const X_VALUE: &[u8] = &[11];
const Z_VALUE: &[u8] = &[33];

/// Every record exactly fills an arena's record area.
const RECORD_BYTES: u32 = 32;
/// The epoch W stays pinned to, and the arena T reuses for epoch 5.
const DEAD_PIN: u64 = 2;
const RECYCLED_ARENA: u64 = 2;
const WRITER_SLOT: u32 = 0;
const ROTATOR_SLOT: u32 = 1;

// ---------------------------------------------------------------------------
// Liveness backend
// ---------------------------------------------------------------------------

const WRITER_PID: u32 = 1;
const ROTATOR_PID: u32 = 2;
const REGISTRAR_PID: u32 = 3;
const COORDINATOR_PID: u32 = 4;
/// A PID's start time, which tells the incarnations apart.
const fn start_time(pid: u32) -> u64 {
    100 * pid as u64
}

/// Thread identities, by PID; and the scenario's progress flags.
struct CrashIdentity;

static COORDINATOR_THREAD: ThreadSlot = ThreadSlot::new();
static WRITER_THREAD: ThreadSlot = ThreadSlot::new();
static ROTATOR_THREAD: ThreadSlot = ThreadSlot::new();
static REGISTRAR_THREAD: ThreadSlot = ThreadSlot::new();
/// Released once every worker identity is set; each worker acquires it
/// before its first `get_pid`.
static IDENTITIES_READY: AtomicBool = AtomicBool::new(false);
/// W registered (so T registers in slot 1).
static WRITER_REGISTERED: AtomicBool = AtomicBool::new(false);
/// T registered (so R finds the registry full).
static ROTATOR_REGISTERED: AtomicBool = AtomicBool::new(false);
/// W reached its crash point (released before it stops).
static WRITER_ABANDONED: AtomicBool = AtomicBool::new(false);
/// T was told that W is dead (and so may have reaped W's slot itself).
static ROTATOR_LEARNED_DEATH: AtomicBool = AtomicBool::new(false);

impl GetPid for CrashIdentity {
    fn get_pid() -> u32 {
        let me = current_thread();
        if COORDINATOR_THREAD.is(me) {
            COORDINATOR_PID
        } else if WRITER_THREAD.is(me) {
            WRITER_PID
        } else if ROTATOR_THREAD.is(me) {
            ROTATOR_PID
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
        check!((WRITER_PID..=COORDINATOR_PID).contains(&pid));
        Ok(true)
    }

    fn is_live_since(pid: u32, start: u64) -> Result<bool, Error> {
        check!((WRITER_PID..=COORDINATOR_PID).contains(&pid));
        if pid != WRITER_PID {
            check!(start == start_time(pid));
            return Ok(true);
        }
        let live = if start == start_time(WRITER_PID) {
            !WRITER_ABANDONED.load(Acquire)
        } else {
            // W's state paired with the start time of a reaper that took
            // the slot over (T or R): no such incarnation.
            check!(start == start_time(ROTATOR_PID) || start == start_time(REGISTRAR_PID));
            false
        };
        if !live && Self::get_pid() == ROTATOR_PID {
            ROTATOR_LEARNED_DEATH.store(true, Relaxed);
        }
        Ok(live)
    }

    fn reservation_epoch_hook(_slot: &ParticipantSlot, epoch: u64) {
        if Self::get_pid() == WRITER_PID {
            assume(epoch == DEAD_PIN);
        }
    }

    fn reservation_retry_hook(_slot: &ParticipantSlot) {
        if Self::get_pid() == WRITER_PID {
            assume(false);
        }
    }

    /// W's crash point: it published X and is still pinned.
    fn after_record_publication(_slot: &ParticipantSlot) {
        if Self::get_pid() != WRITER_PID {
            return;
        }
        WRITER_ABANDONED.store(true, Release);
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

struct Writer {
    cache: CacheRef,
    /// W's registration id, set before it stops.
    registration: u32,
}

struct Rotator {
    cache: CacheRef,
    /// The last rotation, 4 -> 5, succeeded.
    reused: bool,
}

struct Registrar {
    cache: CacheRef,
    /// The slot R registered in, if it registered.
    slot: Option<&'static ParticipantSlot>,
    registration: u32,
}

/// W: registers, then inserts X and stops pinned right after publishing
/// it. Its registration is never released.
fn insert_and_abandon(writer: &mut Writer) {
    wait_until(&IDENTITIES_READY);
    let mut participant = check_ok!(writer.cache.register_participant());
    check!(participant.slot_index() == WRITER_SLOT);
    writer.registration = registration_id(participant.slot());
    WRITER_REGISTERED.store(true, Release);
    let _ = participant.insert(WRITER_HASH, X_KEY, X_VALUE);
    check!(false, "writer returned after crash point");
}

/// T: rotates three times, the last reusing W's arena, then inserts and
/// looks up Z in it. The registration is kept (leaked).
fn rotate_and_reuse(rotator: &mut Rotator) {
    wait_until(&IDENTITIES_READY);
    wait_until(&WRITER_REGISTERED);
    let mut participant = check_ok!(rotator.cache.register_participant());
    check!(participant.slot_index() == ROTATOR_SLOT);
    ROTATOR_REGISTERED.store(true, Release);
    let policy = RotationPolicy::WaitForOwner;
    check_ok!(rotate(rotator.cache, ROTATOR_SLOT, 2, policy));
    check_ok!(rotate(rotator.cache, ROTATOR_SLOT, 3, policy));
    match rotate(rotator.cache, ROTATOR_SLOT, 4, policy) {
        Ok(()) => {
            rotator.reused = true;
            check_ok!(participant.insert(ROTATOR_HASH, Z_KEY, Z_VALUE));
            check_lookup(&mut participant, WRITER_HASH, X_KEY, None);
            check_lookup(&mut participant, ROTATOR_HASH, Z_KEY, Some(Z_VALUE));
        }
        Err(error) => check!(error == Error::ArenaReuseTimeout),
    }
    // Keep the registry full for R.
    core::mem::forget(participant);
}

/// R: registers into the full registry once W has stopped; the registration
/// is kept (leaked) for main to check.
fn register_as_registrar(registrar: &mut Registrar) {
    wait_until(&IDENTITIES_READY);
    wait_until(&ROTATOR_REGISTERED);
    wait_until(&WRITER_ABANDONED);
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

    let mut writer = Writer {
        cache,
        registration: 0,
    };
    let mut rotator = Rotator {
        cache,
        reused: false,
    };
    let mut registrar = Registrar {
        cache,
        slot: None,
        registration: 0,
    };
    scope(|s| {
        let writer_thread = s.spawn(insert_and_abandon, &mut writer);
        let rotator_thread = s.spawn(rotate_and_reuse, &mut rotator);
        let registrar_thread = s.spawn(register_as_registrar, &mut registrar);
        WRITER_THREAD.set(writer_thread.id());
        ROTATOR_THREAD.set(rotator_thread.id());
        REGISTRAR_THREAD.set(registrar_thread.id());
        IDENTITIES_READY.store(true, Release);

        rotator_thread.join();
        registrar_thread.join();
        if cfg!(sgc_genmc) {
            writer_thread.join();
        } else {
            // SAFETY: W is parked forever in abandon_thread, past its last
            // access to `writer`.
            unsafe { writer_thread.leak() };
        }
    });

    let learned = ROTATOR_LEARNED_DEATH.load(Relaxed);
    let replacement = participant(cache, WRITER_SLOT);
    match registrar.slot {
        // R replaced W in slot 0.
        Some(slot) => {
            check!(core::ptr::eq(slot, replacement));
            check!(registrar.registration != 0);
            check!(registrar.registration != writer.registration);
            check!(registration_id(slot) == registrar.registration);
        }
        // T held W's slot between its own reap and release.
        None => check!(learned),
    }
    check!(rotation_owner(cache, Relaxed).registration_id() == 0);
    let reused = rotator.reused;
    if reused {
        check!(global_epoch(cache).load(Relaxed) == 5);
        let arena = arena_state(cache, RECYCLED_ARENA, Relaxed);
        check!(arena.epoch == 5 && !arena.sealed);
        check!(arena.bump == RECORD_BYTES && arena.occupancy == Some(1));
    }
    check!(pinned_epoch(replacement).load(Relaxed) == 0);
    check!(pinned_epoch(participant(cache, ROTATOR_SLOT)).load(Relaxed) == 0);

    witness!("REUSED_AFTER_REGISTRAR_CLEAR", reused && !learned);
    0
}

fn wait_until(flag: &AtomicBool) {
    while !flag.load(Acquire) {}
}

/// `key` misses, or hits with exactly `value` written into the caller's
/// buffer.
fn check_lookup(participant: &mut Participant, hash: u64, key: &[u8], value: Option<&[u8]>) {
    let mut output = output_buffer!(1);
    let output_start = output.words().as_ptr().cast::<u8>();
    let found = check_ok!(participant.lookup(hash, key, &mut output));
    let Some(expected) = value else {
        check!(found.is_none());
        return;
    };
    let found = check_ok!(found);
    check!(found.len() == expected.len());
    check!(found.as_ptr() == output_start);
    // Compare whole words: lookups store whole words, and a byte read of
    // one would be a mixed-size access to GenMC.
    check!(words_eq(output.words(), &padded_words::<1>(expected)));
}
