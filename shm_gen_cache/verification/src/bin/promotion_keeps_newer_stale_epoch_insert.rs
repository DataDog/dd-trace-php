//! A lookup's promotion of an old value can shadow a newer insert of the
//! same key that landed in the previous epoch. This program pins down that
//! behaviour: after `insert(K, V1)` and then `insert(K, V2)` by one thread,
//! a later lookup may read V1, if a rotation overtook the second insert and
//! a concurrent lookup promoted V1.
//!
//! The record area holds two records. Setup inserts K -> V1 at epoch 2.
//! Then, with no ordering constraints:
//!
//! * writer W inserts K -> V2;
//! * rotator R rotates from epoch 2 to 3 (`test_access::rotate()`), which
//!   an epoch-2 pin does not block;
//! * lookup L looks K up once.
//!
//! The interleaving that yields V1:
//!
//! ```text
//! writer W               rotator R            lookup L
//! --------               ---------            --------
//! pin epoch 2, reserve
//!                        rotate 2 -> 3
//!                                             read epoch 3
//!                                             probe arena 3: miss
//!                                             probe arena 2: hit V1
//! publish V2 in arena 2
//! (insert returns)
//!                                             promote V1 into arena 3
//! ```
//!
//! Lookups probe epoch 3 first, so the promoted V1 shadows V2. Unlike the
//! rotation in `promotion_keeps_newer_insert`, publishing only in the epoch
//! the lookup probed first does not prevent it: V2 is in the previous one.
//!
//! # Checked after the joins
//!
//! * W's insert succeeded, or timed out on rotation ownership (R sealed
//!   arena 2 before W reserved there, and W's own rotation found R owning
//!   it); R's rotation succeeded, or found that W had rotated first;
//! * L saw exactly V1 or V2;
//! * epoch 3, no rotation owner, all participants unpinned;
//! * a final lookup returns exactly V1 or V2, and V1 if W's insert failed.
//!
//! # Witnesses
//!
//! * `FINAL_V1`: W's insert succeeded, yet the final lookup reads V1 (the
//!   shadowing above);
//! * `FINAL_V2`: the final lookup reads V2.
//!
//! No `assume` is used.

#![no_std]
#![no_main]

use core::sync::atomic::Ordering::{Relaxed, SeqCst};

use genmc_harness::{Lent, check, check_ok, padded_words, scope, witness, words_eq};
use shm_gen_cache::test_access::{
    RotationPolicy, global_epoch, pinned_epoch, rotate, rotation_owner,
};
use shm_gen_cache::{
    Cache, CacheStorage, Error, NoopGetPid, ParticipantLock, StaticParams, output_buffer,
    static_config,
};

static_config! {
    struct Cfg: NoopGetPid {
        participant_capacity: 3,
        bucket_count: 8,
        max_key_size: 1,
        max_value_size: 8,
        record_area_size: 64,
        max_occupancy: 8,
        reservation_chunk_size: 8, // exact record accounting
    }
}

type Participant = ParticipantLock<'static, Cfg>;
type CacheRef = Cache<'static, Cfg>;

const HASH: u64 = 42;
const KEY: &[u8] = &[1];
const V1: &[u8; 8] = b"VALUE-01";
const V2: &[u8; 8] = b"VALUE-02";
const FIRST_EPOCH: u64 = 2;

#[derive(Clone, Copy, PartialEq, Eq, Debug)]
enum Outcome {
    Unset,
    LookupError,
    Miss,
    V1,
    V2,
    Other,
}

struct Writer<'a> {
    participant: Lent<'a, Participant>,
    result: Result<(), Error>,
}

struct Rotator {
    cache: CacheRef,
    slot_index: u32,
    result: Result<(), Error>,
}

struct Reader<'a> {
    participant: Lent<'a, Participant>,
    observed: Outcome,
}

fn insert(writer: &mut Writer) {
    writer.result = writer.participant.insert(HASH, KEY, V2);
}

fn rotate_once(rotator: &mut Rotator) {
    let policy = RotationPolicy::WaitForOwner;
    rotator.result = rotate(rotator.cache, rotator.slot_index, FIRST_EPOCH, policy);
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
    let p3 = check_ok!(cache.register_participant());
    check_ok!(p1.insert(HASH, KEY, V1));
    check!(global_epoch(cache).load(Relaxed) == FIRST_EPOCH);

    let mut writer = Writer {
        participant: Lent::new(&mut p1),
        result: Err(Error::Corrupt),
    };
    let mut rotator = Rotator {
        cache,
        slot_index: p3.slot_index(),
        result: Err(Error::Corrupt),
    };
    let mut reader = Reader {
        participant: Lent::new(&mut p2),
        observed: Outcome::Unset,
    };
    scope(|s| {
        let tw = s.spawn(insert, &mut writer);
        let tr = s.spawn(rotate_once, &mut rotator);
        let tl = s.spawn(lookup, &mut reader);
        tw.join();
        tr.join();
        tl.join();
    });
    let (inserted, rotated, observed) = (writer.result, rotator.result, reader.observed);

    check!(matches!(
        inserted,
        Ok(()) | Err(Error::RotationOwnerTimeout)
    ));
    check!(matches!(rotated, Ok(()) | Err(Error::ConcurrentOperation)));
    check!(matches!(observed, Outcome::V1 | Outcome::V2));
    check!(global_epoch(cache).load(Relaxed) == FIRST_EPOCH + 1);
    check!(rotation_owner(cache, Relaxed).registration_id() == 0);
    for slot in [p1.slot(), p2.slot(), p3.slot()] {
        check!(pinned_epoch(slot).load(SeqCst) == 0);
    }
    let last = observe(&mut p1);
    check!(matches!(last, Outcome::V1 | Outcome::V2));
    check!(inserted.is_ok() || last == Outcome::V1);

    witness!("FINAL_V1", inserted.is_ok() && last == Outcome::V1);
    witness!("FINAL_V2", last == Outcome::V2);
    0
}

fn observe(participant: &mut Participant) -> Outcome {
    let mut output = output_buffer!(8);
    let len = match participant.lookup(HASH, KEY, &mut output) {
        Err(_) => return Outcome::LookupError,
        Ok(None) => return Outcome::Miss,
        Ok(Some(value)) => value.len(),
    };
    if len != V1.len() {
        Outcome::Other
    } else if words_eq(output.words(), &padded_words::<1>(V1)) {
        Outcome::V1
    } else if words_eq(output.words(), &padded_words::<1>(V2)) {
        Outcome::V2
    } else {
        Outcome::Other
    }
}
