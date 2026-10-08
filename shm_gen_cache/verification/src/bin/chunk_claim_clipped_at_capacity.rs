//! A chunk claim that crosses the end of the record area is clipped to it,
//! so later tail writes stay inside the arena.
//!
//! `reserve()` picks a chunk-sized claim from a relaxed precheck of the
//! arena bump. Another claim can land between that precheck and the
//! fetch-add, so a claim may still succeed for its record while its chunk
//! extends past the record area. Tail consumption checks only the chunk's
//! own `[cursor, end)`, never the capacity: clipping `end` to the record
//! area is what keeps later tail writes inside it.
//!
//! Setup: records are 32 bytes, chunks 160 and the record area 192. Two
//! symmetric writers each insert two distinct keys (in distinct buckets)
//! through the public API, starting in epoch 2:
//!
//! ```text
//! T1: A, then B
//! T2: C, then D      (symmetric to T1 in the safety build)
//! ```
//!
//! If both prechecks see bump 0, both claim 160 bytes: one chunk is
//! `[0, 160)`, the other starts at 160 and keeps only `[160, 192)` after
//! clipping. That writer's second record must not use the clipped tail; it
//! rotates and reserves in epoch 3. Unclipped, the tail would be
//! `[192, 320)` and the second record would land past arena 2's record
//! area. Nothing else would refuse that write (record placement does not
//! check bounds). Arena 2 is the last member of the cache, so a canary
//! placed directly after the cache storage catches such a write. The
//! storage is a plain byte array of exactly the mapping size, not a
//! `CacheStorage`: that type is 128-byte aligned, so with 64-byte cache
//! lines its size, and with it the canary's offset, can be rounded up past
//! the end of the mapping.
//!
//! Arena 0, the only arena rotation can reuse here, was never written, and
//! epoch 3 fits every record that can still reach it. Hence the epoch ends
//! at 2 or 3, and no arena-reuse timeout or second rotation is possible. A
//! writer that sees arena 2 sealed while the other owns rotation may still
//! get `RotationOwnerTimeout`.
//!
//! Checked after the joins:
//!
//! * the canary is intact;
//! * both participants are unpinned and their chunk ends lie within the
//!   record area;
//! * the epoch is 2 or 3, rotation ownership is released, and arena 2
//!   still holds epoch 2, sealed exactly if the epoch advanced;
//! * every insert succeeded or timed out waiting for rotation ownership,
//!   and each successful record reads back exactly from the current or
//!   previous arena. Readback probes the arenas directly
//!   (`test_access::probe`): a public lookup would promote epoch-2
//!   records, which could fill epoch 3 and rotate the originals away.
//!
//! Witness `CLIPPED_CHUNK`: arena 2's bump reaches 320. Without the race,
//! the second claimant's precheck sees bump 160 and claims exactly its
//! 32-byte record. No later add can reach a sealed or full arena 2: the
//! clipped writer rotates after a precheck failure and the other writer
//! uses its own tail. So bump 320 occurs exactly when a successful claim
//! was clipped.

#![no_std]
#![no_main]

use core::cell::UnsafeCell;
use core::sync::atomic::AtomicU64;
use core::sync::atomic::Ordering::{Relaxed, SeqCst};

use genmc_harness::{Lent, check, check_ok, padded_words, scope, witness};
use shm_gen_cache::test_access::{
    ARENAS, FIRST_EPOCH, ProbeError, arena_state, chunk, distinct_bucket_hashes, global_epoch,
    pinned_epoch, probe, record_area, rotation_owner,
};
use shm_gen_cache::{
    Cache, Error, NoopGetPid, ParticipantLock, StaticParams, output_buffer, static_config,
};

const BUCKET_COUNT: u32 = 8;
const RECORD_AREA_SIZE: u32 = 192;

static_config! {
    struct Cfg: NoopGetPid {
        participant_capacity: 2,
        bucket_count: BUCKET_COUNT,
        max_key_size: 1,
        max_value_size: 8,
        record_area_size: RECORD_AREA_SIZE,
        max_occupancy: 8,
        reservation_chunk_size: 160,
    }
}

type Participant = ParticipantLock<'static, Cfg>;

/// The arena of the initial epoch: the last member of the cache.
const FIRST_ARENA: u64 = FIRST_EPOCH % ARENAS;
/// Bump of arena 2 once a 160-byte claim landed at 160.
const CLIPPED_BUMP: u32 = 320;

const CANARY: u64 = 0xa5a5_a5a5_a5a5_a5a5;

/// The cache storage, immediately followed by a canary (the mapping size
/// is a multiple of 8, so there is no padding in between).
#[repr(C, align(128))]
struct Layout {
    storage: UnsafeCell<[u8; MAPPING_SIZE]>,
    canary: [AtomicU64; 8],
}

const MAPPING_SIZE: usize = <Cfg as StaticParams>::DERIVED.mapping_size();

// SAFETY: the storage is only accessed through the cache (as for
// `CacheStorage`); the canary is atomic.
unsafe impl Sync for Layout {}

struct Record {
    hash: u64,
    key: [u8; 1],
    value: &'static [u8; 8],
}

const HASHES: [u64; 4] = distinct_bucket_hashes(BUCKET_COUNT);

/// Writer `i` inserts `RECORDS[2 * i]`, then `RECORDS[2 * i + 1]`.
static RECORDS: [Record; 4] = [
    Record {
        hash: HASHES[0],
        key: [1],
        value: b"AAAAAAAA",
    },
    Record {
        hash: HASHES[1],
        key: [2],
        value: b"BBBBBBBB",
    },
    Record {
        hash: HASHES[2],
        key: [3],
        value: b"CCCCCCCC",
    },
    Record {
        hash: HASHES[3],
        key: [4],
        value: b"DDDDDDDD",
    },
];

#[derive(Clone, Copy, PartialEq, Eq)]
enum Outcome {
    Unset,
    Success,
    OwnerTimeout,
    Other,
}

struct Writer<'a> {
    participant: Lent<'a, Participant>,
    records: &'static [Record],
    results: [Outcome; 2],
}

fn write_records(writer: &mut Writer) {
    for i in 0..writer.results.len() {
        let r = &writer.records[i];
        writer.results[i] = match writer.participant.insert(r.hash, &r.key, r.value) {
            Ok(()) => Outcome::Success,
            Err(Error::RotationOwnerTimeout) => Outcome::OwnerTimeout,
            Err(_) => Outcome::Other,
        };
    }
}

#[unsafe(no_mangle)]
extern "C" fn main() -> i32 {
    static MEMORY: Layout = Layout {
        storage: UnsafeCell::new([0; MAPPING_SIZE]),
        canary: [const { AtomicU64::new(CANARY) }; 8],
    };
    let storage = MEMORY.storage.get().cast::<u8>();
    // SAFETY: zeroed static storage, used only through this cache.
    let cache = check_ok!(unsafe { Cache::initialize(storage, MAPPING_SIZE, Cfg) });
    let (records, size) = record_area(cache, FIRST_ARENA);
    check!(records.wrapping_add(size) == MEMORY.canary.as_ptr().cast::<u8>());

    let mut p1 = check_ok!(cache.register_participant());
    let mut p2 = check_ok!(cache.register_participant());
    let slots = [p1.slot(), p2.slot()];
    let mut w1 = Writer {
        participant: Lent::new(&mut p1),
        records: &RECORDS[0..2],
        results: [Outcome::Unset; 2],
    };
    let mut w2 = Writer {
        participant: Lent::new(&mut p2),
        records: &RECORDS[2..4],
        results: [Outcome::Unset; 2],
    };
    scope(|s| {
        let first = s.spawn(write_records, &mut w1);
        let second = s.spawn_symmetric(write_records, &mut w2, &first);
        first.join();
        second.join();
    });

    for word in &MEMORY.canary {
        check!(word.load(Relaxed) == CANARY);
    }
    for slot in slots {
        check!(pinned_epoch(slot).load(SeqCst) == 0);
        check!(chunk(slot).2.load(SeqCst) <= RECORD_AREA_SIZE);
    }
    expect_state(cache);
    for writer in [&w1, &w2] {
        expect_records(cache, writer);
    }

    let clipped = arena_state(cache, FIRST_ARENA, Relaxed).bump == CLIPPED_BUMP;
    witness!("CLIPPED_CHUNK", clipped);
    0
}

/// At most one rotation happened, and it completed.
fn expect_state(cache: Cache<'_, Cfg>) {
    let e = global_epoch(cache).load(SeqCst);
    check!(e == FIRST_EPOCH || e == FIRST_EPOCH + 1);
    check!(rotation_owner(cache, SeqCst).registration_id() == 0);
    let old = arena_state(cache, FIRST_ARENA, Relaxed);
    check!(old.epoch as u64 == FIRST_EPOCH);
    check!(old.sealed == (e != FIRST_EPOCH));
}

/// Every insert succeeded or timed out on rotation ownership; successful
/// records are readable.
fn expect_records(cache: Cache<'_, Cfg>, writer: &Writer) {
    for (r, &result) in writer.records.iter().zip(&writer.results) {
        check!(matches!(result, Outcome::Success | Outcome::OwnerTimeout));
        if result == Outcome::Success {
            check!(holds(cache, r));
        }
    }
}

/// Whether the current or the previous arena holds exactly `r` (lookup's
/// probe order, without promotion).
fn holds(cache: Cache<'_, Cfg>, r: &Record) -> bool {
    let e = global_epoch(cache).load(SeqCst);
    for epoch in [e, e - 1] {
        let mut output = output_buffer!(8);
        let capacity = output.capacity();
        match probe(cache, r.hash, &r.key, epoch, output.words_mut(), capacity) {
            Ok(lengths) => {
                return lengths.value_len() == 8
                    && output.words()[0] == padded_words::<1>(r.value)[0];
            }
            Err(error) => check!(error == ProbeError::Miss),
        }
    }
    false
}
