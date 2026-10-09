//! A registrar that reaps a dead participant's slot does not inherit the
//! dead participant's reservation chunk.
//!
//! A registrar keeps the slot it reaps, without the claim from FREE that
//! resets a slot's chunk, so the reap itself must reset it. The staged
//! chunk is torn, as a participant that died midway through recording a
//! new chunk leaves it: the current epoch with an older chunk's cursor and
//! end, which cover bytes another participant has since claimed. A
//! replacement that kept it would write its first record over that
//! participant's.
//!
//! # Staged crash state (before any worker exists)
//!
//! The cache starts empty at epoch 2. Every one-byte key/value record takes
//! 32 bytes and a chunk 64, so a first insert claims a chunk and leaves a
//! one-record tail.
//!
//! 1. Dead participant D (PID 1, start 101) registers in slot 0 and inserts
//!    X -> VX: its chunk is [0, 64), X at 0.
//! 2. Live participant P (PID 2, start 202) registers in slot 1 and inserts
//!    Y -> VY: its chunk is [64, 128), Y at 64.
//! 3. D dies in its next reservation, pinned in epoch 2, after recording the
//!    epoch of a new chunk and before its cursor and end: slot 0's chunk is
//!    (epoch 2, cursor 64, end 128), which covers Y. D's registration is
//!    leaked.
//!
//! The `StagedDeath` backend reports PID 1 dead and PID 2 live, without
//! synchronising anything. Only R asks it, about D's registration, so it
//! checks that the start time belongs to the PID paired with it.
//!
//! # Worker
//!
//! Registrar R (PID 2) registers: the registry is full, so it must take
//! slot 0 over from D, then inserts Z -> VZ.
//!
//! # Properties
//!
//! After the join: R registered in slot 0 with a fresh registration id and
//! its insert succeeded, from a fresh chunk: slot 0's chunk is (epoch 2,
//! cursor 160, end 192) and the epoch-2 arena's bump 192, with three
//! records. X, Y and Z look up as exactly VX, VY and VZ, and both pins are
//! clear.
//!
//! # Witness
//!
//! None: the program has a single execution, which the safety check
//! covers.
//!
//! No `assume` is used.

#![no_std]
#![no_main]

use core::sync::atomic::AtomicU32;
use core::sync::atomic::Ordering::{Relaxed, Release};

use genmc_harness::{check, check_ok, padded_words, scope, words_eq};
use shm_gen_cache::test_access::{
    arena_state, chunk, distinct_bucket_hashes, global_epoch, participant, pinned_epoch,
    registration_id,
};
use shm_gen_cache::{
    Cache, CacheStorage, Error, GetPid, ParticipantLock, ParticipantSlot, StaticParams,
    output_buffer, static_config,
};

const BUCKET_COUNT: u32 = 8;

static_config! {
    struct Cfg: StagedDeath {
        participant_capacity: 2,
        bucket_count: BUCKET_COUNT,
        max_key_size: 1,
        max_value_size: 1,
        record_area_size: 192,
        max_occupancy: 8,
        reservation_chunk_size: CHUNK_BYTES,
    }
}

type Participant = ParticipantLock<'static, Cfg>;
type CacheRef = Cache<'static, Cfg>;

struct Record {
    hash: u64,
    key: &'static [u8; 1],
    value: &'static [u8; 1],
}

const HASHES: [u64; 3] = distinct_bucket_hashes(BUCKET_COUNT);
const X: Record = Record {
    hash: HASHES[0],
    key: &[1],
    value: &[11],
};
const Y: Record = Record {
    hash: HASHES[1],
    key: &[2],
    value: &[22],
};
const Z: Record = Record {
    hash: HASHES[2],
    key: &[3],
    value: &[33],
};

const RECORD_BYTES: u32 = 32;
const CHUNK_BYTES: u32 = 64;
const EPOCH: u64 = 2;
const ARENA: u64 = 2;
const DEAD_SLOT: u32 = 0;
/// The torn chunk left in D's slot: P's chunk, which holds Y.
const TORN_CURSOR: u32 = CHUNK_BYTES;
const TORN_END: u32 = 2 * CHUNK_BYTES;

const DEAD_PID: u32 = 1;
const LIVE_PID: u32 = 2;
/// A PID's start time, which tells the incarnations apart.
const fn start_time(pid: u32) -> u64 {
    100 * pid as u64 + pid as u64
}

/// Liveness backend: PID 1 is dead, PID 2 live. Only setup changes the
/// caller's PID; the worker runs as PID 2.
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
        check!(pid == DEAD_PID || pid == LIVE_PID);
        Ok(pid == LIVE_PID)
    }

    fn is_live_since(pid: u32, start: u64) -> Result<bool, Error> {
        // Only R asks: no concurrent takeover can pair a PID with another
        // incarnation's start time.
        check!(start == start_time(pid));
        Self::is_live(pid)
    }
}

struct Registrar {
    cache: CacheRef,
    /// The slot R registered in, if it registered.
    slot: Option<&'static ParticipantSlot>,
    registration: u32,
    inserted: bool,
}

/// R: registers, reaping slot 0, and inserts Z; the registration is kept
/// (leaked) for main to check.
fn register_and_insert(registrar: &mut Registrar) {
    let Ok(mut lock) = registrar.cache.register_participant() else {
        return;
    };
    registrar.slot = Some(lock.slot());
    registrar.registration = registration_id(lock.slot());
    registrar.inserted = lock.insert(Z.hash, Z.key, Z.value).is_ok();
    core::mem::forget(lock);
}

#[unsafe(no_mangle)]
extern "C" fn main() -> i32 {
    static STORAGE: <Cfg as StaticParams>::Storage = CacheStorage::new();
    // SAFETY: zeroed static storage, used only through this cache.
    let cache = check_ok!(unsafe { Cache::initialize(STORAGE.as_mut_ptr(), STORAGE.len(), Cfg) });

    let dead_registration = stage_dead_participant(cache);
    CALLER.store(LIVE_PID, Relaxed);
    let mut live = check_ok!(cache.register_participant());
    check_ok!(live.insert(Y.hash, Y.key, Y.value));
    stage_torn_chunk(cache);

    let mut r = Registrar {
        cache,
        slot: None,
        registration: 0,
        inserted: false,
    };
    scope(|s| s.spawn(register_and_insert, &mut r).join());
    let replacement = check_ok!(r.slot);
    check!(core::ptr::eq(replacement, participant(cache, DEAD_SLOT)));
    check!(r.registration != 0 && r.registration != dead_registration);
    check!(r.registration != registration_id(live.slot()));
    check!(registration_id(replacement) == r.registration);
    check!(r.inserted);
    for r in [&X, &Y, &Z] {
        check_lookup(&mut live, r);
    }

    // Z went to a fresh chunk, after P's.
    let (epoch, cursor, end) = chunk(replacement);
    check!(epoch.load(Relaxed) == EPOCH);
    check!(cursor.load(Relaxed) == 2 * CHUNK_BYTES + RECORD_BYTES);
    check!(end.load(Relaxed) == 3 * CHUNK_BYTES);
    check!(global_epoch(cache).load(Relaxed) == EPOCH);
    let arena = arena_state(cache, ARENA, Relaxed);
    check!(arena.epoch == EPOCH as u32 && !arena.sealed);
    check!(arena.bump == 3 * CHUNK_BYTES && arena.occupancy == Some(3));

    check!(pinned_epoch(replacement).load(Relaxed) == 0);
    check!(pinned_epoch(live.slot()).load(Relaxed) == 0);
    0
}

/// D: registers in slot 0 and inserts X, which claims D's first chunk;
/// the registration is leaked. Returns its registration id.
fn stage_dead_participant(cache: CacheRef) -> u32 {
    let mut dead = check_ok!(cache.register_participant());
    check!(core::ptr::eq(dead.slot(), participant(cache, DEAD_SLOT)));
    check_ok!(dead.insert(X.hash, X.key, X.value));
    let registration = registration_id(dead.slot());
    core::mem::forget(dead);
    registration
}

/// D died in its next reservation, pinned, with the epoch of a new chunk
/// recorded and an older chunk's cursor and end.
fn stage_torn_chunk(cache: CacheRef) {
    let dead = participant(cache, DEAD_SLOT);
    let (epoch, cursor, end) = chunk(dead);
    check!(epoch.load(Relaxed) == EPOCH);
    check!(cursor.load(Relaxed) == RECORD_BYTES && end.load(Relaxed) == CHUNK_BYTES);
    cursor.store(TORN_CURSOR, Relaxed);
    end.store(TORN_END, Relaxed);
    pinned_epoch(dead).store(EPOCH, Release);
}

/// `r` looks up as exactly its value, written into the caller's buffer.
fn check_lookup(participant: &mut Participant, r: &Record) {
    let mut output = output_buffer!(1);
    let output_start = output.words().as_ptr().cast::<u8>();
    let value = check_ok!(check_ok!(participant.lookup(r.hash, r.key, &mut output)));
    check!(value.len() == r.value.len());
    check!(value.as_ptr() == output_start);
    // Compare whole words: lookups store whole words, and a byte read of
    // one would be a mixed-size access to GenMC.
    check!(words_eq(output.words(), &padded_words::<1>(r.value)));
}
