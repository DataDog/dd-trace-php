//! Publication into recycled storage that still holds a stale record of the
//! same key, through the public participant API.
//!
//! Every ten-byte key/value record fills the whole 48-byte record area.
//!
//! Setup (main, serial):
//!
//! ```text
//! insert K -> V0        epoch 2: arena 2 holds K/V0
//! insert F -> VF        rotates to epoch 3 (arena 0)
//! insert F -> VF        rotates to epoch 4 (arena 1)
//! lookup K              miss
//! ```
//!
//! K is now absent from both searchable generations (epochs 3 and 4), but
//! arena 2 still physically contains its epoch-2 slot and the V0 payload.
//! K and F have distinct home buckets.
//!
//! Then, concurrently:
//!
//! ```text
//! T1: insert K -> V1    rotates naturally from epoch 4 to 5, reusing arena 2
//! T2: lookup K
//! ```
//!
//! Rotation resets the arena's control word and occupancy, not its index
//! table or payload bytes. Checked after the joins:
//!
//! * T1's insert succeeded;
//! * T2 saw a miss or exactly V1: never V0, a torn value, or an error.
//!
//! This exercises the rejection of stale incarnations and publication into
//! recycled storage, not concurrent inserts into a fresh arena.
//!
//! K cannot be found in an older searchable generation (it is absent from
//! epochs 3 and 4, and only the current epoch 5 can hold a live K), so the
//! lookup never promotes, only T1 rotates, and no timeout or extra rotation
//! can happen. Hence after the joins (see [`expect_state`]): epoch 5's arena
//! is unsealed with bump 48 and occupancy 1, the other arenas keep their
//! sealed epochs 3 and 4, also full with occupancy 1, rotation ownership is
//! released and both participants are unpinned. A final lookup returns V1
//! and leaves all of that unchanged. F is not looked up afterwards:
//! promoting it into the full arena could legitimately rotate.
//!
//! Witnesses: T2's two allowed outcomes (MISS, V1) are both reachable,
//! after every safety check. Both have serial witnesses; neither proves the
//! reader probed specifically between the reset and the slot publication.
//!
//! No hooks, assumptions, barriers or direct `rotate` calls are used.
//! Internals (`test_access`) are only read, and only while no worker runs.
//! The suite-wide short-wait and word-state variants apply, but owner-wait
//! and pin-blocking paths should be unreachable. Out of scope: pinning
//! preventing rotation, an old reader copying across reuse, the final
//! age-validation retry, OS identity and dead-owner recovery.

#![no_std]
#![no_main]

use core::sync::atomic::Ordering::Relaxed;

use genmc_harness::{Lent, check, check_ok, padded_words, scope, witness};
use shm_gen_cache::test_access::{
    ARENAS, arena_state, distinct_bucket_hashes, global_epoch, pinned_epoch, rotation_owner,
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
        record_area_size: 48,
        max_occupancy: 8,
        reservation_chunk_size: 8, // exact record accounting
    }
}

type Participant = ParticipantLock<'static, Cfg>;
type CacheRef = Cache<'static, Cfg>;

const BUCKET_COUNT: u32 = 8;
const KEY: &[u8; 10] = b"01234567KK";
const FILLER_KEY: &[u8; 10] = b"01234567FF";
const V0: &[u8; 10] = b"0123456789";
const V1: &[u8; 10] = b"ABCDEFGHIJ";
const FILLER_VALUE: &[u8; 10] = b"ffffffffff";
const V1_WORDS: [u64; 2] = padded_words(V1);

/// K's hash and F's, with distinct home buckets.
const HASHES: [u64; 2] = distinct_bucket_hashes(BUCKET_COUNT);
const HASH: u64 = HASHES[0];
const FILLER_HASH: u64 = HASHES[1];

#[derive(Clone, Copy, PartialEq, Eq)]
enum Outcome {
    Unset,
    Miss,
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
    let unpinned = |p1: &Participant, p2: &Participant| {
        pinned_epoch(p1.slot()).load(Relaxed) == 0 && pinned_epoch(p2.slot()).load(Relaxed) == 0
    };

    check_ok!(p1.insert(HASH, KEY, V0));
    check_ok!(p1.insert(FILLER_HASH, FILLER_KEY, FILLER_VALUE));
    check_ok!(p1.insert(FILLER_HASH, FILLER_KEY, FILLER_VALUE));
    expect_state(cache, 4);
    check!(observe(&mut p2) == Outcome::Miss);
    expect_state(cache, 4);
    check!(unpinned(&p1, &p2));

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
    check!(matches!(observed, Outcome::Miss | Outcome::V1));
    expect_state(cache, 5);
    check!(unpinned(&p1, &p2));
    check!(observe(&mut p2) == Outcome::V1);
    expect_state(cache, 5);
    check!(unpinned(&p1, &p2));

    witness!("MISS", observed == Outcome::Miss);
    witness!("V1", observed == Outcome::V1);
    0
}

/// Checks the quiescent state when `epoch` (4 or 5) is current: no rotation
/// owner, and each arena full (bump 48) with occupancy 1, sealed unless it
/// holds the current epoch. Arenas 0 and 1 hold epochs 3 and 4; arena 2
/// holds the stale epoch 2 before reuse and epoch 5 after.
fn expect_state(cache: CacheRef, epoch: u32) {
    check!(global_epoch(cache).load(Relaxed) == u64::from(epoch));
    check!(rotation_owner(cache, Relaxed).registration_id() == 0);
    let epochs: [u32; ARENAS as usize] = [3, 4, if epoch == 4 { 2 } else { 5 }];
    for (index, &arena_epoch) in (0..ARENAS).zip(&epochs) {
        let state = arena_state(cache, index, Relaxed);
        check!(state.epoch == arena_epoch);
        check!(state.sealed == (arena_epoch != epoch));
        check!(state.bump == 48);
        check!(state.occupancy == Some(1));
    }
}

fn observe(participant: &mut Participant) -> Outcome {
    let mut output = output_buffer!(10);
    let len = match participant.lookup(HASH, KEY, &mut output) {
        Err(_) => return Outcome::Other,
        Ok(None) => return Outcome::Miss,
        Ok(Some(value)) => value.len(),
    };
    if len == output.capacity() && genmc_harness::words_eq(output.words(), &V1_WORDS) {
        Outcome::V1
    } else {
        Outcome::Other
    }
}
