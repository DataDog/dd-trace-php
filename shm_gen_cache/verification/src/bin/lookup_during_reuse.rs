//! A public lookup that can stay inside an epoch-2 probe while a writer
//! advances through epoch 5 and reuses the probed arena.
//!
//! Records: K -> V0 occupies 48 bytes; F -> VF (an 18-byte value) occupies
//! 56. Setup inserts K at offset 0 and F at offset 48, exactly filling
//! epoch 2's 104-byte record area. The 48-byte reservation chunk is no
//! larger than either record, so every reservation claims exactly its
//! record and none uses a chunk tail. K and F have distinct home buckets.
//!
//! Threads: T1 inserts F -> VF three times through the public API; T2 looks
//! K up once. A lookup that snapshots epoch 2 may overlap every rotation and
//! the physical overwrite of K's original record. Checked after the joins:
//!
//! * every insert succeeded or failed with `RotationOwnerTimeout` (no
//!   harness retries);
//! * the lookup returned exactly V0, a miss (K aged out), or
//!   `RotationOwnerTimeout` (while promoting an old hit); never VF, a torn
//!   value, or another error. A lookup may write its output buffer before
//!   deciding on a miss or an error; those bytes are not a returned value,
//!   so the buffer is examined only after a hit;
//! * the final state: epoch in 2..=5, no rotation owner, the current arena
//!   unsealed, stamped with that epoch, holding one or two records (bump 56
//!   or 104); both participants unpinned.
//!
//! Capacity bound: each epoch fits one 56-byte writer record plus one
//! possible 48-byte promotion. So three successful writer inserts force
//! epochs 3, 4 and 5, and the promotion cannot force epoch 6. A promotion
//! can pin only epoch 3 or later, so no transition through epoch 5 can wait
//! on that pin (pinning preventing rotation is outside this test's scope).
//! Hence only `RotationOwnerTimeout` is permitted; `ArenaReuseTimeout`
//! would contradict the bound. Every timeout occurs before allocation:
//! occupancy is below its limit and K/F use distinct buckets.
//!
//! Witnesses: `V0`, `MISS` and `TIMEOUT` reach the three public lookup
//! outcomes. `REUSE_OVERWRITE` requires all three inserts to succeed and the
//! lookup to miss; the miss excludes a successful promotion, so capacity
//! accounting proves the third insert reused arena 2 at offset 0,
//! overwriting K's original record (checked: final epoch 5, bump 56,
//! occupancy 1). The witnesses do not prove that the reader copied
//! concurrently with that overwrite: the public lookup hides internal probe
//! retries. No hooks, assumptions, barriers, direct probe or rotate calls,
//! or new production variants are used.
//!
//! This is a long exhaustive test: its safety variant explores 1,475,399
//! complete and 18 blocked executions (about 200 s with -nthreads=16 on a
//! 32-vCPU amd64 VM).

#![no_std]
#![no_main]

use core::sync::atomic::Ordering::Relaxed;

use genmc_harness::{Lent, check, check_ok, padded_words, scope, witness, words_eq};
use shm_gen_cache::test_access::{
    ARENAS, arena_state, distinct_bucket_hashes, global_epoch, pinned_epoch, rotation_owner,
};
use shm_gen_cache::{
    Cache, CacheStorage, Error, NoopGetPid, ParticipantLock, StaticParams, output_buffer,
    static_config,
};

const BUCKET_COUNT: u32 = 8;

static_config! {
    struct Cfg: NoopGetPid {
        participant_capacity: 2,
        bucket_count: BUCKET_COUNT,
        max_key_size: 10,
        max_value_size: 18,
        record_area_size: 104,
        max_occupancy: 8,
        reservation_chunk_size: 48,
    }
}

type Participant = ParticipantLock<'static, Cfg>;

const KEY: &[u8; 10] = b"01234567KK";
const V0: &[u8; 10] = b"ABCDEFGHIJ";
const V0_WORDS: [u64; 2] = padded_words(V0);
const FILLER_KEY: &[u8; 10] = b"01234567FF";
const FILLER_VALUE: &[u8; 18] = b"abcdefghijklmnopqr";

/// K's hash and F's, with distinct home buckets.
const HASHES: [u64; 2] = distinct_bucket_hashes(BUCKET_COUNT);
const HASH: u64 = HASHES[0];
const FILLER_HASH: u64 = HASHES[1];

#[derive(Clone, Copy, PartialEq, Eq)]
enum InsertOutcome {
    Unset,
    Success,
    OwnerTimeout,
    Other,
}

#[derive(Clone, Copy, PartialEq, Eq)]
enum LookupOutcome {
    Unset,
    Miss,
    V0,
    OwnerTimeout,
    Other,
}

struct Writer<'a> {
    participant: Lent<'a, Participant>,
    outcomes: [InsertOutcome; 3],
}

struct Reader<'a> {
    participant: Lent<'a, Participant>,
    observed: LookupOutcome,
}

/// T1: three inserts of F -> VF.
fn insert_filler(writer: &mut Writer) {
    for outcome in &mut writer.outcomes {
        *outcome = match writer
            .participant
            .insert(FILLER_HASH, FILLER_KEY, FILLER_VALUE)
        {
            Ok(()) => InsertOutcome::Success,
            Err(Error::RotationOwnerTimeout) => InsertOutcome::OwnerTimeout,
            Err(_) => InsertOutcome::Other,
        };
    }
}

/// T2: one lookup of K.
fn lookup(reader: &mut Reader) {
    reader.observed = observe(&mut reader.participant);
}

#[unsafe(no_mangle)]
extern "C" fn main() -> i32 {
    static STORAGE: <Cfg as StaticParams>::Storage = CacheStorage::new();
    // SAFETY: zeroed static storage, used only through this cache.
    let cache = check_ok!(unsafe { Cache::initialize(STORAGE.as_mut_ptr(), STORAGE.len(), Cfg) });
    let mut p1 = check_ok!(cache.register_participant());
    let mut p2 = check_ok!(cache.register_participant());
    check_ok!(p1.insert(HASH, KEY, V0));
    check_ok!(p1.insert(FILLER_HASH, FILLER_KEY, FILLER_VALUE));
    check_setup(cache);

    let (slot1, slot2) = (p1.slot(), p2.slot());
    let mut writer = Writer {
        participant: Lent::new(&mut p1),
        outcomes: [InsertOutcome::Unset; 3],
    };
    let mut reader = Reader {
        participant: Lent::new(&mut p2),
        observed: LookupOutcome::Unset,
    };
    scope(|s| {
        let t1 = s.spawn(insert_filler, &mut writer);
        let t2 = s.spawn(lookup, &mut reader);
        t1.join();
        t2.join();
    });
    let (outcomes, observed) = (writer.outcomes, reader.observed);

    let mut all_inserts_succeeded = true;
    for outcome in outcomes {
        check!(matches!(
            outcome,
            InsertOutcome::Success | InsertOutcome::OwnerTimeout
        ));
        all_inserts_succeeded &= outcome == InsertOutcome::Success;
    }
    check!(matches!(
        observed,
        LookupOutcome::V0 | LookupOutcome::Miss | LookupOutcome::OwnerTimeout
    ));
    let reused_without_promotion = all_inserts_succeeded && observed == LookupOutcome::Miss;
    check_finished(cache, reused_without_promotion);
    check!(pinned_epoch(slot1).load(Relaxed) == 0);
    check!(pinned_epoch(slot2).load(Relaxed) == 0);

    witness!("V0", observed == LookupOutcome::V0);
    witness!("MISS", observed == LookupOutcome::Miss);
    witness!("REUSE_OVERWRITE", reused_without_promotion);
    witness!("TIMEOUT", observed == LookupOutcome::OwnerTimeout);
    0
}

/// Epoch 2's arena is exactly full with K and F, and nobody rotates.
fn check_setup(cache: Cache<'static, Cfg>) {
    check!(global_epoch(cache).load(Relaxed) == 2);
    check!(rotation_owner(cache, Relaxed).registration_id() == 0);
    let arena = arena_state(cache, 2, Relaxed);
    check!(arena.epoch == 2 && !arena.sealed && arena.bump == 104);
    check!(check_ok!(arena.occupancy) == 2);
}

/// The final epoch's arena holds the last writer record and possibly one
/// promotion; a reuse without promotion pins down epoch 5 with one record.
fn check_finished(cache: Cache<'static, Cfg>, reused_without_promotion: bool) {
    let epoch = global_epoch(cache).load(Relaxed);
    check!((2..=5).contains(&epoch));
    check!(rotation_owner(cache, Relaxed).registration_id() == 0);
    let arena = arena_state(cache, epoch % ARENAS, Relaxed);
    check!(u64::from(arena.epoch) == epoch && !arena.sealed);
    check!(arena.bump == 56 || arena.bump == 104);
    let occupancy = check_ok!(arena.occupancy);
    check!((1..=2).contains(&occupancy));
    if reused_without_promotion {
        check!(epoch == 5 && arena.bump == 56 && occupancy == 1);
    }
}

/// Looks K up; the output buffer is inspected only after a hit.
fn observe(participant: &mut Participant) -> LookupOutcome {
    let mut output = output_buffer!(10);
    let len = match participant.lookup(HASH, KEY, &mut output) {
        Err(Error::RotationOwnerTimeout) => return LookupOutcome::OwnerTimeout,
        Err(_) => return LookupOutcome::Other,
        Ok(None) => return LookupOutcome::Miss,
        Ok(Some(value)) => value.len(),
    };
    if len == output.capacity() && words_eq(output.words(), &V0_WORDS) {
        LookupOutcome::V0
    } else {
        LookupOutcome::Other
    }
}
