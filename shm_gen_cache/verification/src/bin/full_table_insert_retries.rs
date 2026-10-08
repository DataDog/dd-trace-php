//! A reserved record that finds every table bucket taken by other keys must
//! rotate, retry and publish before its public insert reports success.
//! (`reserved_insert_survives_occupancy` checks that the occupancy target
//! alone cannot reject a reservation; this test exhausts the real buckets.)
//!
//! Setup, by main through the public API, in epoch 2: seven inserts fill
//! seven of the eight buckets (one-byte keys `0..=6`, each with a hash whose
//! home bucket is a different one of buckets 0..=6). Then two unordered
//! inserts whose hashes both start at the eighth bucket:
//!
//! ```text
//! T1: FIRST_KEY  -> FIRST_VALUE
//! T2: SECOND_KEY -> SECOND_VALUE   (symmetric to T1 in the safety build)
//! ```
//!
//! Every record is 32 bytes, and all nine fit the 288-byte record area
//! (`reservation_chunk_size` 8 keeps the byte accounting exact). With
//! `max_occupancy` 8, the winner publishes the eighth entry and requests a
//! rotation. If both workers reserved in epoch 2, the other writer's full
//! probe finds no reusable bucket, so its reserved record is abandoned: it
//! must unpin, wait for the rotation if needed, and reserve and publish
//! again in epoch 3.
//!
//! Assumption (after the joins): both public inserts succeeded and the old
//! arena's bump is 288, i.e. nine distinct records were reserved in epoch 2,
//! which eight buckets cannot all hold, so one was rejected. The selector
//! does not look at occupancy, the global epoch, the current arena, table
//! entries or lookups. Native runs skip the checks when it does not hold.
//!
//! Checked:
//!
//! * each insert succeeds or returns `RotationOwnerTimeout` (a space-needing
//!   insert may time out while the accepted writer owns rotation); no pin is
//!   ever old enough for `ArenaReuseTimeout`;
//! * both participants end unpinned;
//! * the global epoch is 3 and nobody owns rotation;
//! * the old arena (epoch 2) is sealed with bump 288 and occupancy 8;
//! * the current arena (epoch 3) is unsealed with bump/occupancy 32/1: only
//!   the retried record;
//! * both keys read back exactly their values (the output buffer holds the
//!   hit and the returned slice is the buffer's);
//! * the old-arena hit is promoted into epoch 3 without another rotation,
//!   leaving it at bump/occupancy 64/2, and both participants unpinned.
//!
//! These are conditional no-false-success/republication properties.
//!
//! Witness `PHYSICAL_FULL_RETRY`: the selected physical-full retry is
//! reachable. Symmetric winners need no separate witnesses: swapping T1's
//! and T2's participant/key/value preserves the shared home bucket, both
//! successes, the selector, the aggregate metadata and each key's readback.
//! Witness variants spawn both threads normally.
//!
//! `NoopGetPid` keeps OS identity and slot reaping out of scope.

#![no_std]
#![no_main]

use core::sync::atomic::Ordering::{Relaxed, SeqCst};

use genmc_harness::{Lent, assume, check, check_ok, padded_words, scope, witness};
use shm_gen_cache::test_access::{arena_state, bucket, global_epoch, pinned_epoch, rotation_owner};
use shm_gen_cache::{
    Cache, CacheStorage, Error, NoopGetPid, ParticipantLock, StaticParams, output_buffer,
    static_config,
};

const BUCKETS: u32 = 8;

static_config! {
    struct Cfg: NoopGetPid {
        participant_capacity: 2,
        bucket_count: BUCKETS,
        max_key_size: 1,
        max_value_size: 1,
        record_area_size: 288,
        max_occupancy: 8,
        reservation_chunk_size: 8, // exact record accounting
    }
}

type Participant = ParticipantLock<'static, Cfg>;

/// `HOME_HASH[b]` is the smallest hash whose home bucket is `b`.
const HOME_HASH: [u64; BUCKETS as usize] = {
    let mut hashes = [0; BUCKETS as usize];
    let mut b = 0;
    while b < BUCKETS {
        let mut candidate = 0;
        while bucket(candidate, BUCKETS) != b {
            candidate += 1;
        }
        hashes[b as usize] = candidate;
        b += 1;
    }
    hashes
};
/// The home bucket left free by the setup, shared by both workers.
const CONTESTED_HASH: u64 = HOME_HASH[7];

const FIRST_KEY: &[u8; 1] = &[7];
const SECOND_KEY: &[u8; 1] = &[8];
const FIRST_VALUE: &[u8; 1] = &[42];
const SECOND_VALUE: &[u8; 1] = &[43];
const SETUP_VALUE: &[u8; 1] = &[16];

/// Arena indices of epoch 2 (the setup's) and epoch 3 (after rotation).
const OLD_ARENA: u64 = 2;
const CURRENT_ARENA: u64 = 0;

struct Insert<'a> {
    participant: Lent<'a, Participant>,
    key: &'static [u8],
    value: &'static [u8],
    succeeded: bool,
}

fn insert(op: &mut Insert) {
    let result = op.participant.insert(CONTESTED_HASH, op.key, op.value);
    check!(matches!(result, Ok(()) | Err(Error::RotationOwnerTimeout)));
    op.succeeded = result.is_ok();
}

#[unsafe(no_mangle)]
extern "C" fn main() -> i32 {
    static STORAGE: <Cfg as StaticParams>::Storage = CacheStorage::new();
    // SAFETY: zeroed static storage, used only through this cache.
    let cache = check_ok!(unsafe { Cache::initialize(STORAGE.as_mut_ptr(), STORAGE.len(), Cfg) });
    let mut p1 = check_ok!(cache.register_participant());
    let mut p2 = check_ok!(cache.register_participant());
    for seed in 0..7u8 {
        check_ok!(p1.insert(HOME_HASH[seed as usize], &[seed], SETUP_VALUE));
    }

    let mut t1 = Insert {
        participant: Lent::new(&mut p1),
        key: FIRST_KEY,
        value: FIRST_VALUE,
        succeeded: false,
    };
    let mut t2 = Insert {
        participant: Lent::new(&mut p2),
        key: SECOND_KEY,
        value: SECOND_VALUE,
        succeeded: false,
    };
    scope(|s| {
        let first = s.spawn(insert, &mut t1);
        let second = s.spawn_symmetric(insert, &mut t2, &first);
        first.join();
        second.join();
    });
    let both_succeeded = t1.succeeded && t2.succeeded;

    let unpinned = |p: &Participant| pinned_epoch(p.slot()).load(SeqCst) == 0;
    check!(unpinned(&p1));
    check!(unpinned(&p2));
    let selected = both_succeeded && arena_state(cache, OLD_ARENA, Relaxed).bump == 288;
    assume(selected);
    if !selected {
        return 0;
    }

    check_arenas(cache, false);
    check!(lookup(&mut p1, FIRST_KEY) == Some(word(FIRST_VALUE)));
    check!(lookup(&mut p2, SECOND_KEY) == Some(word(SECOND_VALUE)));
    check_arenas(cache, true);
    check!(unpinned(&p1));
    check!(unpinned(&p2));

    witness!("PHYSICAL_FULL_RETRY", selected);
    0
}

/// Epoch 3 is current with nobody rotating; epoch 2 is sealed and full;
/// epoch 3 holds the retried record, plus the promoted old hit once
/// `looked_up`.
fn check_arenas(cache: Cache<'static, Cfg>, looked_up: bool) {
    check!(global_epoch(cache).load(Relaxed) == 3);
    check!(rotation_owner(cache, Relaxed).registration_id() == 0);

    let old = arena_state(cache, OLD_ARENA, Relaxed);
    check!(old.epoch == 2 && old.sealed && old.bump == 288);
    check!(old.occupancy == Some(8));

    let records = if looked_up { 2 } else { 1 };
    let current = arena_state(cache, CURRENT_ARENA, Relaxed);
    check!(current.epoch == 3 && !current.sealed);
    check!(current.bump == 32 * records);
    check!(current.occupancy == Some(records));
}

/// The value of `key` (its one padded word) or `None` on a miss; a hit must
/// fill the whole buffer and be returned as the buffer itself.
fn lookup(participant: &mut Participant, key: &[u8]) -> Option<u64> {
    let mut output = output_buffer!(1);
    let buffer = output.words().as_ptr().cast::<u8>();
    let hit = check_ok!(participant.lookup(CONTESTED_HASH, key, &mut output))?;
    check!(hit.len() == 1);
    check!(hit.as_ptr() == buffer);
    Some(output.words()[0])
}

const fn word(value: &[u8; 1]) -> u64 {
    padded_words::<1>(value)[0]
}
