//! Competing public inserts rotate a full arena safely, and a newly
//! registered rotation owner is recognised as live.
//!
//! Setup: main inserts S -> V0 twice. The replacement allocates a second
//! 48-byte record while one slot stays occupied, which fills epoch 2's
//! 96-byte record area without rotating. Then two symmetric workers insert
//! distinct keys into distinct buckets:
//!
//! ```text
//! T1: FIRST_KEY  -> V1
//! T2: SECOND_KEY -> V2      (symmetric to T1 in the safety build)
//! ```
//!
//! Both can run out of record space and request rotation through
//! `insert()`. Epoch 3 fits both new records, so neither needs a second
//! rotation.
//!
//! A contender may retry after another participant rotates, or time out
//! waiting for a live rotation owner. Public insert consumes the internal
//! `ConcurrentOperation` result, so its only permitted failure here is
//! `RotationOwnerTimeout`. `ArenaReuseTimeout` is forbidden: no pin is old
//! enough to block the only rotation. At least one insert must succeed. A
//! timed-out insert cannot have published in this configuration: it fails
//! before reservation, and neither occupancy nor table capacity can require
//! a rotation after publication.
//!
//! Checked after the joins:
//!
//! * every successful key returns its exact value, every timed-out key
//!   misses;
//! * epoch 3, rotation ownership released, both worker slots unpinned;
//! * the old arena (epoch 2) is sealed, full (bump 96) and holds the seed;
//! * the current arena's occupancy is the number of successful inserts and
//!   its bump is that count times 48.
//!
//! These expose a stale contender resetting an arena into which another
//! insert has written. Main uses a fresh registration for the final
//! lookups, and checks the metadata before and after them. The seed is not
//! looked up: promoting it could require a rotation.
//!
//! The workers register in their own threads (not at setup), which also
//! checks this publication path between them:
//!
//! ```text
//! rotation owner                     contender
//! --------------                     ---------
//! register participant slot
//! W3 seq_cst fence (also release)
//! CAS owner = {slot, id}  --------->  acquire-load owner
//!                                     registration_is_live(slot, id)
//! ```
//!
//! `registration_is_live()` reads the owner's participant slot. Acquiring
//! the owner word must make the matching registration visible; otherwise
//! the contender may read the slot's earlier FREE state and take rotation
//! from a live owner. This is separate from the participant-pin scan done
//! after rotation ownership has been acquired.
//!
//! The worker's registration outlives `insert()`. A rotator clears the
//! owner word before `insert()` returns, and only then can dropping the
//! registration publish FREE. A contender that loaded the old owner may
//! later see that FREE, but its takeover CAS must fail against the cleared
//! owner word. So a takeover based on FREE can succeed only if the
//! contender failed to acquire the still-live owner's registration.
//!
//! Witnesses: T1's insert succeeds in some execution (`V1`), so does T2's
//! (`V2`), and in some execution an insert times out (`TIMEOUT`).

#![no_std]
#![no_main]

use core::sync::atomic::Ordering::Relaxed;

use genmc_harness::{check, check_ok, padded_words, scope, witness, words_eq};
use shm_gen_cache::test_access::{
    arena_state, distinct_bucket_hashes, global_epoch, participant, pinned_epoch, rotation_owner,
};
use shm_gen_cache::{
    Cache, CacheStorage, Error, NoopGetPid, ParticipantLock, StaticParams, output_buffer,
    static_config,
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

const BUCKET_COUNT: u32 = 8;
const SEED_KEY: &[u8; 10] = b"01234567S0";
const FIRST_KEY: &[u8; 10] = b"01234567A1";
const SECOND_KEY: &[u8; 10] = b"01234567B2";
const SEED_VALUE: &[u8; 10] = b"0000000000";
const V1: &[u8; 10] = b"ABCDEFGHIJ";
const V2: &[u8; 10] = b"abcdefghij";

/// Two hashes with distinct home buckets.
const HASHES: [u64; 2] = distinct_bucket_hashes(BUCKET_COUNT);
const FIRST_HASH: u64 = HASHES[0];
const SECOND_HASH: u64 = HASHES[1];

/// One 48-byte record (header + 10-byte key + 10-byte value).
const RECORD_SIZE: u32 = 48;

#[derive(Clone, Copy, PartialEq, Eq)]
enum Outcome {
    Unset,
    Success,
    OwnerTimeout,
    OtherError,
}

struct Writer {
    cache: Cache<'static, Cfg>,
    hash: u64,
    key: &'static [u8],
    value: &'static [u8],
    result: Outcome,
}

/// Registers in the worker's own thread, then inserts. The registration
/// is dropped (unregistered) only after `insert()` has returned.
fn insert(writer: &mut Writer) {
    let mut participant = check_ok!(writer.cache.register_participant());
    writer.result = match participant.insert(writer.hash, writer.key, writer.value) {
        Ok(()) => Outcome::Success,
        Err(Error::RotationOwnerTimeout) => Outcome::OwnerTimeout,
        Err(_) => Outcome::OtherError,
    };
}

#[unsafe(no_mangle)]
extern "C" fn main() -> i32 {
    static STORAGE: <Cfg as StaticParams>::Storage = CacheStorage::new();
    // SAFETY: zeroed static storage, used only through this cache.
    let cache = check_ok!(unsafe { Cache::initialize(STORAGE.as_mut_ptr(), STORAGE.len(), Cfg) });
    {
        let mut setup = check_ok!(cache.register_participant());
        check_ok!(setup.insert(FIRST_HASH, SEED_KEY, SEED_VALUE));
        check_ok!(setup.insert(FIRST_HASH, SEED_KEY, SEED_VALUE));
    }

    let mut t1 = Writer {
        cache,
        hash: FIRST_HASH,
        key: FIRST_KEY,
        value: V1,
        result: Outcome::Unset,
    };
    let mut t2 = Writer {
        cache,
        hash: SECOND_HASH,
        key: SECOND_KEY,
        value: V2,
        result: Outcome::Unset,
    };
    scope(|s| {
        let first = s.spawn(insert, &mut t1);
        let second = s.spawn_symmetric(insert, &mut t2, &first);
        first.join();
        second.join();
    });

    let results = [t1.result, t2.result];
    let mut inserted = 0;
    for result in results {
        check!(result == Outcome::Success || result == Outcome::OwnerTimeout);
        inserted += (result == Outcome::Success) as u32;
    }
    check!(inserted >= 1);

    expect_final_state(cache, inserted);
    let mut reader = check_ok!(cache.register_participant());
    expect_lookup(&mut reader, FIRST_HASH, FIRST_KEY, V1, t1.result);
    expect_lookup(&mut reader, SECOND_HASH, SECOND_KEY, V2, t2.result);
    expect_final_state(cache, inserted);

    witness!("V1", t1.result == Outcome::Success);
    witness!("V2", t2.result == Outcome::Success);
    witness!(
        "TIMEOUT",
        t1.result == Outcome::OwnerTimeout || t2.result == Outcome::OwnerTimeout
    );
    0
}

/// One rotation happened (epoch 2 -> 3, arena 2 -> arena 0) and nothing
/// else: the old arena is intact, the current one holds exactly the
/// successful inserts, and nobody owns rotation or is pinned.
fn expect_final_state(cache: Cache<'static, Cfg>, inserted: u32) {
    check!(global_epoch(cache).load(Relaxed) == 3);
    check!(rotation_owner(cache, Relaxed).registration_id() == 0);

    let old = arena_state(cache, 2, Relaxed);
    check!(old.epoch == 2 && old.sealed && old.bump == 2 * RECORD_SIZE);
    check!(old.occupancy == Some(1));

    let current = arena_state(cache, 0, Relaxed);
    check!(current.epoch == 3 && !current.sealed);
    check!(current.bump == inserted * RECORD_SIZE);
    check!(current.occupancy == Some(inserted));

    for i in 0..2 {
        check!(pinned_epoch(participant(cache, i)).load(Relaxed) == 0);
    }
}

/// `key` holds exactly `expected` if its insert succeeded, and misses if it
/// timed out.
fn expect_lookup(
    reader: &mut Participant,
    hash: u64,
    key: &[u8],
    expected: &[u8; 10],
    inserted: Outcome,
) {
    let mut output = output_buffer!(10);
    let hit = check_ok!(reader.lookup(hash, key, &mut output)).map(|hit| (hit.as_ptr(), hit.len()));
    if inserted == Outcome::OwnerTimeout {
        check!(hit.is_none());
        return;
    }
    let (data, len) = check_ok!(hit);
    check!(len == output.capacity());
    check!(data == output.words().as_ptr().cast());
    check!(words_eq(output.words(), &padded_words::<2>(expected)));
}
