//! A lookup's promotion of an old value never shadows a newer insert of the
//! same key across a rotation: after `insert(K, V1)` and then
//! `insert(K, V2)` by one thread, a later lookup reads V2, whatever a
//! concurrent lookup promoted meanwhile.
//!
//! Every record fills its whole 32-byte arena, so every second insert must
//! rotate first. Setup inserts K -> V1 at epoch 2, filling arena 2. Then,
//! with no ordering constraints:
//!
//! * writer W inserts K -> V2, which rotates to epoch 3 and publishes V2 in
//!   arena 3 (or, if a promotion took arena 3 first, rotates again and
//!   publishes it in arena 4);
//! * lookup L looks K up once.
//!
//! The interleaving of interest crosses a rotation:
//!
//! ```text
//! writer W                          lookup L
//! --------                          --------
//! rotate 2 -> 3
//!                                   read epoch 3
//!                                   probe arena 3: miss
//!                                   probe arena 2: hit V1
//! publish V2 in arena 3
//! (insert returns)
//!                                   promote V1: arena 3 is full
//! ```
//!
//! A promotion that rotated to epoch 4 and published V1 there would shadow
//! V2, which lookups would no longer probe first. A promotion is published
//! only in the epoch its lookup probed first, so this one is dropped.
//! `promotion_keeps_newer_stale_epoch_insert` covers a newer insert that
//! lands in the previous epoch, which promotion still shadows.
//!
//! # Checked after the joins
//!
//! * W's insert succeeded;
//! * L saw exactly V1 or V2;
//! * no rotation owner, both participants unpinned;
//! * a final lookup returns exactly V2.
//!
//! # Witnesses
//!
//! * `STALE_HIT_NOT_PROMOTED`: L saw V1, and its promotion found the current
//!   arena full (`reservation_retry_hook`, called only for L's slot). The
//!   hook adds no synchronisation: L's slot address is written before the
//!   spawns, and the flag only by L and read by main after the joins.
//! * `PROMOTED_BEFORE_INSERT`: L saw V1, and its promotion took the current
//!   arena before W, which then rotated again.
//!
//! No `assume` is used.

#![no_std]
#![no_main]

use core::sync::atomic::Ordering::{Relaxed, SeqCst};

use genmc_harness::{Lent, check, check_ok, padded_words, scope, witness, words_eq};
use shm_gen_cache::test_access::{global_epoch, pinned_epoch, rotation_owner};
use shm_gen_cache::{
    Cache, CacheStorage, Error, GetPid, NoopGetPid, ParticipantLock, ParticipantSlot, StaticParams,
    output_buffer, static_config,
};

static_config! {
    struct Cfg: LookupHooks {
        participant_capacity: 2,
        bucket_count: 8,
        max_key_size: 1,
        max_value_size: 8,
        record_area_size: 32,
        max_occupancy: 8,
        reservation_chunk_size: 8, // exact record accounting
    }
}

type Participant = ParticipantLock<'static, Cfg>;

const HASH: u64 = 42;
const KEY: &[u8] = &[1];
const V1: &[u8; 8] = b"VALUE-01";
const V2: &[u8; 8] = b"VALUE-02";

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
    succeeded: bool,
}

struct Reader<'a> {
    participant: Lent<'a, Participant>,
    observed: Outcome,
}

fn insert(writer: &mut Writer) {
    writer.succeeded = writer.participant.insert(HASH, KEY, V2).is_ok();
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
    check_ok!(p1.insert(HASH, KEY, V1));
    LookupHooks::watch(p2.slot());

    let mut writer = Writer {
        participant: Lent::new(&mut p1),
        succeeded: false,
    };
    let mut reader = Reader {
        participant: Lent::new(&mut p2),
        observed: Outcome::Unset,
    };
    scope(|s| {
        let tw = s.spawn(insert, &mut writer);
        let tl = s.spawn(lookup, &mut reader);
        tw.join();
        tl.join();
    });
    let (inserted, observed) = (writer.succeeded, reader.observed);
    let promotion_found_full = LookupHooks::reader_retried();
    let epoch = global_epoch(cache).load(Relaxed);

    check!(inserted);
    check!(matches!(observed, Outcome::V1 | Outcome::V2));
    check!(rotation_owner(cache, Relaxed).registration_id() == 0);
    check!(pinned_epoch(p1.slot()).load(SeqCst) == 0);
    check!(pinned_epoch(p2.slot()).load(SeqCst) == 0);
    let last = observe(&mut p1);
    check!(last != Outcome::V1, "a promoted V1 shadows V2");
    check!(last == Outcome::V2, "the newer insert reads back");

    witness!(
        "STALE_HIT_NOT_PROMOTED",
        observed == Outcome::V1 && promotion_found_full
    );
    witness!(
        "PROMOTED_BEFORE_INSERT",
        observed == Outcome::V1 && !promotion_found_full && epoch == 4
    );
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

/// `NoopGetPid` plus a reservation-retry hook for L's slot (see the module
/// documentation).
#[derive(Clone, Copy, Debug, Default)]
struct LookupHooks;

/// L's slot; written by main before any spawn, then only read.
static mut READER_SLOT: *const ParticipantSlot = core::ptr::null();
/// Written only by L; read by main after joining L.
static mut READER_RETRIED: bool = false;

impl LookupHooks {
    fn watch(slot: &'static ParticipantSlot) {
        // SAFETY: no other thread exists yet.
        unsafe { READER_SLOT = slot };
    }

    fn reader_retried() -> bool {
        // SAFETY: L, its only writer, has been joined.
        unsafe { READER_RETRIED }
    }

    fn is_reader(slot: &ParticipantSlot) -> bool {
        // SAFETY: written before the workers were spawned.
        core::ptr::eq(slot, unsafe { READER_SLOT })
    }
}

impl GetPid for LookupHooks {
    fn get_pid() -> u32 {
        NoopGetPid::get_pid()
    }
    fn get_start_time() -> u64 {
        NoopGetPid::get_start_time()
    }
    fn is_live(pid: u32) -> Result<bool, Error> {
        NoopGetPid::is_live(pid)
    }
    fn is_live_since(pid: u32, start_time: u64) -> Result<bool, Error> {
        NoopGetPid::is_live_since(pid, start_time)
    }

    fn reservation_retry_hook(slot: &ParticipantSlot) {
        if Self::is_reader(slot) {
            // SAFETY: only L reaches this write.
            unsafe { READER_RETRIED = true };
        }
    }
}
