//! Concurrent replacement and a full-hash collision, through the public
//! participant API.
//!
//! Setup: an empty cache, three participants registered by main, and both
//! keys missing. Then three unordered inserts:
//!
//! ```text
//! T1: FIRST_KEY  -> V1
//! T2: FIRST_KEY  -> V2      (symmetric to T1 in the safety build)
//! T3: SECOND_KEY -> OTHER
//! ```
//!
//! Every insert passes the same 64-bit hash although the keys differ. The
//! keys are ten bytes and share their first eight, so telling them apart
//! requires comparing the partial second word. Values are eight bytes. The
//! three records fit in the 128-byte record area and occupancy stays below
//! the rotation threshold: insertion, replacement and collision handling
//! are isolated from rotation and eviction. `NoopGetPid` keeps OS identity
//! and slot reaping out of scope.
//!
//! Checked after the joins: every insert succeeded, FIRST_KEY holds exactly
//! V1 or V2, and SECOND_KEY holds OTHER.
//!
//! Witnesses: V1 wins in some execution, and so does V2. T1 and T2 are
//! symmetric only in the safety build (exchanging their participants and
//! values preserves every check); the witnesses distinguish the values.

#![no_std]
#![no_main]

use genmc_harness::{Lent, check, check_ok, padded_words, scope, witness};
use shm_gen_cache::{
    Cache, CacheStorage, NoopGetPid, ParticipantLock, StaticParams, output_buffer, static_config,
};

static_config! {
    struct Cfg: NoopGetPid {
        participant_capacity: 3,
        bucket_count: 8,
        max_key_size: 10,
        max_value_size: 8,
        record_area_size: 128,
        max_occupancy: 8,
        reservation_chunk_size: 8, // exact record accounting
    }
}

type Participant = ParticipantLock<'static, Cfg>;

/// The same complete hash for both keys, not merely the same bucket.
const HASH: u64 = 42;
const FIRST_KEY: &[u8; 10] = b"01234567A1";
const SECOND_KEY: &[u8; 10] = b"01234567B2";
const V1: &[u8; 8] = b"VALUE-V1";
const V2: &[u8; 8] = b"VALUE-V2";
const OTHER: &[u8; 8] = b"OTHER-V3";

struct Insert<'a> {
    participant: Lent<'a, Participant>,
    key: &'static [u8],
    value: &'static [u8],
}

fn insert(op: &mut Insert) {
    check_ok!(op.participant.insert(HASH, op.key, op.value));
}

#[unsafe(no_mangle)]
extern "C" fn main() -> i32 {
    static STORAGE: <Cfg as StaticParams>::Storage = CacheStorage::new();
    // SAFETY: zeroed static storage, used only through this cache.
    let cache = check_ok!(unsafe { Cache::initialize(STORAGE.as_mut_ptr(), STORAGE.len(), Cfg) });
    let mut p1 = check_ok!(cache.register_participant());
    let mut p2 = check_ok!(cache.register_participant());
    let mut p3 = check_ok!(cache.register_participant());
    check!(lookup(&mut p1, FIRST_KEY).is_none());
    check!(lookup(&mut p1, SECOND_KEY).is_none());

    let mut t1 = Insert {
        participant: Lent::new(&mut p1),
        key: FIRST_KEY,
        value: V1,
    };
    let mut t2 = Insert {
        participant: Lent::new(&mut p2),
        key: FIRST_KEY,
        value: V2,
    };
    let mut t3 = Insert {
        participant: Lent::new(&mut p3),
        key: SECOND_KEY,
        value: OTHER,
    };
    scope(|s| {
        let first = s.spawn(insert, &mut t1);
        let second = s.spawn_symmetric(insert, &mut t2, &first);
        let third = s.spawn(insert, &mut t3);
        first.join();
        second.join();
        third.join();
    });

    let winner = check_ok!(lookup(&mut p1, FIRST_KEY));
    check!(winner == word(V1) || winner == word(V2));
    check!(lookup(&mut p1, SECOND_KEY) == Some(word(OTHER)));

    witness!("V1", winner == word(V1));
    witness!("V2", winner == word(V2));
    0
}

/// The value of `key` (one full word) or `None` on a miss.
fn lookup(participant: &mut Participant, key: &[u8]) -> Option<u64> {
    let mut output = output_buffer!(8);
    let hit = check_ok!(participant.lookup(HASH, key, &mut output))?;
    check!(hit.len() == 8);
    Some(output.words()[0])
}

const fn word(value: &[u8; 8]) -> u64 {
    padded_words::<1>(value)[0]
}
