//! One lookup racing two replacements of the same key, through the public
//! participant API.
//!
//! The cache starts empty. T1 inserts K -> V1, T2 inserts K -> V2 and T3
//! looks K up. T3 only records what it saw; main checks it after the joins,
//! once both inserts are known to have completed:
//!
//! * both inserts succeeded;
//! * T3 saw a miss, exactly V1 or exactly V2 (never a torn mixture: V1 and
//!   V2 differ in their full first word and in their partial second word);
//! * K finally holds V1 or V2.
//!
//! Witnesses: each of T3's three outcomes is reachable.
//!
//! Assumption: before its lookup, T3 acquire-loads both writers'
//! participant pins and assumes both are non-zero. The loads read the
//! release stores made at W2 in `reserve()`, so both inserts have entered
//! the production algorithm before the lookup starts; this excludes the
//! trivial histories in which T3 finishes before either writer starts. The
//! native smoke run performs the loads without constraining their values.
//!
//! K, V1 and V2 are ten bytes. Two 48-byte records fit in the 128-byte
//! record area and occupancy stays below the rotation threshold, so
//! rotation, eviction and OS identity/slot reaping are out of scope.

#![no_std]
#![no_main]

use core::sync::atomic::Ordering::Acquire;

use genmc_harness::{Lent, assume, check, check_ok, padded_words, scope, witness};
use shm_gen_cache::test_access::pinned_epoch;
use shm_gen_cache::{
    Cache, CacheStorage, NoopGetPid, ParticipantLock, ParticipantSlot, StaticParams, output_buffer,
    static_config,
};

static_config! {
    struct Cfg: NoopGetPid {
        participant_capacity: 3,
        bucket_count: 8,
        max_key_size: 10,
        max_value_size: 10,
        record_area_size: 128,
        max_occupancy: 8,
        reservation_chunk_size: 8, // exact record accounting
    }
}

type Participant = ParticipantLock<'static, Cfg>;

const HASH: u64 = 42;
const KEY: &[u8; 10] = b"0123456789";
const V1: &[u8; 10] = b"ABCDEFGHIJ";
const V2: &[u8; 10] = b"abcdefghij";
const V1_WORDS: [u64; 2] = padded_words(V1);
const V2_WORDS: [u64; 2] = padded_words(V2);

#[derive(Clone, Copy, PartialEq, Eq)]
enum Outcome {
    Unset,
    LookupError,
    Miss,
    V1,
    V2,
    Other,
}

struct Writer<'a> {
    participant: Lent<'a, Participant>,
    value: &'static [u8],
    succeeded: bool,
}

struct Reader<'a> {
    participant: Lent<'a, Participant>,
    writer_slots: [&'static ParticipantSlot; 2],
    observed: Outcome,
}

fn insert(writer: &mut Writer) {
    writer.succeeded = writer.participant.insert(HASH, KEY, writer.value).is_ok();
}

fn lookup(reader: &mut Reader) {
    let writers_started = reader
        .writer_slots
        .iter()
        .all(|slot| pinned_epoch(slot).load(Acquire) != 0);
    assume(writers_started);
    reader.observed = observe(&mut reader.participant);
}

#[unsafe(no_mangle)]
extern "C" fn main() -> i32 {
    static STORAGE: <Cfg as StaticParams>::Storage = CacheStorage::new();
    // SAFETY: zeroed static storage, used only through this cache.
    let cache = check_ok!(unsafe { Cache::initialize(STORAGE.as_mut_ptr(), STORAGE.len(), Cfg) });
    let mut p1 = check_ok!(cache.register_participant());
    let mut p2 = check_ok!(cache.register_participant());
    let mut p3 = check_ok!(cache.register_participant());
    check!(observe(&mut p3) == Outcome::Miss);

    let writer_slots = [p1.slot(), p2.slot()];
    let mut w1 = Writer {
        participant: Lent::new(&mut p1),
        value: V1,
        succeeded: false,
    };
    let mut w2 = Writer {
        participant: Lent::new(&mut p2),
        value: V2,
        succeeded: false,
    };
    let mut r = Reader {
        participant: Lent::new(&mut p3),
        writer_slots,
        observed: Outcome::Unset,
    };
    scope(|s| {
        let t1 = s.spawn(insert, &mut w1);
        let t2 = s.spawn(insert, &mut w2);
        let t3 = s.spawn(lookup, &mut r);
        t1.join();
        t2.join();
        t3.join();
    });
    let (w1_succeeded, w2_succeeded, observed) = (w1.succeeded, w2.succeeded, r.observed);

    check!(w1_succeeded);
    check!(w2_succeeded);
    check!(matches!(
        observed,
        Outcome::Miss | Outcome::V1 | Outcome::V2
    ));
    let last = observe(&mut p3);
    check!(matches!(last, Outcome::V1 | Outcome::V2));

    witness!("MISS", observed == Outcome::Miss);
    witness!("V1", observed == Outcome::V1);
    witness!("V2", observed == Outcome::V2);
    0
}

fn observe(participant: &mut Participant) -> Outcome {
    let mut output = output_buffer!(10);
    let len = match participant.lookup(HASH, KEY, &mut output) {
        Err(_) => return Outcome::LookupError,
        Ok(None) => return Outcome::Miss,
        Ok(Some(value)) => value.len(),
    };
    if len != output.capacity() {
        Outcome::Other
    } else if genmc_harness::words_eq(output.words(), &V1_WORDS) {
        Outcome::V1
    } else if genmc_harness::words_eq(output.words(), &V2_WORDS) {
        Outcome::V2
    } else {
        Outcome::Other
    }
}
