//! Reaping a dead participant slot must not let a rotation reuse an arena
//! past a *different*, still-live participant's insertion reservation.
//!
//! The dead slot comes first in scan order, so the rotation must reap it
//! and then continue the scan, rather than treat one successful slot
//! reaping as permission to reuse the arena.
//!
//! # Staged crash state (before any worker exists)
//!
//! Slot 0 is registered through the public API with deterministic PID 1;
//! its registration is leaked (never dropped, so `release_claim()` never
//! runs) and its pin is set to epoch 2, as if PID 1 had died inside an
//! insert. This is a crash-state fixture, not an OS process exit or an
//! actually interrupted insert, and no dead payload is needed for this scan
//! invariant. All fixture writes happen before the spawns, which provides
//! the happens-before ordering a death observation requires. The liveness
//! backend (`StagedDeath`) reports PID 1 dead and PID 2 live; it supplies
//! no synchronisation between workers. The rotation's production slot
//! reaping itself takes the dead slot over (REGISTERED to PID 1, then
//! INITIALIZING and REGISTERED to the rotator), which clears the dead pin,
//! and releases it (FREE).
//!
//! # Workers
//!
//! Slots 1 and 2 belong to the live writer and rotator (PID 2). Every
//! one-byte key/value record fills its 32-byte arena.
//!
//! * Writer: inserts K -> VK.
//! * Rotator: assumes the epoch-2 arena's bump is 32 before doing anything,
//!   i.e. that it observes the writer's actual reservation. It then inserts
//!   F -> VF twice and G -> VG. The first two inserts must succeed (at
//!   epochs 3 and 4) without reaping the dead slot, which does not block
//!   them yet. The third rotates 4 -> 5: it reaps slot 0 and then scans the
//!   live writer's slot 1.
//!
//! The third insert may succeed (the writer has unpinned) or fail with
//! `ArenaReuseTimeout` (the writer still blocks reuse). Either way slot 0
//! must now be FREE and unpinned and the writer's slot still claimed. On a
//! timeout the rotator also checks, right away: epoch 4, the old epoch-2
//! incarnation intact (sealed, bump 32), rotation ownership released, the
//! current arena at epoch 4 with one record, and its own pin clear. The
//! writer may finish before that snapshot, so the old occupancy may be 0 or
//! 1: a timeout does not imply the writer is still pinned at that later
//! observation.
//!
//! # After the joins
//!
//! The writer has published successfully and both live pins are clear. On
//! the timeout branch the epoch-2 arena still has bump/occupancy 32/1, and a
//! single public retry must reuse it at epoch 5. Both branches then check
//! the same final arena states and that G looks up as exactly VG. K has
//! legitimately aged out; its retention is not required.
//!
//! # Witness
//!
//! `REAPED_THEN_PIN_BLOCKED` follows every check and proves reachable: dead
//! slot reaping, then a live-pin timeout, then a successful retry. Neither
//! slot reaping nor the timeout is assumed, and a rotation-owner timeout
//! cannot satisfy it (the checks reject it).
//!
//! # Assumption
//!
//! The rotator's `assume` on the epoch-2 bump. Natively `assume` is a no-op,
//! so the rotator returns early and main skips the scenario checks when the
//! observation is false.
//!
//! No production hooks, direct rotate/probe calls, copied implementation or
//! extra GenMC variants are used. Out of scope: fidelity of backend death
//! detection, concurrent slot replacement, multiple reapers, reaper death
//! (see `reaper_dies_mid_reap`).

#![no_std]
#![no_main]

use core::sync::atomic::AtomicU32;
use core::sync::atomic::Ordering::{Relaxed, Release, SeqCst};

use genmc_harness::{Lent, assume, check, check_ok, padded_words, scope, witness};
use shm_gen_cache::test_access::{
    arena_ctl, arena_state, global_epoch, is_free, participant, pinned_epoch, rotation_owner,
};
use shm_gen_cache::{
    Cache, CacheStorage, Error, GetPid, ParticipantLock, ParticipantSlot, StaticParams,
    output_buffer, static_config,
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
const RESERVED: (&[u8], &[u8]) = (&[1], &[11]); // K -> VK
const FILLER: (&[u8], &[u8]) = (&[2], &[22]); // F -> VF
const FINAL: (&[u8], &[u8]) = (&[3], &[33]); // G -> VG

/// The dead participant's PID and the live one's.
const DEAD_PID: u32 = 1;
const LIVE_PID: u32 = 2;
/// The epoch the dead participant stays pinned to.
const DEAD_PIN: u64 = 2;

/// Liveness backend: PID 1 is dead, PID 2 live. The caller's PID is set
/// only during setup; both workers run as PID 2.
struct StagedDeath;

static CALLER: AtomicU32 = AtomicU32::new(DEAD_PID);

impl GetPid for StagedDeath {
    fn get_pid() -> u32 {
        CALLER.load(Relaxed)
    }

    fn get_start_time() -> u64 {
        start_time_of(Self::get_pid())
    }

    fn is_live(pid: u32) -> Result<bool, Error> {
        check!(pid == DEAD_PID || pid == LIVE_PID);
        Ok(pid == LIVE_PID)
    }

    fn is_live_since(pid: u32, start_time: u64) -> Result<bool, Error> {
        check!(start_time == start_time_of(pid));
        Self::is_live(pid)
    }
}

fn start_time_of(pid: u32) -> u64 {
    100 + pid as u64
}

#[derive(Clone, Copy, PartialEq, Eq)]
enum Outcome {
    Unset,
    Success,
    OwnerTimeout,
    ReuseTimeout,
    OtherError,
}

struct Writer<'a> {
    participant: Lent<'a, Participant>,
    result: Outcome,
}

struct Rotator<'a> {
    participant: Lent<'a, Participant>,
    writer_slot: &'static ParticipantSlot,
    writer_reserved_in_epoch_two: bool,
    results: [Outcome; 3],
}

fn reserve_and_publish(writer: &mut Writer) {
    writer.result = insert(&mut writer.participant, RESERVED);
}

fn rotate_and_attempt_reuse(rotator: &mut Rotator) {
    let cache = rotator.participant.cache();
    rotator.writer_reserved_in_epoch_two = epoch_two_bump_is_32(cache);
    assume(rotator.writer_reserved_in_epoch_two);
    if !rotator.writer_reserved_in_epoch_two {
        return;
    }
    let p = &mut rotator.participant;
    rotator.results[0] = insert(p, FILLER);
    rotator.results[1] = insert(p, FILLER);
    check_dead_slot_not_reaped(cache);
    rotator.results[2] = insert(p, FINAL);
    check_dead_slot_reaped(cache);
    check!(!is_free(rotator.writer_slot));
    if rotator.results[2] == Outcome::ReuseTimeout {
        check_blocked_snapshot(cache);
        check!(pinned_epoch(p.slot()).load(SeqCst) == 0);
    }
}

#[unsafe(no_mangle)]
extern "C" fn main() -> i32 {
    static STORAGE: <Cfg as StaticParams>::Storage = CacheStorage::new();
    // SAFETY: zeroed static storage, used only through this cache.
    let cache = check_ok!(unsafe { Cache::initialize(STORAGE.as_mut_ptr(), STORAGE.len(), Cfg) });

    // Stage the dead participant (slot 0, PID 1) pinned to epoch 2. Its
    // registration is leaked: a dead owner never releases its claim.
    let dead = check_ok!(cache.register_participant());
    pinned_epoch(dead.slot()).store(DEAD_PIN, Release);
    core::mem::forget(dead);
    check_dead_slot_not_reaped(cache);
    CALLER.store(LIVE_PID, Relaxed);

    let mut writer_lock = check_ok!(cache.register_participant());
    let mut rotator_lock = check_ok!(cache.register_participant());
    let writer_slot = writer_lock.slot();
    let rotator_slot = rotator_lock.slot();

    let mut writer = Writer {
        participant: Lent::new(&mut writer_lock),
        result: Outcome::Unset,
    };
    let mut rotator = Rotator {
        participant: Lent::new(&mut rotator_lock),
        writer_slot,
        writer_reserved_in_epoch_two: false,
        results: [Outcome::Unset; 3],
    };
    scope(|s| {
        let t1 = s.spawn(reserve_and_publish, &mut writer);
        let t2 = s.spawn(rotate_and_attempt_reuse, &mut rotator);
        t1.join();
        t2.join();
    });
    let writer_result = writer.result;
    let (reserved_in_epoch_two, results) = (rotator.writer_reserved_in_epoch_two, rotator.results);

    check!(pinned_epoch(writer_slot).load(SeqCst) == 0);
    check!(pinned_epoch(rotator_slot).load(SeqCst) == 0);
    check!(writer_result == Outcome::Success);
    // GenMC already pruned this execution in the rotator; natively assume()
    // is a no-op, so skip the scenario explicitly.
    if !reserved_in_epoch_two {
        return 0;
    }
    check!(results[0] == Outcome::Success);
    check!(results[1] == Outcome::Success);
    check!(matches!(
        results[2],
        Outcome::Success | Outcome::ReuseTimeout
    ));
    let blocked = results[2] == Outcome::ReuseTimeout;
    check_dead_slot_reaped(cache);
    check!(!is_free(writer_slot));
    check!(!is_free(rotator_slot));
    check_joined_state(cache, !blocked);
    if blocked {
        check_ok!(rotator_lock.insert(HASH, FINAL.0, FINAL.1));
    }
    check_joined_state(cache, true);
    check_final_lookup(&mut rotator_lock);
    check_joined_state(cache, true);
    check!(pinned_epoch(writer_slot).load(SeqCst) == 0);
    check!(pinned_epoch(rotator_slot).load(SeqCst) == 0);

    witness!("REAPED_THEN_PIN_BLOCKED", blocked);
    0
}

fn insert(participant: &mut Participant, (key, value): (&[u8], &[u8])) -> Outcome {
    match participant.insert(HASH, key, value) {
        Ok(()) => Outcome::Success,
        Err(Error::RotationOwnerTimeout) => Outcome::OwnerTimeout,
        Err(Error::ArenaReuseTimeout) => Outcome::ReuseTimeout,
        Err(_) => Outcome::OtherError,
    }
}

/// G looks up as exactly VG, written into the caller's buffer.
fn check_final_lookup(participant: &mut Participant) {
    let mut output = output_buffer!(1);
    let output_start = output.bytes().as_ptr();
    let capacity = output.capacity();
    let (value_start, value_len) = match participant.lookup(HASH, FINAL.0, &mut output) {
        Ok(Some(value)) => (value.as_ptr(), value.len()),
        _ => (core::ptr::null(), 0),
    };
    check!(!value_start.is_null(), "G hits");
    check!(value_len == capacity);
    check!(value_start == output_start);
    check!(output.words()[0] == padded_words::<1>(FINAL.1)[0]);
}

/// Whether arena 2's bump is 32, i.e. the writer's reservation is in
/// epoch 2. A single load of the control word only: `arena_state` would
/// also load the occupancy counter, an extra racing read that only enlarges
/// the explored state space.
fn epoch_two_bump_is_32(cache: CacheRef) -> bool {
    arena_ctl(cache, 2, Relaxed).bump == 32
}

fn check_dead_slot_reaped(cache: CacheRef) {
    let slot = participant(cache, 0);
    check!(is_free(slot));
    check!(pinned_epoch(slot).load(Relaxed) == 0);
}

fn check_dead_slot_not_reaped(cache: CacheRef) {
    let slot = participant(cache, 0);
    check!(!is_free(slot));
    check!(pinned_epoch(slot).load(Relaxed) == DEAD_PIN);
}

/// The rotator's view right after its third insert timed out on reuse.
fn check_blocked_snapshot(cache: CacheRef) {
    check!(global_epoch(cache).load(Relaxed) == 4);
    check!(rotation_owner(cache, Relaxed).registration_id() == 0);
    let old = arena_state(cache, 2, Relaxed);
    check!(old.epoch == 2 && old.sealed && old.bump == 32);
    check!(matches!(old.occupancy, Some(0 | 1)));
    let current = arena_state(cache, 1, Relaxed);
    check!(current.epoch == 4 && current.sealed && current.bump == 32);
    check!(current.occupancy == Some(1));
}

/// Every arena holds one 32-byte record: epochs 3, 4 and either 2 (not
/// reused) or 5 (reused, the only unsealed incarnation).
fn check_joined_state(cache: CacheRef, reused: bool) {
    check!(global_epoch(cache).load(Relaxed) == if reused { 5 } else { 4 });
    check!(rotation_owner(cache, Relaxed).registration_id() == 0);
    for index in 0..3u64 {
        let arena = arena_state(cache, index, Relaxed);
        let epoch = match (index, reused) {
            (0 | 1, _) => index as u32 + 3,
            (_, true) => 5,
            (_, false) => 2,
        };
        check!(arena.epoch == epoch);
        check!(arena.sealed == !(reused && index == 2));
        check!(arena.bump == 32);
        check!(arena.occupancy == Some(1));
    }
}
