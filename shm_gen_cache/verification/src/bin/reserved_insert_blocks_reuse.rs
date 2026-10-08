//! A public insertion reservation protects its arena until the writer
//! unpins, even after its epoch has left the two searchable generations.
//!
//! Unlike `lookup_during_reuse`'s unpinned readers, this writer needs arena
//! reuse to wait. Unlike `competing_rotations`, the timeout that matters is
//! the old-pin scan, not contention for rotation ownership.
//!
//! Setup: the cache starts empty at epoch 2. Every one-byte key/value record
//! fills its whole 32-byte arena.
//!
//! * Writer A inserts K -> VK.
//! * Rotator B first assumes that arena 2 (epoch 2) has bump 32: only A can
//!   have reserved that record, so A is pinned at epoch 2 or has already
//!   finished. B then inserts F -> VF twice and G -> VG once:
//!   - the first two inserts must succeed, rotating to epochs 3 and 4: an
//!     epoch-2 pin must not block those rotations;
//!   - the third must recycle arena 2. It either succeeds at epoch 5 (A has
//!     unpinned) or fails with exactly `ArenaReuseTimeout`.
//!     `RotationOwnerTimeout` is forbidden: A neither rotates nor reaches
//!     the occupancy target.
//!
//! On `ArenaReuseTimeout`, B snapshots: arena 2 still in its sealed epoch-2
//! incarnation, global epoch 4, the current arena (1) sealed and full, and
//! rotation ownership released. A may finish between the failed scan and
//! these loads, so arena 2's occupancy may be 0 or 1; nothing after the
//! return requires A to still be pinned.
//!
//! After the joins: A succeeded, both pins are clear and arena 2's
//! occupancy is 1. On the timeout branch, one public retry of G must
//! advance to epoch 5. Either way, a public lookup must return exactly VG
//! (on the success branch this can expose a stale writer publication
//! corrupting already recycled storage). K legitimately aged out; nothing
//! requires it to be retained.
//!
//! This is conditional protection/retry safety for a publicly identified
//! pin-blocked rotation, not a claim that every overlapping schedule times
//! out, nor that a stale pin read is impossible.
//!
//! Witnesses (after every check): both snapshot occupancies are reachable.
//! * `PIN_BLOCKS_REUSE`: blocked while A has not yet published (0);
//! * `PIN_BLOCKS_REUSE_PUBLISHED`: blocked after A published (1).
//!
//! Assumption: B's first load (relaxed) of arena 2's control word must show
//! A's reservation. The native smoke run does the load without constraining
//! it; if the reservation is not visible yet, B returns at once and main
//! only checks A's result and the pins.

#![no_std]
#![no_main]

use core::sync::atomic::Ordering::{Relaxed, SeqCst};

use genmc_harness::{Lent, assume, check, check_ok, padded_words, scope, witness};
use shm_gen_cache::test_access::{
    ARENAS, arena_ctl, arena_state, global_epoch, pinned_epoch, rotation_owner,
};
use shm_gen_cache::{
    Cache, CacheStorage, Error, NoopGetPid, ParticipantLock, StaticParams, output_buffer,
    static_config,
};

static_config! {
    struct Cfg: NoopGetPid {
        participant_capacity: 2,
        bucket_count: 8,
        max_key_size: 1,
        max_value_size: 1,
        record_area_size: 32,
        max_occupancy: 8,
        reservation_chunk_size: 8, // exact record accounting
    }
}

type Participant = ParticipantLock<'static, Cfg>;
type CacheRef = Cache<'static, Cfg>;

const HASH: u64 = 0;
const RESERVED_KEY: &[u8] = &[1];
const FILLER_KEY: &[u8] = &[2];
const FINAL_KEY: &[u8] = &[3];
const RESERVED_VALUE: &[u8] = &[11];
const FILLER_VALUE: &[u8] = &[22];
const FINAL_VALUE: &[u8] = &[33];
const FINAL_WORDS: [u64; 1] = padded_words(FINAL_VALUE);

/// Every record exactly fills an arena's record area.
const RECORD_BYTES: u32 = 32;
/// The arena holding epoch 2 (A's reservation) and later epoch 5.
const RECYCLED_ARENA: u64 = 2;
/// The arena holding epoch 4 when the third insert times out.
const CURRENT_ARENA: u64 = 1;

#[derive(Clone, Copy, PartialEq, Eq)]
enum Outcome {
    Unset,
    Success,
    OwnerTimeout,
    ReuseTimeout,
    OtherError,
}

struct Writer<'a> {
    participant: Lent<'a, Participant>,
    result: Outcome,
}

struct Rotator<'a> {
    participant: Lent<'a, Participant>,
    /// Whether B saw A's reservation (the assumption held).
    selected: bool,
    results: [Outcome; 3],
    /// Arena 2's occupancy in the snapshot after a reuse timeout.
    blocked_old_occupancy: u32,
}

/// A: reserve and publish K.
fn reserve_and_publish(writer: &mut Writer) {
    writer.result = insert(&mut writer.participant, RESERVED_KEY, RESERVED_VALUE);
}

/// B: rotate twice past A's epoch, then try to reuse A's arena.
fn rotate_and_attempt_reuse(rotator: &mut Rotator) {
    let cache = rotator.participant.cache();
    rotator.selected = reserved_in_epoch_two(cache);
    assume(rotator.selected);
    if !rotator.selected {
        return;
    }
    let p = &mut rotator.participant;
    rotator.results[0] = insert(p, FILLER_KEY, FILLER_VALUE);
    rotator.results[1] = insert(p, FILLER_KEY, FILLER_VALUE);
    rotator.results[2] = insert(p, FINAL_KEY, FINAL_VALUE);
    if rotator.results[2] == Outcome::ReuseTimeout {
        rotator.blocked_old_occupancy = expect_blocked_snapshot(cache);
        check!(pinned_epoch(rotator.participant.slot()).load(SeqCst) == 0);
    }
}

#[unsafe(no_mangle)]
extern "C" fn main() -> i32 {
    static STORAGE: <Cfg as StaticParams>::Storage = CacheStorage::new();
    // SAFETY: zeroed static storage, used only through this cache.
    let cache = check_ok!(unsafe { Cache::initialize(STORAGE.as_mut_ptr(), STORAGE.len(), Cfg) });
    let mut writer = check_ok!(cache.register_participant());
    let mut rotator = check_ok!(cache.register_participant());

    let mut a = Writer {
        participant: Lent::new(&mut writer),
        result: Outcome::Unset,
    };
    let mut b = Rotator {
        participant: Lent::new(&mut rotator),
        selected: false,
        results: [Outcome::Unset; 3],
        blocked_old_occupancy: 2,
    };
    scope(|s| {
        let ta = s.spawn(reserve_and_publish, &mut a);
        let tb = s.spawn(rotate_and_attempt_reuse, &mut b);
        ta.join();
        tb.join();
    });
    let writer_result = a.result;
    let (selected, results, blocked_old_occupancy) =
        (b.selected, b.results, b.blocked_old_occupancy);

    expect_unpinned(&writer, &rotator);
    check!(writer_result == Outcome::Success);
    if !selected {
        return 0;
    }
    check!(results[0] == Outcome::Success);
    check!(results[1] == Outcome::Success);
    check!(matches!(
        results[2],
        Outcome::Success | Outcome::ReuseTimeout
    ));
    let blocked = results[2] == Outcome::ReuseTimeout;

    expect_joined_state(cache, !blocked);
    if blocked {
        check_ok!(rotator.insert(HASH, FINAL_KEY, FINAL_VALUE));
    }
    expect_joined_state(cache, true);
    expect_final_lookup(&mut rotator);
    expect_joined_state(cache, true);
    expect_unpinned(&writer, &rotator);

    witness!("PIN_BLOCKS_REUSE", blocked && blocked_old_occupancy == 0);
    witness!(
        "PIN_BLOCKS_REUSE_PUBLISHED",
        blocked && blocked_old_occupancy == 1
    );
    0
}

fn insert(participant: &mut Participant, key: &[u8], value: &[u8]) -> Outcome {
    match participant.insert(HASH, key, value) {
        Ok(()) => Outcome::Success,
        Err(Error::RotationOwnerTimeout) => Outcome::OwnerTimeout,
        Err(Error::ArenaReuseTimeout) => Outcome::ReuseTimeout,
        Err(_) => Outcome::OtherError,
    }
}

/// Whether arena 2 holds A's reservation (bump 32). One relaxed load of the
/// control word: before B rotates, arena 2 is the open epoch-2 incarnation
/// and only A can have claimed bytes in it.
fn reserved_in_epoch_two(cache: CacheRef) -> bool {
    let ctl = arena_ctl(cache, RECYCLED_ARENA, Relaxed);
    ctl.epoch == 2 && !ctl.sealed && ctl.bump == RECORD_BYTES
}

/// B's snapshot after the third insert reported `ArenaReuseTimeout`;
/// returns arena 2's occupancy (0 or 1).
fn expect_blocked_snapshot(cache: CacheRef) -> u32 {
    check!(global_epoch(cache).load(Relaxed) == 4);
    check!(rotation_owner(cache, Relaxed).registration_id() == 0);
    let old = arena_state(cache, RECYCLED_ARENA, Relaxed);
    check!(old.epoch == 2 && old.sealed && old.bump == RECORD_BYTES);
    let old_occupancy = check_ok!(old.occupancy);
    check!(old_occupancy <= 1);
    let current = arena_state(cache, CURRENT_ARENA, Relaxed);
    check!(current.epoch == 4 && current.sealed && current.bump == RECORD_BYTES);
    check!(current.occupancy == Some(1));
    old_occupancy
}

/// The state after the joins: arenas 0 and 1 hold epochs 3 and 4 (sealed,
/// full); arena 2 holds either the sealed epoch 2 or, once `reused`, the
/// open epoch 5. Every arena holds exactly one record and nobody owns
/// rotation.
fn expect_joined_state(cache: CacheRef, reused: bool) {
    check!(global_epoch(cache).load(Relaxed) == if reused { 5 } else { 4 });
    check!(rotation_owner(cache, Relaxed).registration_id() == 0);
    for index in 0..ARENAS {
        let arena = arena_state(cache, index, Relaxed);
        let recycled = index == RECYCLED_ARENA;
        let epoch = match (recycled, reused) {
            (false, _) => index as u32 + 3,
            (true, true) => 5,
            (true, false) => 2,
        };
        check!(arena.epoch == epoch);
        check!(arena.sealed == !(recycled && reused));
        check!(arena.bump == RECORD_BYTES);
        check!(arena.occupancy == Some(1));
    }
}

fn expect_final_lookup(participant: &mut Participant) {
    let mut output = output_buffer!(1);
    let output_start = output.words().as_ptr().cast::<u8>();
    let value = check_ok!(check_ok!(participant.lookup(HASH, FINAL_KEY, &mut output)));
    check!(value.len() == FINAL_VALUE.len());
    check!(value.as_ptr() == output_start);
    // Compare whole words: lookups store whole words, and a byte read of
    // one would be a mixed-size access to GenMC.
    check!(genmc_harness::words_eq(output.words(), &FINAL_WORDS));
}

fn expect_unpinned(writer: &Participant, rotator: &Participant) {
    check!(pinned_epoch(writer.slot()).load(SeqCst) == 0);
    check!(pinned_epoch(rotator.slot()).load(SeqCst) == 0);
}
