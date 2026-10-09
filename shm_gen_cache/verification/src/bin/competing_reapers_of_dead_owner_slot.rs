//! Two registrars competing to steal a dead rotation owner's slot from a
//! dead slot reaper grant exactly one registration, while a writer takes
//! the stale rotation ownership over.
//!
//! # Staged crash state (before any worker exists)
//!
//! As in `rotation_owner_reaper_dies_mid_reap`:
//!
//! 1. Slot 0 is registered by PID 1 (start 101); its registration is leaked,
//!    since a dead rotation owner never unregisters.
//! 2. The writer W (PID 2, start 202) registers in slot 1 and publicly
//!    inserts K0, which fills epoch 2's 32-byte record area.
//! 3. Slot 0 acquires epoch-2 rotation ownership with the real
//!    `acquire_rotation()`, through `test_access`, and is declared dead. It
//!    stays unpinned, as the rotation protocol requires.
//! 4. A slot reaper, PID 3 (start 303), stole slot 0 and died while the slot
//!    was registered to itself: REGISTERED(PID 3, id), with a fresh
//!    registration id. `test_access::stage_interrupted_reap()` stages that
//!    state.
//!
//! The registry is then full: slot 0 can only be recovered by stealing it
//! from the dead reaper. Slot reaping by the rotation (R3) never competes:
//! the dead owner is unpinned.
//!
//! # Liveness backend
//!
//! `StagedDeath` reports PIDs 1 and 3 dead and PID 2 live, without
//! synchronising anything. Every worker runs as PID 2 (start 202), so
//! registration ids, not PIDs, tell the registrars apart. A registrar
//! whose steal reads slot 0 still REGISTERED to PID 3, then the start time
//! that the winner stored after its claiming exchange, pairs PID 3 with
//! start 202: an incarnation that never ran, so the backend reports it dead
//! too, and counts the question. That registrar's verdict is stale, and its
//! claiming exchange must fail. Any other start time must belong to the PID
//! paired with it.
//!
//! # Workers
//!
//! * Registrars R1 and R2 each call `register_participant()` once. All slots
//!   are taken, so each tries to steal slot 0 from the dead reaper; the
//!   winner keeps its registration (leaked) for main to check, and the loser
//!   must find the registry full.
//! * Writer W publicly inserts K1, which needs a rotation before it can
//!   reserve space. The owner word still names PID 1's registration, so W
//!   must take that ownership over, racing both steals.
//!
//! W's owner check reads slot 0's state: the dead reaper's claim or the
//! winner's, neither of them the owner's registration, so W takes ownership
//! over at once, without asking the backend. A losing registrar writes
//! nothing to slot 0: its claiming exchange fails, and it stores no start
//! time.
//!
//! # Properties
//!
//! After the joins: exactly one registrar registered, in slot 0, with a
//! fresh, non-zero registration id that slot 0 still holds; the other got
//! `ParticipantRegistryFull`. W's insert succeeded and its registration is
//! unchanged; K1 looks up as exactly V1. Final state: epoch 3, no rotation
//! owner, the epoch-2 arena sealed with bump 32 and occupancy 1, the epoch-3
//! arena unsealed holding K1 alone (bump 32, occupancy 1), both slots
//! unpinned.
//!
//! # Witnesses
//!
//! * `BOTH_EXAMINED_DEAD_REAPER`: both registrars asked the backend about
//!   PID 3, so both competed for the dead reaper's claim.
//! * `STALE_PAIR_REJECTED`: the loser paired PID 3 with the winner's start
//!   time, found it dead, and still lost.
//!
//! No `assume` is used.

#![no_std]
#![no_main]

use core::sync::atomic::Ordering::Relaxed;
use core::sync::atomic::{AtomicBool, AtomicU32};

use genmc_harness::{Lent, check, check_ok, padded_words, scope, witness, words_eq};
use shm_gen_cache::test_access::{
    ReapCrash, RotationPolicy, acquire_rotation, arena_state, global_epoch, participant,
    pinned_epoch, registration_id, rotation_owner, stage_interrupted_reap,
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
const KEYS: [&[u8; 1]; 2] = [&[0], &[1]];
const VALUES: [&[u8; 1]; 2] = [&[10], &[11]];

/// Every record exactly fills an arena's record area.
const RECORD_BYTES: u32 = 32;
/// The rotated epochs and their arenas (`epoch % 3`).
const OLD_EPOCH: u64 = 2;
const NEW_EPOCH: u64 = 3;
const OLD_ARENA: u64 = 2;
const NEW_ARENA: u64 = 0;
/// The dead owner's slot.
const DEAD_SLOT: u32 = 0;

const DEAD_PID: u32 = 1;
const LIVE_PID: u32 = 2;
const DEAD_REAPER_PID: u32 = 3;
/// A PID's start time, which tells the incarnations apart.
const fn start_time(pid: u32) -> u64 {
    100 * pid as u64 + pid as u64
}

/// Liveness backend: PIDs 1 and 3 are dead, PID 2 live. Only setup changes
/// the caller's PID; every worker runs as PID 2.
struct StagedDeath;

static CALLER: AtomicU32 = AtomicU32::new(DEAD_PID);
/// Backend questions about PID 3, with any start time.
static DEAD_REAPER_CHECKS: AtomicU32 = AtomicU32::new(0);
/// A question paired PID 3 with the live workers' start time.
static STALE_PAIR: AtomicBool = AtomicBool::new(false);

impl GetPid for StagedDeath {
    fn get_pid() -> u32 {
        CALLER.load(Relaxed)
    }

    fn get_start_time() -> u64 {
        start_time(Self::get_pid())
    }

    fn is_live(pid: u32) -> Result<bool, Error> {
        check!(pid == DEAD_PID || pid == LIVE_PID || pid == DEAD_REAPER_PID);
        if pid == DEAD_REAPER_PID {
            DEAD_REAPER_CHECKS.fetch_add(1, Relaxed);
        }
        Ok(pid == LIVE_PID)
    }

    fn is_live_since(pid: u32, start: u64) -> Result<bool, Error> {
        // A stale steal can pair the dead reaper's PID with the winner's
        // start time: an incarnation that never ran.
        if pid == DEAD_REAPER_PID && start == start_time(LIVE_PID) {
            STALE_PAIR.store(true, Relaxed);
        } else {
            check!(start == start_time(pid));
        }
        Self::is_live(pid)
    }
}

struct Registrar {
    cache: CacheRef,
    /// The slot this registrar registered in, if it registered.
    slot: Option<&'static ParticipantSlot>,
    registration: u32,
    registry_full: bool,
}

struct Writer<'a> {
    participant: Lent<'a, Participant>,
    succeeded: bool,
}

/// R1/R2: register once; a registration is kept (leaked) for main to check.
fn register(registrar: &mut Registrar) {
    match registrar.cache.register_participant() {
        Ok(lock) => {
            let slot = lock.slot();
            registrar.registration = registration_id(slot);
            registrar.slot = Some(slot);
            core::mem::forget(lock);
        }
        Err(error) => {
            check!(error == Error::ParticipantRegistryFull);
            registrar.registry_full = true;
        }
    }
}

/// W: insert K1, which must rotate past the stale owner.
fn insert_past_dead_owner(writer: &mut Writer) {
    writer.succeeded = writer.participant.insert(HASH, KEYS[1], VALUES[1]).is_ok();
}

#[unsafe(no_mangle)]
extern "C" fn main() -> i32 {
    static STORAGE: <Cfg as StaticParams>::Storage = CacheStorage::new();
    // SAFETY: zeroed static storage, used only through this cache.
    let cache = check_ok!(unsafe { Cache::initialize(STORAGE.as_mut_ptr(), STORAGE.len(), Cfg) });

    // Slot 0, PID 1. A dead rotation owner cannot unregister, so the
    // registration is leaked; no worker uses it.
    let dead = check_ok!(cache.register_participant());
    check!(core::ptr::eq(dead.slot(), participant(cache, DEAD_SLOT)));
    let old_registration = registration_id(dead.slot());
    core::mem::forget(dead);

    CALLER.store(LIVE_PID, Relaxed);
    let mut writer_lock = check_ok!(cache.register_participant());
    let writer_slot = writer_lock.slot();
    let writer_registration = registration_id(writer_slot);
    check_ok!(writer_lock.insert(HASH, KEYS[0], VALUES[0]));

    stage_dead_owner_and_reaper(cache);
    let dead_reaper_registration = registration_id(participant(cache, DEAD_SLOT));
    check_setup_state(cache, old_registration);

    let mut r1 = Registrar::new(cache);
    let mut r2 = Registrar::new(cache);
    let mut w = Writer {
        participant: Lent::new(&mut writer_lock),
        succeeded: false,
    };
    scope(|s| {
        let t1 = s.spawn(register, &mut r1);
        let t2 = s.spawn(register, &mut r2);
        let tw = s.spawn(insert_past_dead_owner, &mut w);
        tw.join();
        t2.join();
        t1.join();
    });
    check!(w.succeeded);
    check!(registration_id(writer_slot) == writer_registration);

    // Exactly one registrar won slot 0; the other found the registry full.
    check!(r1.slot.is_some() != r2.slot.is_some());
    check!(r1.registry_full == r1.slot.is_none());
    check!(r2.registry_full == r2.slot.is_none());
    let winner = if r1.slot.is_some() { &r1 } else { &r2 };
    let slot = check_ok!(winner.slot);
    check!(core::ptr::eq(slot, participant(cache, DEAD_SLOT)));
    let registration = winner.registration;
    check!(registration != 0 && registration != old_registration);
    check!(registration != dead_reaper_registration && registration != writer_registration);
    check!(registration_id(slot) == registration);

    check_final_state(cache);
    check_k1_value(&mut writer_lock);
    check_both_slots_unpinned(cache);

    witness!(
        "BOTH_EXAMINED_DEAD_REAPER",
        DEAD_REAPER_CHECKS.load(Relaxed) == 2
    );
    witness!("STALE_PAIR_REJECTED", STALE_PAIR.load(Relaxed));
    0
}

impl Registrar {
    fn new(cache: CacheRef) -> Self {
        Registrar {
            cache,
            slot: None,
            registration: 0,
            registry_full: false,
        }
    }
}

/// The real `acquire_rotation()` of epoch 2, as slot 0 (with the
/// `WaitForOwner` policy), then the mid-reap state left by the dead reaper
/// PID 3.
fn stage_dead_owner_and_reaper(cache: CacheRef) {
    let epoch = global_epoch(cache).load(Relaxed);
    check!(epoch == OLD_EPOCH);
    check_ok!(acquire_rotation(
        cache,
        DEAD_SLOT,
        epoch,
        RotationPolicy::WaitForOwner
    ));
    stage_interrupted_reap(
        cache,
        DEAD_SLOT,
        DEAD_REAPER_PID,
        start_time(DEAD_REAPER_PID),
        ReapCrash::Registered,
    );
}

/// K1 looks up as exactly V1, written into the caller's buffer.
fn check_k1_value(participant: &mut Participant) {
    let mut output = output_buffer!(1);
    let output_start = output.words().as_ptr().cast::<u8>();
    let value = check_ok!(check_ok!(participant.lookup(HASH, KEYS[1], &mut output)));
    check!(value.len() == VALUES[1].len());
    check!(value.as_ptr() == output_start);
    check!(words_eq(output.words(), &padded_words::<1>(VALUES[1])));
}

/// The state just before the workers start.
fn check_setup_state(cache: CacheRef, old_registration: u32) {
    check!(global_epoch(cache).load(Relaxed) == OLD_EPOCH);
    let owner = rotation_owner(cache, Relaxed);
    check!(owner.slot() == DEAD_SLOT && owner.registration_id() == old_registration);
    let current = arena_state(cache, OLD_ARENA, Relaxed);
    check!(current.epoch == OLD_EPOCH as u32 && !current.sealed);
    check!(current.bump == RECORD_BYTES && current.occupancy == Some(1));
    check_both_slots_unpinned(cache);
}

/// The state after the joins: rotated to epoch 3, K1 alone in it.
fn check_final_state(cache: CacheRef) {
    check!(global_epoch(cache).load(Relaxed) == NEW_EPOCH);
    check!(rotation_owner(cache, Relaxed).registration_id() == 0);
    let old = arena_state(cache, OLD_ARENA, Relaxed);
    check!(old.epoch == OLD_EPOCH as u32 && old.sealed);
    check!(old.bump == RECORD_BYTES && old.occupancy == Some(1));
    let current = arena_state(cache, NEW_ARENA, Relaxed);
    check!(current.epoch == NEW_EPOCH as u32 && !current.sealed);
    check!(current.bump == RECORD_BYTES && current.occupancy == Some(1));
    check_both_slots_unpinned(cache);
}

fn check_both_slots_unpinned(cache: CacheRef) {
    check!(pinned_epoch(participant(cache, 0)).load(Relaxed) == 0);
    check!(pinned_epoch(participant(cache, 1)).load(Relaxed) == 0);
}
