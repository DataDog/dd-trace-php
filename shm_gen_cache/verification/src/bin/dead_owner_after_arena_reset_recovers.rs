//! Recovery when a rotation owner dies after R4 resets the next arena but
//! before R5 publishes its epoch.
//!
//! Setup uses public inserts to fill epochs 2, 3 and 4, one 32-byte record
//! per arena. Epoch 2's stale record remains physically in arena 2. The
//! survivor keeps slot 0 registered. A second participant publicly inserts
//! another key, takes ownership of the 4 -> 5 rotation, and stops at the
//! verification-only hook immediately after the real R4 store. It leaves
//! its registration and ownership behind, as a dead process would.
//!
//! Before recovery, the test checks the exact interrupted state: global
//! epoch 4, its arena sealed, arena 2 reset to stamp 5 with zero bump and
//! occupancy, and the dead owner still named. No participant is pinned.
//! The survivor then publicly inserts a new key. It must take over, repeat
//! the scan and reset, publish epoch 5, and insert one complete record.
//! The new key must hit; the abandoned insert and the stale epoch-2 key
//! must miss. Metadata and pin checks reject duplicate rotation or a
//! reservation in the wrong incarnation.
//!
//! The crash is unconditional during setup, so there is no reachability
//! witness or scheduling assumption. The hook adds no shared access on its
//! default path. The liveness verdict acquires the hook's release flag,
//! importing the dead owner's final writes, as GetPid requires. GenMC joins
//! the stopped thread; natively its parked thread is leaked.

#![no_std]
#![no_main]

use core::sync::atomic::Ordering::{Acquire, Relaxed, Release};
use core::sync::atomic::{AtomicBool, AtomicU32};

use genmc_harness::{abandon_thread, check, check_ok, padded_words, scope, words_eq};
use shm_gen_cache::test_access::{
    arena_state, distinct_bucket_hashes, global_epoch, participant, pinned_epoch,
    promotion_ready_epoch, rotation_owner,
};
use shm_gen_cache::{
    Cache, CacheStorage, Error, GetPid, ParticipantLock, StaticParams, output_buffer, static_config,
};

static_config! {
    struct Cfg: StagedDeath {
        participant_capacity: 2,
        bucket_count: 8,
        max_key_size: 1,
        max_value_size: 1,
        record_area_size: 32,
        max_occupancy: 8,
        reservation_chunk_size: 8,
    }
}

type Participant = ParticipantLock<'static, Cfg>;
type CacheRef = Cache<'static, Cfg>;

const LIVE_PID: u32 = 1;
const DEAD_PID: u32 = 2;
const LIVE_START: u64 = 100;
const DEAD_START: u64 = 200;

const HASHES: [u64; 4] = distinct_bucket_hashes(8);
const SEED_KEY: &[u8; 1] = b"S";
const FILLER_KEY: &[u8; 1] = b"F";
const ABANDONED_KEY: &[u8; 1] = b"A";
const RECOVERED_KEY: &[u8; 1] = b"R";
const RECOVERED_VALUE: &[u8; 1] = b"V";

struct StagedDeath;

/// Only the owner uses the dead identity, between its registration and
/// abandonment. The survivor uses the live identity before and after that
/// interval; it performs no cache call while the owner runs.
static CURRENT_PID: AtomicU32 = AtomicU32::new(LIVE_PID);
static OWNER_ABANDONED: AtomicBool = AtomicBool::new(false);

impl GetPid for StagedDeath {
    fn get_pid() -> u32 {
        CURRENT_PID.load(Relaxed)
    }

    fn get_start_time() -> u64 {
        if Self::get_pid() == LIVE_PID {
            LIVE_START
        } else {
            DEAD_START
        }
    }

    fn is_live(pid: u32) -> Result<bool, Error> {
        check!(pid == LIVE_PID || pid == DEAD_PID);
        Ok(pid == LIVE_PID || !OWNER_ABANDONED.load(Acquire))
    }

    fn is_live_since(pid: u32, start_time: u64) -> Result<bool, Error> {
        check!(
            (pid == LIVE_PID && start_time == LIVE_START)
                || (pid == DEAD_PID && start_time == DEAD_START)
        );
        Self::is_live(pid)
    }

    fn after_arena_reset() {
        if Self::get_pid() == DEAD_PID {
            OWNER_ABANDONED.store(true, Release);
            abandon_thread();
        }
    }
}

struct Owner {
    cache: CacheRef,
}

fn rotate_and_abandon(owner: &mut Owner) {
    let mut participant = check_ok!(owner.cache.register_participant());
    check!(participant.slot_index() == 1);
    let _ = participant.insert(HASHES[2], ABANDONED_KEY, b"A");
    check!(false, "owner returned before the R4 crash point");
}

#[unsafe(no_mangle)]
extern "C" fn main() -> i32 {
    static STORAGE: <Cfg as StaticParams>::Storage = CacheStorage::new();
    // SAFETY: zeroed static storage, used only through this cache.
    let cache = check_ok!(unsafe { Cache::initialize(STORAGE.as_mut_ptr(), STORAGE.len(), Cfg) });
    let mut survivor = check_ok!(cache.register_participant());
    check!(survivor.slot_index() == 0);

    check_ok!(survivor.insert(HASHES[0], SEED_KEY, b"S"));
    check_ok!(survivor.insert(HASHES[1], FILLER_KEY, b"F"));
    check_ok!(survivor.insert(HASHES[1], FILLER_KEY, b"F"));
    check!(global_epoch(cache).load(Relaxed) == 4);

    CURRENT_PID.store(DEAD_PID, Relaxed);
    let mut owner = Owner { cache };
    scope(|s| {
        let owner_thread = s.spawn(rotate_and_abandon, &mut owner);
        while !OWNER_ABANDONED.load(Acquire) {}
        if cfg!(sgc_genmc) {
            owner_thread.join();
        } else {
            // SAFETY: the native owner is parked forever past its last
            // access to `owner`.
            unsafe { owner_thread.leak() };
        }
    });
    CURRENT_PID.store(LIVE_PID, Relaxed);

    expect_interrupted(cache);
    check_ok!(survivor.insert(HASHES[3], RECOVERED_KEY, RECOVERED_VALUE));
    expect_recovered(cache);
    expect_miss(&mut survivor, HASHES[0], SEED_KEY);
    expect_miss(&mut survivor, HASHES[2], ABANDONED_KEY);
    expect_hit(&mut survivor);
    expect_recovered(cache);
    0
}

fn expect_interrupted(cache: CacheRef) {
    check!(global_epoch(cache).load(Relaxed) == 4);
    let owner = rotation_owner(cache, Relaxed);
    check!(owner.slot() == 1 && owner.registration_id() != 0);
    let old = arena_state(cache, 1, Relaxed);
    check!(old.epoch == 4 && old.sealed && old.bump == 32);
    check!(old.occupancy == Some(1));
    check!(promotion_ready_epoch(cache, 1, Relaxed) == 4);
    let reset = arena_state(cache, 2, Relaxed);
    check!(reset.epoch == 5 && !reset.sealed && reset.bump == 0);
    check!(reset.occupancy == Some(0));
    expect_unpinned(cache);
}

fn expect_recovered(cache: CacheRef) {
    check!(global_epoch(cache).load(Relaxed) == 5);
    check!(rotation_owner(cache, Relaxed).registration_id() == 0);
    let prior = arena_state(cache, 0, Relaxed);
    check!(prior.epoch == 3 && prior.sealed && prior.bump == 32);
    check!(prior.occupancy == Some(1));
    let old = arena_state(cache, 1, Relaxed);
    check!(old.epoch == 4 && old.sealed && old.bump == 32);
    check!(old.occupancy == Some(1));
    let current = arena_state(cache, 2, Relaxed);
    check!(current.epoch == 5 && !current.sealed && current.bump == 32);
    check!(current.occupancy == Some(1));
    expect_unpinned(cache);
}

fn expect_unpinned(cache: CacheRef) {
    for index in 0..2 {
        check!(pinned_epoch(participant(cache, index)).load(Relaxed) == 0);
    }
}

fn expect_miss(participant: &mut Participant, hash: u64, key: &[u8]) {
    let mut output = output_buffer!(1);
    check!(check_ok!(participant.lookup(hash, key, &mut output)).is_none());
}

fn expect_hit(participant: &mut Participant) {
    let mut output = output_buffer!(1);
    let value = check_ok!(participant.lookup(HASHES[3], RECOVERED_KEY, &mut output));
    let value = value.unwrap_or_else(|| genmc_harness::fail("recovered key missed\0"));
    check!(value.len() == 1);
    check!(words_eq(
        output.words(),
        &padded_words::<1>(RECOVERED_VALUE)
    ));
}
