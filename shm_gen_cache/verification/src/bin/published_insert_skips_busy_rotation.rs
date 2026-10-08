//! An insert that has published its entry may leave occupancy-triggered
//! rotation to an existing owner, through the public participant API.
//!
//! Once an insert has published its table entry, occupancy-triggered
//! rotation is maintenance: the insert can leave the work to an owner that
//! is already rotating and return success before that owner finishes. This
//! test checks that this happens through public inserts and that the
//! accepted value stays publicly readable.
//!
//! Setup: main inserts S -> VS into epoch 2. Then T1 inserts A -> VA while
//! T2 inserts B -> VB. Each record takes 48 bytes, so the 144-byte record
//! area holds all three without a capacity-triggered rotation. With
//! `max_occupancy` 2, each writer requests rotation after it publishes. The
//! three keys have distinct home buckets only to avoid unrelated collision
//! and probing interleavings. In the execution of interest, both writers
//! publish in epoch 2; one owns rotation while the other returns from its
//! insert.
//!
//! Assumptions (neither selects success or readable contents):
//!
//! * After its insert returns, T2 passively reads (relaxed, no
//!   synchronisation) the rotation owner and the global epoch, and assumes
//!   T1 owns rotation and the epoch is still 2: T2 returned without seeing
//!   rotation complete. An inserter that rotated itself, or saw epoch 3,
//!   cannot then read epoch 2. That observation alone could also follow a
//!   `RotationOwnerTimeout` before B was published; `ArenaReuseTimeout` is
//!   excluded by the epoch bound.
//! * After the joins, main assumes the epoch-2 occupancy is 3: S, A and B
//!   were all accepted there. With one seed entry and `max_occupancy` 2,
//!   both new entries reached the threshold and requested rotation.
//!
//! The native smoke run performs the observations without constraining
//! them (and so usually stops after the unconditional checks).
//!
//! Properties:
//!
//! * every insert succeeds or times out waiting for the rotation owner, and
//!   both participants end unpinned;
//! * in the selected executions: both inserts succeeded; rotation moved the
//!   global epoch to 3 and released ownership; the sealed epoch-2 arena
//!   holds S, A and B (occupancy/bump 3/144) and the epoch-3 arena is empty;
//! * a public lookup of B returns exactly VB from epoch 2 and promotes it:
//!   the old arena stays at 3/144, the current one ends at 1/48.
//!
//! Witness `SKIPPED_BUSY_ROTATION`: the selected execution is reachable.

#![no_std]
#![no_main]

use core::sync::atomic::Ordering::Relaxed;

use genmc_harness::{Lent, assume, check, check_ok, padded_words, scope, witness};
use shm_gen_cache::test_access::{
    arena_state, distinct_bucket_hashes, global_epoch, pinned_epoch, registration_id,
    rotation_owner,
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
        record_area_size: 144,
        max_occupancy: 2,
        reservation_chunk_size: 8, // exact record accounting
    }
}

type Participant = ParticipantLock<'static, Cfg>;

const BUCKET_COUNT: u32 = 8;
const RECORD_SIZE: u32 = 48;

const SEED_KEY: &[u8; 10] = b"01234567SS";
const FIRST_KEY: &[u8; 10] = b"01234567AA";
const SECOND_KEY: &[u8; 10] = b"01234567BB";
const SEED_VALUE: &[u8; 10] = b"0000000000";
const FIRST_VALUE: &[u8; 10] = b"ABCDEFGHIJ";
const SECOND_VALUE: &[u8; 10] = b"abcdefghij";
const SECOND_VALUE_WORDS: [u64; 2] = padded_words(SECOND_VALUE);

const HASHES: [u64; 3] = distinct_bucket_hashes(BUCKET_COUNT);
const SEED_HASH: u64 = HASHES[0];
const FIRST_HASH: u64 = HASHES[1];
const SECOND_HASH: u64 = HASHES[2];

/// The epochs before and after the rotation, and their arenas.
const OLD_EPOCH: u64 = 2;
const NEW_EPOCH: u64 = 3;
const OLD_ARENA: u64 = OLD_EPOCH % 3;
const NEW_ARENA: u64 = NEW_EPOCH % 3;

#[derive(Clone, Copy, PartialEq, Eq)]
enum Outcome {
    Unset,
    Success,
    OwnerTimeout,
    OtherError,
}

struct Writer<'a> {
    participant: Lent<'a, Participant>,
    hash: u64,
    key: &'static [u8],
    value: &'static [u8],
    result: Outcome,
    /// T2 only: the registration id expected to own rotation, and what it
    /// observed after returning.
    watch: Option<RotationWatch>,
}

struct RotationWatch {
    expected_owner: u32,
    returned_during_rotation: bool,
}

fn insert(writer: &mut Writer) {
    writer.result = match writer
        .participant
        .insert(writer.hash, writer.key, writer.value)
    {
        Ok(()) => Outcome::Success,
        Err(Error::RotationOwnerTimeout) => Outcome::OwnerTimeout,
        Err(_) => Outcome::OtherError,
    };
    if let Some(watch) = &mut writer.watch {
        let cache = writer.participant.cache();
        watch.returned_during_rotation = rotation_pending(cache, watch.expected_owner);
        assume(watch.returned_during_rotation);
    }
}

#[unsafe(no_mangle)]
extern "C" fn main() -> i32 {
    static STORAGE: <Cfg as StaticParams>::Storage = CacheStorage::new();
    // SAFETY: zeroed static storage, used only through this cache.
    let cache = check_ok!(unsafe { Cache::initialize(STORAGE.as_mut_ptr(), STORAGE.len(), Cfg) });
    let mut p1 = check_ok!(cache.register_participant());
    let mut p2 = check_ok!(cache.register_participant());
    check_ok!(p1.insert(SEED_HASH, SEED_KEY, SEED_VALUE));

    let p1_registration = registration_id(p1.slot());
    let mut w1 = Writer {
        participant: Lent::new(&mut p1),
        hash: FIRST_HASH,
        key: FIRST_KEY,
        value: FIRST_VALUE,
        result: Outcome::Unset,
        watch: None,
    };
    let mut w2 = Writer {
        participant: Lent::new(&mut p2),
        hash: SECOND_HASH,
        key: SECOND_KEY,
        value: SECOND_VALUE,
        result: Outcome::Unset,
        watch: Some(RotationWatch {
            expected_owner: p1_registration,
            returned_during_rotation: false,
        }),
    };
    scope(|s| {
        let t1 = s.spawn(insert, &mut w1);
        let t2 = s.spawn(insert, &mut w2);
        t1.join();
        t2.join();
    });
    let results = [w1.result, w2.result];
    let returned_during_rotation = w2
        .watch
        .as_ref()
        .is_some_and(|w| w.returned_during_rotation);

    for result in results {
        check!(matches!(result, Outcome::Success | Outcome::OwnerTimeout));
    }
    check!(pinned_epoch(p1.slot()).load(Relaxed) == 0);
    check!(pinned_epoch(p2.slot()).load(Relaxed) == 0);
    let old_occupancy = arena_state(cache, OLD_ARENA, Relaxed).occupancy;
    let selected = returned_during_rotation && old_occupancy == Some(3);
    assume(selected);
    if !selected {
        return 0;
    }

    check!(results[0] == Outcome::Success);
    check!(results[1] == Outcome::Success);
    expect_rotated_state(cache, false);
    expect_second_value(&mut p2);
    expect_rotated_state(cache, true);
    check!(pinned_epoch(p1.slot()).load(Relaxed) == 0);
    check!(pinned_epoch(p2.slot()).load(Relaxed) == 0);

    witness!("SKIPPED_BUSY_ROTATION", selected);
    0
}

/// Whether T1 still owns rotation of epoch 2. These relaxed observations do
/// not synchronise with the rotator. The epoch read excludes a caller that
/// itself published or observed epoch 3.
fn rotation_pending(cache: Cache<'_, Cfg>, owner_registration: u32) -> bool {
    rotation_owner(cache, Relaxed).registration_id() == owner_registration
        && global_epoch(cache).load(Relaxed) == OLD_EPOCH
}

/// The state after the rotation, before (`looked_up == false`) and after
/// the lookup of B promoted it into the new epoch.
fn expect_rotated_state(cache: Cache<'_, Cfg>, looked_up: bool) {
    check!(global_epoch(cache).load(Relaxed) == NEW_EPOCH);
    check!(rotation_owner(cache, Relaxed).registration_id() == 0);

    let old = arena_state(cache, OLD_ARENA, Relaxed);
    check!(old.epoch == OLD_EPOCH as u32 && old.sealed && old.bump == 3 * RECORD_SIZE);
    check!(old.occupancy == Some(3));

    let promoted = if looked_up { 1 } else { 0 };
    let current = arena_state(cache, NEW_ARENA, Relaxed);
    check!(current.epoch == NEW_EPOCH as u32 && !current.sealed);
    check!(current.bump == promoted * RECORD_SIZE);
    check!(current.occupancy == Some(promoted));
}

fn expect_second_value(participant: &mut Participant) {
    let mut output = output_buffer!(10);
    let output_start = output.words().as_ptr().cast::<u8>();
    let (value_start, value_len) = match participant.lookup(SECOND_HASH, SECOND_KEY, &mut output) {
        Ok(Some(value)) => (value.as_ptr(), value.len()),
        Ok(None) => genmc_harness::fail("lookup of B missed\0"),
        Err(_) => genmc_harness::fail("lookup of B failed\0"),
    };
    check!(value_len == output.capacity());
    check!(value_start == output_start);
    check!(genmc_harness::words_eq(output.words(), &SECOND_VALUE_WORDS));
}
