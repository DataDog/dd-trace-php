//! A write into an already-owned reservation chunk must still block reuse
//! of its arena.
//!
//! A writer consuming a chunk it already owns performs no reservation RMW,
//! so a seal can no longer acquire that writer's new pin from the control
//! word: W3 must protect the write. The writer's W3 precedes, in the
//! seq_cst order, R2 before the reusing rotator's pin scan (the rotator's
//! own W3 in `reserve()` currently serves as the same fence, so R2 is
//! redundant here). Two symmetric public inserters contend for rotation
//! while the writer finishes its record in the original arena.
//!
//! # Setup and threads
//!
//! The configuration has 64-byte record areas and 64-byte reservation
//! chunks. Main publicly inserts SEED -> SEED_VALUE in epoch 2 as W: the
//! 32-byte record takes the first half of W's 64-byte chunk, which fills
//! arena 2; W keeps `[32, 64)`. Then:
//!
//! ```text
//! W (writer):   WRITER_KEY -> WRITER_VALUE   consumes [32, 64), no bump change
//! main:         FINAL_KEY  -> FINAL_VALUE    as A, rotating to epoch 3
//!               (main inserts only after spawning W, so W may still be
//!               pinned to epoch 2; only then are A and B spawned)
//! A (rotator):  FINAL_KEY  -> FINAL_VALUE
//! B (rotator):  FINAL_KEY  -> FINAL_VALUE    (symmetric to A in the safety build)
//! ```
//!
//! The 40-byte FINAL_VALUE makes 64-byte records, so each successful
//! insert needs another epoch. The two competing rotations can reach epoch
//! 5, reusing arena 2. Each worker owns its registration and result;
//! swapping A and B leaves every check unchanged. Setup's first rotation
//! also runs through `insert()`: there is no synthesized pin and no direct
//! rotation call. WRITER_KEY and FINAL_KEY share a hash, so a late
//! publication of WRITER_KEY can replace FINAL_KEY's index entry.
//!
//! # Hooks and the native run
//!
//! [`WriterHooks`], this test's liveness backend (otherwise `NoopGetPid`),
//! acts only on W's slot:
//!
//! * `reservation_epoch_hook` (after W1) assumes W selected epoch 2;
//! * `reservation_retry_hook` records that W abandoned an attempt and
//!   prunes the execution (`assume(false)`).
//!
//! A post-join check verifies that W consumed its epoch-2 chunk through
//! offset 64. Together they restrict W to its first, existing-chunk
//! attempt. Selecting the epoch early avoids exploring later-epoch writes
//! only to discard them at the join. The hooks add no synchronisation
//! between workers (plain, non-atomic statics: W's slot address is written
//! before any spawn, the retry flag only by W and read by main after the
//! join). In particular, rotators never observe W's record or cursor
//! before their scans: an acquire edge from such an observation could mask
//! a missing W3. A native run (where `assume` is a no-op) records a retry
//! and skips the conditional checks if W took a different path. Other
//! tests cover reservation retries; this one isolates the existing-chunk
//! path.
//!
//! # Properties
//!
//! W succeeds. At least one rotator succeeds, and a rotator may only fail
//! with a rotation-ownership or arena-reuse timeout. The global epoch is
//! exactly 3 plus the rotator successes. Every registration ends unpinned
//! and rotation ownership is released. If arena 2 was not reused, its
//! original incarnation and both records survive. Otherwise a public
//! lookup at epoch 5 returns exactly FINAL_VALUE: a late W write overlaps
//! its payload, and a late W publication could replace its index entry.
//! The state checks before and after that lookup forbid promotion from
//! hiding a damaged current copy by copying FINAL_KEY from the previous
//! arena. No later write can hide corruption.
//!
//! # Witnesses
//!
//! Both run after all safety and readback checks, and neither outcome is
//! forced by direct rotation, synthesized pins or cross-worker
//! observations.
//!
//! * `CHUNK_PIN_BLOCKS_REUSE`: a reuse timeout with exactly one rotator
//!   success besides main's insertion; only W's epoch-2 pin can block that
//!   transition.
//! * `CHUNK_WRITE_THEN_REUSE`: arena 2 is reused after W's chunk write.

#![no_std]
#![no_main]

use core::sync::atomic::Ordering::{Relaxed, SeqCst};

use genmc_harness::{Lent, assume, check, check_ok, scope, witness};
use shm_gen_cache::test_access::{
    ARENAS, FIRST_EPOCH, arena_state, chunk, distinct_bucket_hashes, global_epoch, pinned_epoch,
    rotation_owner,
};
use shm_gen_cache::{
    Cache, CacheStorage, Error, GetPid, NoopGetPid, ParticipantLock, ParticipantSlot, StaticParams,
    output_buffer, static_config,
};

static_config! {
    struct Cfg: WriterHooks {
        participant_capacity: 3,
        bucket_count: 8,
        max_key_size: 1,
        max_value_size: 40,
        record_area_size: 64,
        max_occupancy: 8,
        reservation_chunk_size: 64,
    }
}

type Participant = ParticipantLock<'static, Cfg>;

/// SEED's home bucket differs from the shared one of the other two keys.
const HASHES: [u64; 2] = distinct_bucket_hashes(8);
const SEED_HASH: u64 = HASHES[0];
const SHARED_HASH: u64 = HASHES[1];
const SEED: &[u8] = &[1];
const WRITER_KEY: &[u8] = &[2];
const FINAL_KEY: &[u8] = &[3];
const SEED_VALUE: &[u8] = &[11];
const WRITER_VALUE: &[u8] = &[22];
const FINAL_VALUE: &[u8; 40] = b"UUUUUUUUUUUUUUUUUUUUUUUUUUUUUUUUUUUUUUUU";
const FINAL_WORD: u64 = 0x5555_5555_5555_5555;

/// The epoch reached when arena 2 is reused.
const REUSE_EPOCH: u64 = FIRST_EPOCH + ARENAS;

#[derive(Clone, Copy, PartialEq, Eq)]
enum Outcome {
    Unset,
    Success,
    OwnerTimeout,
    ReuseTimeout,
    Other,
}

struct Writer<'a> {
    participant: Lent<'a, Participant>,
    inserted: bool,
}

struct Rotator<'a> {
    participant: Lent<'a, Participant>,
    result: Outcome,
}

fn write_chunk(writer: &mut Writer) {
    writer.inserted = writer
        .participant
        .insert(SHARED_HASH, WRITER_KEY, WRITER_VALUE)
        .is_ok();
}

fn rotate_and_insert(rotator: &mut Rotator) {
    rotator.result = match rotator
        .participant
        .insert(SHARED_HASH, FINAL_KEY, FINAL_VALUE)
    {
        Ok(()) => Outcome::Success,
        Err(Error::RotationOwnerTimeout) => Outcome::OwnerTimeout,
        Err(Error::ArenaReuseTimeout) => Outcome::ReuseTimeout,
        Err(_) => Outcome::Other,
    };
}

#[unsafe(no_mangle)]
extern "C" fn main() -> i32 {
    static STORAGE: <Cfg as StaticParams>::Storage = CacheStorage::new();
    // SAFETY: zeroed static storage, used only through this cache.
    let cache = check_ok!(unsafe { Cache::initialize(STORAGE.as_mut_ptr(), STORAGE.len(), Cfg) });
    let mut writer_lock = check_ok!(cache.register_participant());
    let mut first_lock = check_ok!(cache.register_participant());
    let mut second_lock = check_ok!(cache.register_participant());
    check_ok!(writer_lock.insert(SEED_HASH, SEED, SEED_VALUE));
    let writer_slot = writer_lock.slot();
    let (first_slot, second_slot) = (first_lock.slot(), second_lock.slot());
    WriterHooks::watch(writer_slot);

    let mut writer = Writer {
        participant: Lent::new(&mut writer_lock),
        inserted: false,
    };
    let mut rotators = [
        Rotator {
            participant: Lent::new(&mut first_lock),
            result: Outcome::Unset,
        },
        Rotator {
            participant: Lent::new(&mut second_lock),
            result: Outcome::Unset,
        },
    ];
    scope(|s| {
        let writer_thread = s.spawn(write_chunk, &mut writer);
        // Through A's registration, which main still holds: A has not been
        // spawned yet.
        let [a, b] = &mut rotators;
        check_ok!(a.participant.insert(SHARED_HASH, FINAL_KEY, FINAL_VALUE));
        let first_thread = s.spawn(rotate_and_insert, a);
        let second_thread = s.spawn_symmetric(rotate_and_insert, b, &first_thread);
        writer_thread.join();
        first_thread.join();
        second_thread.join();
    });
    let inserted = writer.inserted;
    let results = [rotators[0].result, rotators[1].result];

    for slot in [writer_slot, first_slot, second_slot] {
        check!(pinned_epoch(slot).load(SeqCst) == 0);
    }
    let (chunk_epoch, chunk_cursor, _) = chunk(writer_slot);
    let took_existing_chunk = !WriterHooks::writer_retried()
        && chunk_epoch.load(SeqCst) == FIRST_EPOCH
        && chunk_cursor.load(SeqCst) == 64;
    if cfg!(sgc_genmc) {
        check!(took_existing_chunk, "guaranteed by the hooks");
    } else if !took_existing_chunk {
        return 0;
    }
    check!(inserted);

    let mut successful = 1; // main's insertion at epoch 3
    let mut blocked = false;
    for result in results {
        check!(matches!(
            result,
            Outcome::Success | Outcome::OwnerTimeout | Outcome::ReuseTimeout
        ));
        successful += (result == Outcome::Success) as u64;
        blocked |= result == Outcome::ReuseTimeout;
    }
    check!(successful == 2 || successful == 3);
    check!(!blocked || successful == 2);
    expect_state(cache, successful);
    expect_final_value(&mut first_lock);
    expect_state(cache, successful);

    witness!("CHUNK_PIN_BLOCKS_REUSE", blocked && successful == 2);
    witness!("CHUNK_WRITE_THEN_REUSE", successful == 3);
    0
}

/// Checks the arenas after `successful` epoch advances from epoch 2.
fn expect_state(cache: Cache<'_, Cfg>, successful: u64) {
    let epoch = FIRST_EPOCH + successful;
    let reused = epoch == REUSE_EPOCH;
    check!(global_epoch(cache).load(SeqCst) == epoch);
    check!(rotation_owner(cache, SeqCst).registration_id() == 0);

    // Relaxed loads suffice, even for the occupancy counters: every worker
    // has been joined, so each load reads the final value anyway.
    let old = arena_state(cache, FIRST_EPOCH % ARENAS, Relaxed);
    check!(old.epoch as u64 == if reused { REUSE_EPOCH } else { FIRST_EPOCH });
    // Only W's preclaimed chunk, or the final rotator's epoch-5 claim, can
    // bump arena 2. W's concurrent write performs no reservation RMW.
    check!(old.bump == 64);
    check!(old.occupancy == Some(if reused { 1 } else { 2 }));

    let current = arena_state(cache, epoch % ARENAS, Relaxed);
    check!(current.epoch as u64 == epoch);
    // A competing fetch-add can fail once after the epoch-4 claim. Its next
    // precheck sees its own add, so it cannot fail twice in that incarnation.
    check!(current.bump == 64 || current.bump == 128);
    check!(current.occupancy == Some(1));
}

/// A public lookup of FINAL_KEY returns exactly FINAL_VALUE.
fn expect_final_value(participant: &mut Participant) {
    let mut output = output_buffer!(40);
    let len = check_ok!(check_ok!(participant.lookup(
        SHARED_HASH,
        FINAL_KEY,
        &mut output
    )))
    .len();
    check!(len == output.capacity());
    for &word in output.words() {
        check!(word == FINAL_WORD);
    }
}

/// `NoopGetPid` plus the reservation hooks for W's slot (see the module
/// documentation).
#[derive(Clone, Copy, Debug, Default)]
struct WriterHooks;

/// W's slot; written by main before any spawn, then only read.
static mut WRITER_SLOT: *const ParticipantSlot = core::ptr::null();
/// Written only by W; read by main after joining W.
static mut WRITER_RETRIED: bool = false;

impl WriterHooks {
    fn watch(slot: &'static ParticipantSlot) {
        // SAFETY: no other thread exists yet.
        unsafe { WRITER_SLOT = slot };
    }

    fn writer_retried() -> bool {
        // SAFETY: W, its only writer, has been joined.
        unsafe { WRITER_RETRIED }
    }

    fn is_writer(slot: &ParticipantSlot) -> bool {
        // SAFETY: written before the workers were spawned.
        core::ptr::eq(slot, unsafe { WRITER_SLOT })
    }
}

impl GetPid for WriterHooks {
    fn get_pid() -> u32 {
        NoopGetPid::get_pid()
    }
    fn get_start_time() -> u64 {
        NoopGetPid::get_start_time()
    }
    fn is_live(pid: u32) -> Result<bool, Error> {
        NoopGetPid::is_live(pid)
    }
    fn is_live_since(pid: u32, start_time: u64) -> Result<bool, Error> {
        NoopGetPid::is_live_since(pid, start_time)
    }

    fn reservation_epoch_hook(slot: &ParticipantSlot, epoch: u64) {
        if Self::is_writer(slot) {
            assume(epoch == FIRST_EPOCH);
        }
    }

    fn reservation_retry_hook(slot: &ParticipantSlot) {
        if Self::is_writer(slot) {
            // SAFETY: only W reaches this write.
            unsafe { WRITER_RETRIED = true };
            assume(false);
        }
    }
}
