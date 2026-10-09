//! A rotation blocked on a pin is woken by the unpin: arena reuse waits for
//! the writer, and no wake is lost.
//!
//! Built with `sgc_genmc_futex_model` (README.md): a rotation's wait for a
//! pin (R3) announces itself by flagging the pin (`ROTATION_WAITING`), then
//! blocks in a model of the futex that has no timeout, so the wait never
//! gives up. GenMC runs with `-check-liveness`: an execution in which the
//! rotation blocks and the unpin does not wake it is a violation, where
//! natively it would only delay the rotation until its budget ends. The
//! setup is that of `reserved_insert_blocks_reuse`, whose short waits let
//! the rotation time out instead.
//!
//! Setup: the cache starts empty at epoch 2. Every one-byte key/value record
//! fills its whole 32-byte arena.
//!
//! * Writer A inserts K -> VK.
//! * Rotator B first assumes that arena 2 (epoch 2) has bump 32: only A can
//!   have reserved that record, so A is pinned at epoch 2 or has already
//!   finished. B then inserts F -> VF twice and G -> VG once. The first two
//!   rotate to epochs 3 and 4, which an epoch-2 pin does not block. The
//!   third recycles arena 2, so its rotation waits for A's pin; under the
//!   model it must succeed at epoch 5, whenever A unpins.
//!
//! After the joins: A's insert and all three of B's succeeded, both
//! pins are clear (no leftover `ROTATION_WAITING`), arenas 0, 1 and 2 hold
//! epochs 3, 4 and 5 with one record each, and a public lookup of G returns
//! exactly VG.
//!
//! Witnesses (after every check), from the model's count of woken waits:
//! * `BLOCKED_THEN_WOKEN`: B blocked in the futex and A's unpin woke it;
//! * `UNPINNED_BEFORE_BLOCKING`: A unpinned before B blocked (B's R3 loop
//!   saw the cleared pin, or its announcement's compare-exchange failed, or
//!   the futex word no longer held the pin). The model has no spin polls.
//!
//! Assumption: B's first load (relaxed) of arena 2's control word must show
//! A's reservation. The native smoke run, with the real waits and their
//! 5 ms budget, does the load without constraining it, and also accepts an
//! `ArenaReuseTimeout` of the third insert, followed by one retry.

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
}

/// A: reserve and publish K.
fn reserve_and_publish(writer: &mut Writer) {
    writer.result = insert(&mut writer.participant, RESERVED_KEY, RESERVED_VALUE);
}

/// B: rotate twice past A's epoch, then reuse A's arena.
fn rotate_and_reuse(rotator: &mut Rotator) {
    rotator.selected = reserved_in_epoch_two(rotator.participant.cache());
    assume(rotator.selected);
    if !rotator.selected {
        return;
    }
    let p = &mut rotator.participant;
    rotator.results[0] = insert(p, FILLER_KEY, FILLER_VALUE);
    rotator.results[1] = insert(p, FILLER_KEY, FILLER_VALUE);
    rotator.results[2] = insert(p, FINAL_KEY, FINAL_VALUE);
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
    };
    scope(|s| {
        let ta = s.spawn(reserve_and_publish, &mut a);
        let tb = s.spawn(rotate_and_reuse, &mut b);
        ta.join();
        tb.join();
    });
    let writer_result = a.result;
    let (selected, results) = (b.selected, b.results);

    expect_unpinned(&writer, &rotator);
    check!(writer_result == Outcome::Success);
    if !selected {
        return 0;
    }
    check!(results[0] == Outcome::Success);
    check!(results[1] == Outcome::Success);
    // Under the model the reuse wait never gives up; natively, with the
    // real budget, it may time out (see the opening comment).
    if cfg!(sgc_genmc) {
        check!(results[2] == Outcome::Success);
    } else {
        check!(matches!(
            results[2],
            Outcome::Success | Outcome::ReuseTimeout
        ));
        if results[2] == Outcome::ReuseTimeout {
            check_ok!(rotator.insert(HASH, FINAL_KEY, FINAL_VALUE));
        }
    }

    expect_joined_state(cache);
    expect_final_lookup(&mut rotator);
    expect_unpinned(&writer, &rotator);

    let woken_waits = woken_waits();
    witness!("BLOCKED_THEN_WOKEN", woken_waits == 1);
    witness!("UNPINNED_BEFORE_BLOCKING", woken_waits == 0);
    0
}

/// The model's count of woken waits; zero natively, where there is no
/// model.
fn woken_waits() -> u32 {
    #[cfg(sgc_genmc)]
    let waits = shm_gen_cache::test_access::futex_model_woken_waits();
    #[cfg(not(sgc_genmc))]
    let waits = 0;
    waits
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

/// The state after the joins: arenas 0, 1 and 2 hold epochs 3, 4 (sealed,
/// full) and 5 (open); every arena holds exactly one record and nobody owns
/// rotation.
fn expect_joined_state(cache: CacheRef) {
    check!(global_epoch(cache).load(Relaxed) == 5);
    check!(rotation_owner(cache, Relaxed).registration_id() == 0);
    for index in 0..ARENAS {
        let arena = arena_state(cache, index, Relaxed);
        let recycled = index == RECYCLED_ARENA;
        check!(arena.epoch == if recycled { 5 } else { index as u32 + 3 });
        check!(arena.sealed == !recycled);
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

/// Both pins are clear: no participant is pinned, and no
/// `ROTATION_WAITING` flag survived the unpin.
fn expect_unpinned(writer: &Participant, rotator: &Participant) {
    check!(pinned_epoch(writer.slot()).load(SeqCst) == 0);
    check!(pinned_epoch(rotator.slot()).load(SeqCst) == 0);
}
