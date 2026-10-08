//! The real arena probe copying a record while public inserts overwrite
//! exactly the same bytes during arena reuse.
//!
//! K is eight bytes and V0/V1 are sixteen, so every record fills its whole
//! 40-byte record area (header, lengths, key, two value words).
//!
//! Setup (main, one participant): insert K -> V0 twice, publishing epochs 2
//! and 3. Arena 2 keeps its epoch-2 record at offset zero, and epoch 3 is
//! still young enough for an epoch-2 probe to succeed.
//!
//! ```text
//! T1 (writer): insert K -> V1   (rotates to epoch 4)
//!              insert K -> V1   (rotates to epoch 5, reuses arena 2 and
//!                                overwrites the original 40-byte range)
//! T2 (prober): probe arena 2 once, with the fixed epoch-2 stamp
//! ```
//!
//! Matching keys and lengths let stale header/index reads pass the
//! structural checks even if the value copy reads recycled payload words.
//! Every byte of V0 differs from V1, including both corresponding words, so
//! a mixed copy equals neither complete value.
//!
//! Property: a successful probe returns one complete value, V0 or V1, never
//! garbled data. Only value integrity is checked, not whether a complete V1
//! is valid for the old stamp. Miss and retry are allowed; their output is
//! unusable and not inspected. Capacity errors are impossible with the
//! exact-size output buffer. There is no witness.
//!
//! Why setup stops at epoch 3: the epoch-2 stamp is still valid when both
//! workers start. The writer's first insert publishes epoch 4; its second
//! publishes epoch 5 and overwrites the old record. If the probe copies
//! that recycled payload, W3's release fence and D6's acquire fence force V2
//! to observe the newer epoch and reject the copy. Publishing epoch 4 before
//! spawning the prober would instead order epoch 4 before V2 through thread
//! creation: V2 would reject the expired stamp even with the copy fences
//! weakened, making the test vacuous.
//!
//! Read-only checks before and after the race verify arena 2's control
//! word, occupancy and its one record at offset zero (header, lengths, key,
//! payload). Only the writer reserves or rotates, and the direct probe
//! neither pins nor promotes, so both inserts succeed and the final epoch is
//! exactly 5. `test_access` only reaches the real probe and inspects
//! non-concurrent state. No hooks, assumptions, barriers or direct
//! rotations.

#![no_std]
#![no_main]

use core::sync::atomic::Ordering::Relaxed;

use genmc_harness::{Lent, check, check_ok, padded_words, scope, words_eq};
use shm_gen_cache::test_access::{
    ProbeError, arena_state, global_epoch, pinned_epoch, probe, record_area, record_header,
    record_lengths, record_word, rotation_owner,
};
use shm_gen_cache::{
    Cache, CacheStorage, NoopGetPid, ParticipantLock, StaticParams, output_buffer, static_config,
};

static_config! {
    struct Cfg: NoopGetPid {
        participant_capacity: 1,
        bucket_count: 8,
        max_key_size: 8,
        max_value_size: 16,
        record_area_size: 40,
        max_occupancy: 8,
        reservation_chunk_size: 8, // exact record accounting
    }
}

type Participant = ParticipantLock<'static, Cfg>;

const HASH: u64 = 0;
const KEY: &[u8; 8] = b"01234567";
const V0: &[u8; 16] = b"ABCDEFGHIJKLMNOP";
const V1: &[u8; 16] = b"abcdefghijklmnop";
const KEY_WORD: u64 = padded_words::<1>(KEY)[0];
const V0_WORDS: [u64; 2] = padded_words(V0);
const V1_WORDS: [u64; 2] = padded_words(V1);
const _: () = assert!(V0_WORDS[0] != V1_WORDS[0] && V0_WORDS[1] != V1_WORDS[1]);

/// The arena whose epoch-2 record is recycled at epoch 5.
const REUSED_ARENA: u64 = 2;
/// The stamp the prober uses throughout.
const STALE_EPOCH: u64 = 2;

struct Writer<'a> {
    participant: Lent<'a, Participant>,
}

struct Prober {
    cache: Cache<'static, Cfg>,
}

fn insert_twice(writer: &mut Writer) {
    check_ok!(writer.participant.insert(HASH, KEY, V1));
    check_ok!(writer.participant.insert(HASH, KEY, V1));
}

fn probe_stale(prober: &mut Prober) {
    let mut output = output_buffer!(16);
    let capacity = output.capacity();
    match probe(
        prober.cache,
        HASH,
        KEY,
        STALE_EPOCH,
        output.words_mut(),
        capacity,
    ) {
        Ok(lengths) => {
            check!(lengths.key_len() == 8 && lengths.value_len() == 16);
            check!(words_eq(output.words(), &V0_WORDS) || words_eq(output.words(), &V1_WORDS));
        }
        Err(error) => check!(matches!(error, ProbeError::Miss | ProbeError::Retry)),
    }
}

#[unsafe(no_mangle)]
extern "C" fn main() -> i32 {
    static STORAGE: <Cfg as StaticParams>::Storage = CacheStorage::new();
    // SAFETY: zeroed static storage, used only through this cache.
    let cache = check_ok!(unsafe { Cache::initialize(STORAGE.as_mut_ptr(), STORAGE.len(), Cfg) });
    let mut participant = check_ok!(cache.register_participant());
    check_ok!(participant.insert(HASH, KEY, V0));
    expect_state(cache, 2, &V0_WORDS);
    check_ok!(participant.insert(HASH, KEY, V0));
    expect_state(cache, 3, &V0_WORDS);

    let mut writer = Writer {
        participant: Lent::new(&mut participant),
    };
    let mut prober = Prober { cache };
    scope(|s| {
        let t1 = s.spawn(insert_twice, &mut writer);
        let t2 = s.spawn(probe_stale, &mut prober);
        t1.join();
        t2.join();
    });

    expect_state(cache, 5, &V1_WORDS);
    check!(pinned_epoch(participant.slot()).load(Relaxed) == 0);
    0
}

/// Checks, at a quiescent point with global epoch `epoch` (3 or 5), that no
/// rotation is in progress and arena 2 holds exactly one record, K ->
/// `value`, at offset zero, stamped 2 (sealed at epoch 3) or 5.
fn expect_state(cache: Cache<'static, Cfg>, epoch: u64, value: &[u64; 2]) {
    check!(global_epoch(cache).load(Relaxed) == epoch);
    check!(rotation_owner(cache, Relaxed).registration_id() == 0);

    let stamp: u32 = if epoch == 5 { 5 } else { 2 };
    let arena = arena_state(cache, REUSED_ARENA, Relaxed);
    check!(arena.epoch == stamp && arena.sealed == (epoch == 3) && arena.bump == 40);
    check!(arena.occupancy == Some(1));

    // A sole 40-byte record in a 40-byte arena must occupy [0, 40).
    check!(record_area(cache, REUSED_ARENA).1 == 40);
    // Payload length 32: lengths word, 8-byte key, 16-byte value;
    // 8 + align8(32) == 40.
    let header = record_header(cache, REUSED_ARENA, 0, Relaxed);
    check!(header.epoch == stamp && header.len == 32);
    let lengths = record_lengths(cache, REUSED_ARENA, 0, Relaxed);
    check!(lengths.key_len() == 8 && lengths.value_len() == 16);
    check!(record_word(cache, REUSED_ARENA, 16, Relaxed) == KEY_WORD);
    check!(record_word(cache, REUSED_ARENA, 24, Relaxed) == value[0]);
    check!(record_word(cache, REUSED_ARENA, 32, Relaxed) == value[1]);
}
