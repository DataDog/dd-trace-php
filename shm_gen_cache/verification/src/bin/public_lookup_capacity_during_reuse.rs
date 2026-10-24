//! A public lookup never reports `InsufficientCapacity` for a key that was
//! never inserted, even when arena reuse makes its key comparison appear to
//! match.
//!
//! Keys (all with the same supplied hash, i.e. a collision; `|` denotes
//! concatenation):
//!
//! * old key   `AAAAAAAA|BBBBBBBB`
//! * new key   `CCCCCCCC|DDDDDDDD`
//! * query key `AAAAAAAA|DDDDDDDD` (never inserted)
//!
//! Each record has a sixteen-byte key and a nine-byte value: 33 payload
//! bytes, a 48-byte record. The 48-byte record area holds exactly one
//! record, at [0, 48), so every insert after the first must rotate.
//! Occupancy cannot trigger extra rotations.
//!
//! Setup: main inserts the old key twice, reaching epoch 3. Then
//!
//! * T1 inserts the new key twice, reaching epoch 4 and then reusing
//!   physical arena 2 in epoch 5;
//! * T2 looks the query key up through the public API with an eight-byte
//!   output buffer, and records its outcome.
//!
//! The race under test: T2 can snapshot epoch 3, miss in arena 0, read
//! arena 2's epoch-2 index entry and header, and compare `AAAAAAAA` before
//! the reuse and `DDDDDDDD` after it. The equal record lengths pass the
//! structural checks and the mixed key passes the comparison. The nine
//! value bytes do not fit the buffer, so the probe skips the value copy.
//!
//! W3's release fence synchronizes, through the reused key word, with D6's
//! acquire fence. V2 must then reject the expired epoch-2 stamp, and the
//! public lookup must retry and miss. Without D6, V2 could still read epoch
//! 3 and wrongly return `InsufficientCapacity`. That error is never
//! legitimate here: neither inserted key equals the query key.
//!
//! Checked after both workers have joined:
//!
//! * T2's outcome is not `InsufficientCapacity` (a separate check, so that
//!   the D6 mutation counterexample points at it), and it is a miss;
//! * the new key is present with the full value;
//! * the query key still misses.
//!
//! Epoch 4 is published by T1 after spawning, so thread creation does not
//! itself force V2 to reject the old stamp and conceal a missing D6 fence.
//!
//! No assumptions and no witnesses.

#![no_std]
#![no_main]

use genmc_harness::{Lent, check, check_ok, padded_words, scope, words_eq};
use shm_gen_cache::{
    Cache, CacheStorage, Error, NoopGetPid, ParticipantLock, StaticParams, output_buffer,
    static_config,
};

static_config! {
    struct Cfg: NoopGetPid {
        participant_capacity: 2,
        bucket_count: 8,
        max_key_size: 16,
        max_value_size: 9,
        record_area_size: 48,
        max_occupancy: 8,
        reservation_chunk_size: 8, // exact record accounting
    }
}

type Participant = ParticipantLock<'static, Cfg>;

const HASH: u64 = 0;
const OLD_KEY: &[u8; 16] = b"AAAAAAAABBBBBBBB";
const NEW_KEY: &[u8; 16] = b"CCCCCCCCDDDDDDDD";
const QUERY_KEY: &[u8; 16] = b"AAAAAAAADDDDDDDD";
const VALUE: &[u8; 9] = b"123456789";
const VALUE_WORDS: [u64; 2] = padded_words(VALUE);

#[derive(Clone, Copy, PartialEq, Eq)]
enum Outcome {
    Unset,
    LookupError,
    InsufficientCapacity,
    Miss,
    Hit,
}

struct Writer<'a> {
    participant: Lent<'a, Participant>,
}

struct Reader<'a> {
    participant: Lent<'a, Participant>,
    observed: Outcome,
}

/// T1: two inserts of the new key (epoch 4, then reuse of arena 2 in 5).
fn insert_twice(writer: &mut Writer) {
    check!(writer.participant.insert(HASH, NEW_KEY, VALUE).is_ok());
    check!(writer.participant.insert(HASH, NEW_KEY, VALUE).is_ok());
}

/// T2: one public lookup of the never-inserted query key.
fn lookup(reader: &mut Reader) {
    reader.observed = observe_query(&mut reader.participant);
}

#[unsafe(no_mangle)]
extern "C" fn main() -> i32 {
    static STORAGE: <Cfg as StaticParams>::Storage = CacheStorage::new();
    // SAFETY: zeroed static storage, used only through this cache.
    let cache = check_ok!(unsafe { Cache::initialize(STORAGE.as_mut_ptr(), STORAGE.len(), Cfg) });
    let mut writer = check_ok!(cache.register_participant());
    let mut reader = check_ok!(cache.register_participant());
    check!(writer.insert(HASH, OLD_KEY, VALUE).is_ok());
    check!(writer.insert(HASH, OLD_KEY, VALUE).is_ok()); // epoch 3

    let mut t1 = Writer {
        participant: Lent::new(&mut writer),
    };
    let mut t2 = Reader {
        participant: Lent::new(&mut reader),
        observed: Outcome::Unset,
    };
    scope(|s| {
        let insert_thread = s.spawn(insert_twice, &mut t1);
        let lookup_thread = s.spawn(lookup, &mut t2);
        insert_thread.join();
        lookup_thread.join();
    });
    let observed = t2.observed;

    check!(observed != Outcome::InsufficientCapacity);
    check!(observed == Outcome::Miss);

    let mut output = output_buffer!(9);
    let found = check_ok!(check_ok!(reader.lookup(HASH, NEW_KEY, &mut output)));
    check!(found.len() == VALUE.len());
    check!(words_eq(output.words(), &VALUE_WORDS));
    check!(observe_query(&mut reader) == Outcome::Miss);
    0
}

/// Looks the query key up with an eight-byte buffer, one byte too small
/// for any stored value.
fn observe_query(participant: &mut Participant) -> Outcome {
    let mut output = output_buffer!(8);
    match participant.lookup(HASH, QUERY_KEY, &mut output) {
        Err(Error::InsufficientCapacity) => Outcome::InsufficientCapacity,
        Err(_) => Outcome::LookupError,
        Ok(None) => Outcome::Miss,
        Ok(Some(_)) => Outcome::Hit,
    }
}
