//! A rotation-owner waiter can sleep through a release it needed, when the
//! same participant reacquires ownership before the waiter blocks; the
//! owner's next release wakes it. This documents a known, mild delay.
//!
//! Waiters block on the owner word's registration-id half. A participant
//! that rotates twice in a row owns the rotation under the same id both
//! times, so that half can go from its id to 0 and back before a waiter
//! that read it reaches FUTEX_WAIT:
//!
//! ```text
//! waiter W                          owner A (registration id X)
//! --------                          ---------------------------
//! word = owner word (id X)
//! done(word)? no (owner X, epoch 2)
//!                                   publish epoch 3
//!                                   release: owner = 0; wake: nobody queued
//!                                   acquire again: owner = X
//! FUTEX_WAIT(X): half == X, blocks
//!   although the epoch moved
//!                                   publish epoch 4
//!                                   release: owner = 0; wake: ends W's block
//! ```
//!
//! Natively W then sleeps until the owner's next release, the end of A's
//! second rotation however long it takes, instead of returning at once; at
//! most until its budget ends. Its result is unaffected: once awake it sees
//! the moved epoch.
//!
//! A waiter that reads no owner cannot be stranded. `done` judges the
//! same load whose half W would block on, so W returns before any
//! FUTEX_WAIT:
//!
//! ```text
//! waiter W                          owner A (registration id X)
//! --------                          ---------------------------
//!                                   release: owner = 0; wake: nobody queued
//! word = owner word (0)
//! done(word)? yes (no owner): returns
//!                                   acquire again: owner = X
//!                                   release: owner = 0; wake: nobody queued
//! ```
//!
//! Had `done` loaded the owner word again, it could have seen A's next
//! ownership and said no, leaving W to block on the 0 it read first:
//!
//! ```text
//! waiter W                          owner A (registration id X)
//! --------                          ---------------------------
//!                                   release: owner = 0; wake: nobody queued
//! word = owner word (0)
//!                                   acquire again: owner = X
//! done(second load: X, epoch 2)? no
//!                                   release: owner = 0; wake: nobody queued
//! FUTEX_WAIT(0): half == 0, blocks
//!   with no release left to wake it
//! ```
//!
//! (The epoch reads 2 if the relaxed load is stale, or if A's first
//! rotation failed and published nothing.)
//!
//! Built with `sgc_genmc_futex_model` (README.md): waits block in a model of
//! the futex without a timeout, and GenMC runs with `-check-liveness`, so
//! every blocked wait must still be woken.
//!
//! Setup: the cache starts at epoch 2 with arena 2 holding one record P.
//! Every one-byte key/value record fills its whole 32-byte arena, so every
//! insert but into an empty arena needs a rotation.
//!
//! * A inserts K1 then K2: each rotates (to epochs 3, then 4) under A's
//!   registration id.
//! * W inserts KW: its first attempt must rotate from epoch 2, and may wait
//!   for A's ownership.
//!
//! Assumption (the `ConsecutiveRotations` backend's `reservation_epoch_hook`,
//! README.md): W never reserves at epoch 3 (`assume(epoch != BETWEEN_A)`).
//!
//! W reserves at epoch 3 only if it wins the rotation to epoch 3, or if it
//! retries between A's two rotations. Excluding that leaves the executions
//! in which A rotates twice in a row, which is all the race needs. Without
//! the assumption, the exploration takes half an hour instead of seconds.
//!
//! The hook runs as a reservation attempt starts (W1), before the
//! participant pins or owns anything. So a thread that the `assume` stops
//! holds nothing another thread waits for. Stopped while owning the rotation,
//! it would leave the other waiting forever, which `-check-liveness` would
//! report.
//!
//! Natively `assume` is a no-op and the program runs unconstrained.
//!
//! After the joins: all three inserts succeeded, the epoch is 5 (one
//! rotation per insert), arenas 0, 1 and 2 hold epochs 3, 4 and 5 with one
//! record each, and no participant is pinned or owns rotation.
//!
//! Witnesses (after every check), from the model's counts:
//! * `BLOCKED_AFTER_WAIT_ENDED`: a wait blocked although its condition
//!   already held (`test_access::futex_model_stale_blocks`): the diagram.
//! * `BLOCKED_ON_OWNER`: a wait blocked, was woken, and no wait was stale:
//!   the ordinary case.

#![no_std]
#![no_main]

use core::sync::atomic::AtomicUsize;
use core::sync::atomic::Ordering::{Relaxed, SeqCst};

use genmc_harness::{Lent, assume, check, check_ok, scope, witness};
use shm_gen_cache::test_access::{ARENAS, arena_state, global_epoch, pinned_epoch, rotation_owner};
use shm_gen_cache::{
    Cache, CacheStorage, Error, GetPid, ParticipantLock, ParticipantSlot, StaticParams,
    static_config,
};

static_config! {
    struct Cfg: ConsecutiveRotations {
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
const SETUP_KEY: &[u8] = &[1];
const A_FIRST_KEY: &[u8] = &[2];
const A_SECOND_KEY: &[u8] = &[3];
const W_KEY: &[u8] = &[4];
const VALUE: &[u8] = &[10];

/// Every record exactly fills an arena's record area.
const RECORD_BYTES: u32 = 32;

/// The epoch between A's two rotations, at which W never reserves.
const BETWEEN_A: u64 = 3;

/// W's slot, recorded before the threads start.
static W_SLOT: AtomicUsize = AtomicUsize::new(0);

/// `NoopGetPid`'s liveness (one live PID), plus, in `reservation_epoch_hook`,
/// the assumption that W never reserves at `BETWEEN_A`.
struct ConsecutiveRotations;

impl GetPid for ConsecutiveRotations {
    fn get_pid() -> u32 {
        1
    }

    fn get_start_time() -> u64 {
        0
    }

    fn is_live(_pid: u32) -> Result<bool, Error> {
        Ok(true)
    }

    fn is_live_since(_pid: u32, _start_time: u64) -> Result<bool, Error> {
        Ok(true)
    }

    fn reservation_epoch_hook(slot: &ParticipantSlot, epoch: u64) {
        if core::ptr::from_ref(slot) as usize == W_SLOT.load(Relaxed) {
            assume(epoch != BETWEEN_A);
        }
    }
}

struct Inserter<'a> {
    participant: Lent<'a, Participant>,
    /// Successful inserts. A count rather than per-insert results: copying
    /// an array of one-byte results whole would be a mixed-size access to
    /// GenMC.
    successes: u32,
}

/// A: two inserts, each rotating under A's registration id. Written out:
/// iterating over an array of keys copies it with a memcpy that GenMC
/// cannot promote.
fn insert_twice(a: &mut Inserter) {
    if a.participant.insert(HASH, A_FIRST_KEY, VALUE).is_ok() {
        a.successes += 1;
    }
    if a.participant.insert(HASH, A_SECOND_KEY, VALUE).is_ok() {
        a.successes += 1;
    }
}

/// W: one insert, which must rotate from epoch 2 first.
fn insert_once(w: &mut Inserter) {
    if w.participant.insert(HASH, W_KEY, VALUE).is_ok() {
        w.successes += 1;
    }
}

#[unsafe(no_mangle)]
extern "C" fn main() -> i32 {
    static STORAGE: <Cfg as StaticParams>::Storage = CacheStorage::new();
    // SAFETY: zeroed static storage, used only through this cache.
    let cache = check_ok!(unsafe { Cache::initialize(STORAGE.as_mut_ptr(), STORAGE.len(), Cfg) });
    let mut a_lock = check_ok!(cache.register_participant());
    let mut w_lock = check_ok!(cache.register_participant());
    // P fills epoch 2's arena.
    check_ok!(a_lock.insert(HASH, SETUP_KEY, VALUE));
    check!(global_epoch(cache).load(Relaxed) == 2);
    W_SLOT.store(core::ptr::from_ref(w_lock.slot()) as usize, Relaxed);

    let mut a = Inserter {
        participant: Lent::new(&mut a_lock),
        successes: 0,
    };
    let mut w = Inserter {
        participant: Lent::new(&mut w_lock),
        successes: 0,
    };
    scope(|s| {
        let ta = s.spawn(insert_twice, &mut a);
        let tw = s.spawn(insert_once, &mut w);
        ta.join();
        tw.join();
    });

    check!(a.successes == 2);
    check!(w.successes == 1);
    expect_joined_state(cache);
    expect_unpinned(a_lock.slot(), w_lock.slot());

    let (woken_waits, stale_blocks) = model_counts();
    witness!("BLOCKED_AFTER_WAIT_ENDED", stale_blocks >= 1);
    witness!("BLOCKED_ON_OWNER", woken_waits >= 1 && stale_blocks == 0);
    0
}

/// The model's counts of woken waits and stale blocks; zero natively,
/// where there is no model.
fn model_counts() -> (u32, u32) {
    #[cfg(sgc_genmc)]
    let counts = (
        shm_gen_cache::test_access::futex_model_woken_waits(),
        shm_gen_cache::test_access::futex_model_stale_blocks(),
    );
    #[cfg(not(sgc_genmc))]
    let counts = (0, 0);
    counts
}

/// The state after the joins: arenas 0, 1 and 2 hold epochs 3, 4 (sealed)
/// and 5 (open); every arena holds exactly one record and nobody owns
/// rotation.
fn expect_joined_state(cache: CacheRef) {
    check!(global_epoch(cache).load(Relaxed) == 5);
    check!(rotation_owner(cache, Relaxed).registration_id() == 0);
    for index in 0..ARENAS {
        let arena = arena_state(cache, index, Relaxed);
        let current = index == 2;
        check!(arena.epoch == index as u32 + 3);
        check!(arena.sealed == !current);
        // A reservation add that finds the arena full or sealed still
        // advances its bump (overshoot), so only the record count is exact.
        check!(arena.bump >= RECORD_BYTES);
        check!(arena.occupancy == Some(1));
    }
}

fn expect_unpinned(a: &ParticipantSlot, w: &ParticipantSlot) {
    check!(pinned_epoch(a).load(SeqCst) == 0);
    check!(pinned_epoch(w).load(SeqCst) == 0);
}
