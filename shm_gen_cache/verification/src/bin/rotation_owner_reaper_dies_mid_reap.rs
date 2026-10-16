//! A dead rotation owner whose slot reaper also died mid-reap does not keep
//! rotation ownership: another participant takes it over, and a registrar
//! reaps the slot again.
//!
//! # Staged crash state (before any worker exists)
//!
//! 1. Slot 0 is registered by PID 1 (start 101); its registration is leaked,
//!    since a dead rotation owner never unregisters.
//! 2. The writer W (PID 2, start 202) registers in slot 1 and publicly
//!    inserts K0, which fills epoch 2's 32-byte record area.
//! 3. Slot 0 acquires epoch-2 rotation ownership with the real
//!    `acquire_rotation()`, through `test_access` (the post-owner-CAS,
//!    pre-seal crash state of `dead_rotation_owner_reaping_races_takeover`),
//!    and is declared dead. It stays unpinned, as the rotation protocol
//!    requires.
//! 4. A slot reaper, PID 3 (start 303), stole slot 0 and died while the
//!    slot was registered to itself: REGISTERED(PID 3, id), with a fresh
//!    registration id. A thread cannot stop in the middle of a synchronous
//!    reap, so `test_access::stage_interrupted_reap()` stages that state.
//!    Slot 0 no longer holds the owner's registration, and only a reap of
//!    the dead reaper's claim recovers it. (`reaper_dies_mid_reap` and
//!    `reaping_dead_reaper_wakes_blocked_rotation` stage a reaper that died
//!    while the slot was still INITIALIZING.)
//! 5. Setup check: epoch 2, owner = (slot 0, PID 1's registration), the
//!    epoch-2 arena unsealed with bump 32 and occupancy 1, both slots
//!    unpinned.
//!
//! The `StagedDeath` backend reports PIDs 1 and 3 dead and PID 2 live,
//! without synchronising anything. Only R's reap asks it about a start
//! time, that of the staged claim, so it checks that the start time belongs
//! to the PID paired with it.
//!
//! # Workers
//!
//! * Registrar R (PID 2) reads the owner word (relaxed, for the witness
//!   only), then registers publicly. All slots are taken, so it must steal
//!   slot 0 from the dead reaper and keep it; its registration is kept
//!   (leaked) for main to check.
//! * Writer W publicly inserts K1, which needs a rotation before it can
//!   reserve space. The owner word still names PID 1's registration, so W
//!   must take that ownership over, racing R's reap of slot 0.
//!
//! W's liveness check of the owner reads slot 0's state: the dead reaper's
//! claim or R's, neither of them the owner's registration, so W takes
//! ownership over at once, without asking the backend. That is safe: the
//! staged claim precedes the spawns, and R publishes its own with a
//! release, after its verdict that the claim it replaced was dead. W's
//! insert therefore never times out on the owner: no observation pairs the
//! owner's registration with a start time (unlike
//! `dead_rotation_owner_reaping_races_takeover`, whose slot 0 starts
//! REGISTERED to the owner), and only W writes the owner word, so its
//! takeover cannot fail.
//!
//! # Properties
//!
//! After the joins: W's insert succeeded and its registration is unchanged;
//! R registered in slot 0 with a fresh, non-zero registration id; K1 looks
//! up as exactly V1. Final state: epoch 3, no rotation owner, the epoch-2
//! arena sealed with bump 32 and occupancy 1, the epoch-3 arena unsealed
//! holding K1 alone (bump 32, occupancy 1), both slots unpinned.
//!
//! A slot that a dead reaper left unreapable would instead leave R with a
//! full registry, and an owner check that waited for the end of the reap
//! would time out every takeover.
//!
//! # Witness
//!
//! * `TAKEOVER_BEFORE_REAP`: R's read of the owner word, before its reap,
//!   saw that W had taken ownership over. W's owner check, which precedes
//!   its ownership takeover, then read the dead reaper's claim, not R's
//!   (the state the protocol before slot reaping by claim reported live).
//!
//! No `assume` is used.

#![no_std]
#![no_main]

use core::sync::atomic::AtomicU32;
use core::sync::atomic::Ordering::Relaxed;

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
/// the caller's PID; both workers run as PID 2.
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

    fn is_live_since(pid: u32, start: u64) -> Result<bool, Error> {
        // Only R's reap asks, about the staged claim (W's owner check never
        // finds the owner's registration): no concurrent reap can pair a PID
        // with another incarnation's start time.
        check!(start == start_time(pid));
        Self::is_live(pid)
    }
}

struct Registrar {
    cache: CacheRef,
    /// The dead owner's registration id.
    dead_owner: u32,
    /// The owner word no longer named the dead owner before R's reap.
    owner_moved: bool,
    /// The slot R registered in, if it registered.
    slot: Option<&'static ParticipantSlot>,
    registration: u32,
}

struct Writer<'a> {
    participant: Lent<'a, Participant>,
    succeeded: bool,
}

/// R: register, reaping slot 0 and keeping it; the registration is kept
/// (leaked) for main to check.
fn register(registrar: &mut Registrar) {
    let owner = rotation_owner(registrar.cache, Relaxed);
    registrar.owner_moved = owner.registration_id() != registrar.dead_owner;
    if let Ok(lock) = registrar.cache.register_participant() {
        let slot = lock.slot();
        registrar.registration = registration_id(slot);
        registrar.slot = Some(slot);
        core::mem::forget(lock);
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
    check_setup_state(cache, old_registration);

    let mut r = Registrar {
        cache,
        dead_owner: old_registration,
        owner_moved: false,
        slot: None,
        registration: 0,
    };
    let mut w = Writer {
        participant: Lent::new(&mut writer_lock),
        succeeded: false,
    };
    scope(|s| {
        let tr = s.spawn(register, &mut r);
        let tw = s.spawn(insert_past_dead_owner, &mut w);
        tw.join();
        tr.join();
    });
    check!(w.succeeded);
    check!(registration_id(writer_slot) == writer_registration);
    let replacement = check_ok!(r.slot);
    check!(core::ptr::eq(replacement, participant(cache, DEAD_SLOT)));
    check!(r.registration != 0 && r.registration != old_registration);
    check!(r.registration != writer_registration);
    check!(registration_id(replacement) == r.registration);

    check_final_state(cache);
    check_k1_value(&mut writer_lock);
    check_both_slots_unpinned(cache);

    witness!("TAKEOVER_BEFORE_REAP", r.owner_moved);
    0
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
