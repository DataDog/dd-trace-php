//! Old-arena promotion racing an explicit replacement, through the public
//! participant API.
//!
//! Setup inserts K -> V0 and calls the real rotation implementation once,
//! leaving V0 in the old arena (epoch 3, empty current arena). Then, with no
//! ordering constraints, T1 inserts K -> V1 and T2 looks K up. Rotation is
//! setup, not an operation raced by this test.
//!
//! Checked after the joins:
//!
//! * T1's insert succeeded;
//! * T2 saw exactly V0 or exactly V1 (V0 and V1 differ in their full first
//!   word and in their partial second word, so a torn copy is `Other`);
//! * the epoch is still 3 and the current arena's occupancy is 1;
//! * both participants are unpinned;
//! * a final lookup returns V1 (stale promotion never overwrites V1), and
//!   leaves the epoch, occupancy and pin unchanged.
//!
//! The interesting paths: an old hit copies V0 and attempts promotion; it
//! may publish before the explicit replacement, or find V1 already present
//! and leave it alone. The two stores can also compete for the empty index
//! slot and retry after a failed CAS. A young V1 hit needs no promotion.
//!
//! Lookup promotion keeps the output buffer's padded word representation
//! while preserving the ten-byte logical length, so it copies both whole
//! words instead of narrowing the second one to two bytes. Two 48-byte
//! records fit exactly in the 96-byte record area, and occupancy stays at
//! one, so neither insertion nor promotion rotates again. `NoopGetPid`
//! excludes OS identity and slot reaping. Internals are used only to run
//! the setup rotation and to read the epoch and current occupancy.
//!
//! The safety run imposes no scheduling assumptions. Witnesses: T2 can see
//! V0 and can see V1. V0 implies a completed old-hit promotion attempt, but
//! does not tell publication from declining to replace V1; both outcomes
//! also have serial witnesses, so neither certifies a publication order, a
//! failed CAS or operation overlap (that would need execution-graph
//! inspection; no hooks are needed for the safety property).

#![no_std]
#![no_main]

use core::sync::atomic::Ordering::{Relaxed, SeqCst};

use genmc_harness::{Lent, check, check_ok, padded_words, scope, witness};
use shm_gen_cache::test_access::{
    ARENAS, RotationPolicy, arena_state, global_epoch, pinned_epoch, rotate,
};
use shm_gen_cache::{
    Cache, CacheStorage, NoopGetPid, ParticipantLock, StaticParams, output_buffer, static_config,
};

static_config! {
    struct Cfg: NoopGetPid {
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

const HASH: u64 = 42;
const KEY: &[u8; 10] = b"0123456789";
const V0: &[u8; 10] = b"ABCDEFGHIJ";
const V1: &[u8; 10] = b"abcdefghij";
const V0_WORDS: [u64; 2] = padded_words(V0);
const V1_WORDS: [u64; 2] = padded_words(V1);

#[derive(Clone, Copy, PartialEq, Eq)]
enum Outcome {
    Unset,
    LookupError,
    Miss,
    V0,
    V1,
    Other,
}

struct Writer<'a> {
    participant: Lent<'a, Participant>,
    succeeded: bool,
}

struct Reader<'a> {
    participant: Lent<'a, Participant>,
    observed: Outcome,
}

fn insert(writer: &mut Writer) {
    writer.succeeded = writer.participant.insert(HASH, KEY, V1).is_ok();
}

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
    check_ok!(rotate(
        cache,
        p1.slot_index(),
        epoch(cache),
        RotationPolicy::WaitForOwner
    ));
    check!(epoch(cache) == 3);
    check!(current_occupancy(cache) == 0);

    let mut writer = Writer {
        participant: Lent::new(&mut p1),
        succeeded: false,
    };
    let mut reader = Reader {
        participant: Lent::new(&mut p2),
        observed: Outcome::Unset,
    };
    scope(|s| {
        let t1 = s.spawn(insert, &mut writer);
        let t2 = s.spawn(lookup, &mut reader);
        t1.join();
        t2.join();
    });
    let (inserted, observed) = (writer.succeeded, reader.observed);

    check!(inserted);
    check!(matches!(observed, Outcome::V0 | Outcome::V1));
    check!(epoch(cache) == 3);
    check!(current_occupancy(cache) == 1);
    check!(pinned_epoch(p1.slot()).load(SeqCst) == 0);
    check!(pinned_epoch(p2.slot()).load(SeqCst) == 0);
    check!(observe(&mut p2) == Outcome::V1);
    check!(epoch(cache) == 3);
    check!(current_occupancy(cache) == 1);
    check!(pinned_epoch(p2.slot()).load(SeqCst) == 0);

    witness!("V0", observed == Outcome::V0);
    witness!("V1", observed == Outcome::V1);
    0
}

fn epoch(cache: Cache<'_, Cfg>) -> u64 {
    global_epoch(cache).load(Relaxed)
}

fn current_occupancy(cache: Cache<'_, Cfg>) -> u32 {
    let state = arena_state(cache, epoch(cache) % ARENAS, Relaxed);
    check_ok!(state.occupancy)
}

fn observe(participant: &mut Participant) -> Outcome {
    let mut output = output_buffer!(10);
    let len = match participant.lookup(HASH, KEY, &mut output) {
        Err(_) => return Outcome::LookupError,
        Ok(None) => return Outcome::Miss,
        Ok(Some(value)) => value.len(),
    };
    // A hit is always a prefix of `output` (by type), so only the length
    // needs checking.
    if len != output.capacity() {
        Outcome::Other
    } else if genmc_harness::words_eq(output.words(), &V0_WORDS) {
        Outcome::V0
    } else if genmc_harness::words_eq(output.words(), &V1_WORDS) {
        Outcome::V1
    } else {
        Outcome::Other
    }
}
