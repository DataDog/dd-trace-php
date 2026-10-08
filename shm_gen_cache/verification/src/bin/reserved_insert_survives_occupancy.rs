//! Once a public insert has reserved a record, an exceeded occupancy target
//! must not reject it while a table bucket remains available.
//!
//! Related tests cover the neighbouring cases: `competing_rotations` needs
//! space before reservation, `full_table_insert_retries` exhausts the
//! buckets after reservation, and `published_insert_skips_busy_rotation`
//! selects accepted records and checks returning while another owner is
//! still busy. Here acceptance itself is asserted, independently of how the
//! reservation was selected.
//!
//! # Setup
//!
//! Main inserts SEED -> SEED_VALUE into epoch 2, then two workers race:
//!
//! ```text
//! T1: FIRST_KEY  -> FIRST_VALUE
//! T2: SECOND_KEY -> SECOND_VALUE   (symmetric to T1 in the safety build)
//! ```
//!
//! The three hashes have distinct home buckets; keys and values are ten
//! bytes, so each record takes 48 bytes and the 144-byte record area fits
//! all three reservations. `max_occupancy: 2` lets one worker reach the
//! occupancy threshold after the other has reserved but before it publishes
//! in the table. Both must still publish their reserved record in epoch 2,
//! even if rotation has already sealed it. Every rotation is performed by
//! the real public insert; workers use only the public participant API, with
//! no hooks or barriers.
//!
//! # Assumption (selector)
//!
//! After the joins, main assumes that epoch 2's record headers at offsets 48
//! and 96 are published with stamp 2. Every successful reservation publishes
//! its header before its table publication, and only the workers can reserve
//! after the seed, so this proves that both workers reserved there. Bump
//! alone could not select this: a reservation add that finds the arena
//! sealed still advances bump without reserving. The selector says nothing
//! about occupancy, success, the global epoch, current-arena metadata, table
//! entries or lookup results.
//!
//! # Checked (in the selected executions)
//!
//! * both participants are unpinned after the joins;
//! * both inserts succeeded;
//! * global epoch 3, no rotation owner;
//! * old arena (epoch 2): sealed, bump 144 (two successful reservations
//!   cannot be followed by a failed add of either worker), occupancy 3;
//! * new arena (epoch 3): unsealed, bump 0 and occupancy 0, i.e. neither
//!   record was abandoned and retried in the new arena;
//! * exact readback of FIRST then SECOND hits. It promotes both from epoch 2
//!   into epoch 3, whose occupancy reaches 2 and triggers rotation to an
//!   empty epoch 4: global epoch 4, no owner, epoch 3 sealed with bump 96 and
//!   occupancy 2, epoch 4 unsealed and empty; both participants unpinned.
//!
//! This is conditional publication safety, not a claim that every schedule
//! reserves both records in epoch 2. Metadata is observed through
//! `test_access` only after the joins.
//!
//! # Witness
//!
//! `RESERVED_PUBLICATION` follows every check and proves that the selector
//! is satisfiable (both old reservations are reachable). There are no
//! witnesses merely distinguishing the symmetric winners.
//!
//! # Native runs and limits
//!
//! Native `assume()` is a no-op, so native runs skip the conditional
//! property when the selector is false; native success alone does not
//! establish reachability. Concurrent rotation is only epoch 2 -> 3, and
//! readback runs after both participants are unpinned, so
//! pinning-prevents-rotation and death/slot reaping are out of scope. The
//! safety build marks T1/T2 symmetric: exchanging their
//! participant/key/value tuples preserves the selector, both exact readbacks
//! and the aggregate metadata. Witness builds spawn ordinarily.

#![no_std]
#![no_main]

use core::sync::atomic::Ordering::{Relaxed, SeqCst};

use genmc_harness::{Lent, assume, check, check_ok, padded_words, scope, witness, words_eq};
use shm_gen_cache::test_access::{
    ArenaState, arena_state, distinct_bucket_hashes, global_epoch, pinned_epoch, record_header,
    rotation_owner,
};
use shm_gen_cache::{
    Cache, CacheStorage, NoopGetPid, ParticipantLock, StaticParams, output_buffer, static_config,
};

const BUCKET_COUNT: u32 = 8;

static_config! {
    struct Cfg: NoopGetPid {
        participant_capacity: 2,
        bucket_count: BUCKET_COUNT,
        max_key_size: 10,
        max_value_size: 10,
        record_area_size: 144,
        max_occupancy: 2,
        reservation_chunk_size: 8, // exact record accounting
    }
}

type Participant = ParticipantLock<'static, Cfg>;
type Bytes = [u8; 10];

const SEED_KEY: &Bytes = b"01234567SS";
const FIRST_KEY: &Bytes = b"01234567AA";
const SECOND_KEY: &Bytes = b"01234567BB";
const SEED_VALUE: &Bytes = b"0000000000";
const FIRST_VALUE: &Bytes = b"ABCDEFGHIJ";
const SECOND_VALUE: &Bytes = b"abcdefghij";

const HASHES: [u64; 3] = distinct_bucket_hashes::<3>(BUCKET_COUNT);
const SEED_HASH: u64 = HASHES[0];
const FIRST_HASH: u64 = HASHES[1];
const SECOND_HASH: u64 = HASHES[2];

/// Arena `epoch % 3` of the epochs observed below.
const ARENA_OF_EPOCH_2: u64 = 2;
const ARENA_OF_EPOCH_3: u64 = 0;
const ARENA_OF_EPOCH_4: u64 = 1;

/// Offsets of the two worker records in epoch 2 (after the 48-byte seed).
const WORKER_RECORD_OFFSETS: [usize; 2] = [48, 96];

struct Insert<'a> {
    participant: Lent<'a, Participant>,
    hash: u64,
    key: &'static Bytes,
    value: &'static Bytes,
    succeeded: bool,
}

fn insert(op: &mut Insert) {
    op.succeeded = op.participant.insert(op.hash, op.key, op.value).is_ok();
}

#[unsafe(no_mangle)]
extern "C" fn main() -> i32 {
    static STORAGE: <Cfg as StaticParams>::Storage = CacheStorage::new();
    // SAFETY: zeroed static storage, used only through this cache.
    let cache = check_ok!(unsafe { Cache::initialize(STORAGE.as_mut_ptr(), STORAGE.len(), Cfg) });
    let mut p1 = check_ok!(cache.register_participant());
    let mut p2 = check_ok!(cache.register_participant());
    check_ok!(p1.insert(SEED_HASH, SEED_KEY, SEED_VALUE));

    let mut t1 = Insert {
        participant: Lent::new(&mut p1),
        hash: FIRST_HASH,
        key: FIRST_KEY,
        value: FIRST_VALUE,
        succeeded: false,
    };
    let mut t2 = Insert {
        participant: Lent::new(&mut p2),
        hash: SECOND_HASH,
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

    check!(is_unpinned(&p1) && is_unpinned(&p2));
    let selected = both_reserved_in_epoch_2(cache);
    assume(selected);
    if !selected {
        return 0;
    }

    check!(both_succeeded);
    check!(global_epoch(cache).load(Relaxed) == 3);
    check!(rotation_owner(cache, Relaxed).registration_id() == 0);
    check!(arena(cache, ARENA_OF_EPOCH_2) == sealed(2, 144, 3));
    check!(arena(cache, ARENA_OF_EPOCH_3) == open(3));

    expect_lookup(&mut p1, FIRST_HASH, FIRST_KEY, FIRST_VALUE);
    expect_lookup(&mut p2, SECOND_HASH, SECOND_KEY, SECOND_VALUE);
    check!(global_epoch(cache).load(Relaxed) == 4);
    check!(rotation_owner(cache, Relaxed).registration_id() == 0);
    check!(arena(cache, ARENA_OF_EPOCH_3) == sealed(3, 96, 2));
    check!(arena(cache, ARENA_OF_EPOCH_4) == open(4));
    check!(is_unpinned(&p1) && is_unpinned(&p2));

    witness!("RESERVED_PUBLICATION", selected);
    0
}

/// The selector: epoch 2's worker record headers carry epoch stamp 2.
fn both_reserved_in_epoch_2(cache: Cache<'_, Cfg>) -> bool {
    WORKER_RECORD_OFFSETS
        .iter()
        .all(|&offset| record_header(cache, ARENA_OF_EPOCH_2, offset, Relaxed).epoch == 2)
}

fn expect_lookup(participant: &mut Participant, hash: u64, key: &Bytes, expected: &Bytes) {
    let mut output = output_buffer!(10);
    let hit = check_ok!(check_ok!(participant.lookup(hash, key, &mut output)));
    let (hit_start, hit_len) = (hit.as_ptr(), hit.len());
    check!(hit_len == output.capacity());
    check!(hit_start == output.words().as_ptr().cast::<u8>());
    check!(words_eq(output.words(), &padded_words::<2>(expected)));
}

fn is_unpinned(participant: &Participant) -> bool {
    pinned_epoch(participant.slot()).load(SeqCst) == 0
}

fn arena(cache: Cache<'_, Cfg>, index: u64) -> ArenaState {
    arena_state(cache, index, Relaxed)
}

const fn sealed(epoch: u32, bump: u32, occupancy: u32) -> ArenaState {
    ArenaState {
        epoch,
        sealed: true,
        bump,
        occupancy: Some(occupancy),
    }
}

/// A fresh, empty, unsealed incarnation.
const fn open(epoch: u32) -> ArenaState {
    ArenaState {
        epoch,
        sealed: false,
        bump: 0,
        occupancy: Some(0),
    }
}
