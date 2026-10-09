//! A registrar and a rotation owner can both try to reap the same dead
//! participant slot. Neither a stale reaping attempt nor losing the reaping
//! race may invalidate a replacement registration or undo published stores.
//! Both sides of that invariant are checked through public registration,
//! insertion and lookup operations.
//!
//! # Setup
//!
//! All three slots are taken: slot 0 by the abandoned registration of PID 1
//! (dead), pinned in epoch 2; slots 1 and 2 by the live (PID 2) workers A and
//! B. Registration must therefore reap slot 0, and rotation must clear its
//! pin before reusing arena 2. The `StagedDeath` backend reports PID 1 dead
//! and PID 2 live without synchronising anything.
//!
//! Main inserts the same key S seven times. Each record takes 32 bytes of a
//! 96-byte arena, so these replacements fill epochs 2 and 3 and leave epoch 4
//! (arena 1) with one record. Then, concurrently:
//!
//! ```text
//! A: insert KEYS[1]
//! B: insert KEYS[2]
//! R: observe arena 1's bump; register_participant(); join A and B; verify
//! ```
//!
//! The four keys have pairwise distinct home buckets in the eight-bucket
//! table, so S, A's and B's keys fit. The occupancy target is two, so both
//! new publications request maintenance rotation: one insertion can own
//! rotation while the other skips busy ownership. R's registration has to
//! reap slot 0, possibly overlapping the rotation owner's scan of the same
//! slot. If R wins and registers in slot 0, it keeps the lock (unpinned)
//! until both workers finish: it does not block arena reuse, but a stale
//! rotation scan that wrongly cleared it would be exposed. Rotation ownership
//! serialises the two workers' scans.
//!
//! # Assumptions
//!
//! To keep both reservations in epoch 4, R first relaxed-loads arena 1's
//! control word and assumes its bump is 96. A reservation add that finds the
//! arena sealed still advances bump without reserving, so after joining the
//! workers R also assumes that the record headers at offsets 32 and 64 of
//! arena 1 are published with stamp 4. Every successful reservation
//! publishes its header, and an add that fails on a sealed or full arena
//! leaves it so; together these select the executions in which both
//! reservations precede R's bump observation. They do not select the
//! publication order, which reaper succeeds, or the final epoch. Natively
//! `assume` is a no-op: if the observations differ, R (and then main) skip
//! the focused scenario; those attempts may need other work to reserve.
//!
//! # Properties
//!
//! The selected race permits these outcomes before public readback:
//!
//! ```text
//! registration result        epoch    A and B
//! -------------------------  -------  ------------
//! success                    4 or 5   both succeed
//! ParticipantRegistryFull    5        both succeed
//! ```
//!
//! Both insertions were accepted before maintenance, so a rotation failure
//! cannot turn either into an error. Registration claims a slot while
//! rotation advances the epoch, so both can succeed. A rotator that still
//! sees the dead pin when its wait ends, then finds R's live claim in slot 0
//! (or loses the takeover to R), abandons its attempt and leaves epoch 4
//! sealed; one that observes the pin cleared by R can finish and advance to
//! epoch 5. A full registry means a rotation owner took slot 0 over (and
//! released it); with no old blocker left it must also finish rotation, so
//! registry-full with epoch 4 is forbidden.
//!
//! After the joins, epoch 4 retains all three records at bump 96, every pin
//! and the rotation owner are clear, and an epoch-5 current arena is still
//! empty. R saves the epoch before lookups can change it. A successful
//! replacement must still own slot 0 with its original registration ID;
//! after registry-full, slot 0 must be free and a quiescent retry of the
//! registration must succeed (in slot 0).
//!
//! Exact lookups of A's and B's keys run before inserting a fresh key C, so
//! recovery cannot age either value out first. At epoch 4 they are direct
//! hits and C advances to epoch 5. At epoch 5 both lookups promote; the
//! second advances to epoch 6, where C is inserted. Exact readback of C and
//! the final metadata then check continued use.
//!
//! # Witnesses
//!
//! After every safety and recovery check, `COMPETING_SLOT_REAPING` asks
//! whether the initial registration can report full, and
//! `PUBLISHED_WITH_DEFERRED_ROTATION` whether it can succeed with the epoch
//! still at the saved 4. These are public-outcome witnesses, not
//! observations of particular internal reaping steps. The replacement is
//! unpinned during the race: this checks the survival of its claim, not
//! reuse protection for a concurrently active replacement reservation.

#![no_std]
#![no_main]

use core::sync::atomic::Ordering::{Relaxed, Release, SeqCst};
use core::sync::atomic::{AtomicBool, AtomicU32};

use genmc_harness::{Lent, Thread, assume, check, check_ok, scope, witness};
use shm_gen_cache::test_access::{
    arena_ctl, arena_ctl_word, arena_exact_occupancy, distinct_bucket_hashes, encode_ctl,
    global_epoch, is_free, participant, pinned_epoch, record_header, registration_id,
    rotation_owner,
};
use shm_gen_cache::{
    Cache, CacheStorage, Error, GetPid, ParticipantLock, ParticipantSlot, StaticParams,
    output_buffer, static_config,
};

const BUCKET_COUNT: u32 = 8;

static_config! {
    struct Cfg: StagedDeath {
        participant_capacity: 3,
        bucket_count: BUCKET_COUNT,
        max_key_size: 1,
        max_value_size: 1,
        record_area_size: 96,
        max_occupancy: 2,
        reservation_chunk_size: 8, // exact record accounting
    }
}

type Participant = ParticipantLock<'static, Cfg>;
type CacheRef = Cache<'static, Cfg>;

/// S (setup), A's, B's and the recovery key C, with pairwise distinct home
/// buckets.
const KEYS: [[u8; 1]; 4] = [[0], [1], [2], [3]];
const VALUES: [[u8; 1]; 4] = [[10], [11], [12], [13]];
const HASHES: [u64; 4] = distinct_bucket_hashes(BUCKET_COUNT);
const SETUP: usize = 0;
const RECOVERY: usize = 3;

/// The arena of epochs 1, 4, 7, ...: the current arena when the race starts.
const EPOCH_FOUR_ARENA: u64 = 1;
/// The arena of epochs 2, 5, ...: the one the dead participant pins.
const EPOCH_FIVE_ARENA: u64 = 2;

const DEAD_PID: u32 = 1;
const LIVE_PID: u32 = 2;
/// The epoch the dead participant stays pinned to.
const DEAD_PIN: u64 = 2;

/// Liveness backend: PID 1 is dead, PID 2 live. Only setup changes the
/// caller's PID; every thread of the race runs as PID 2.
struct StagedDeath;

static CALLER: AtomicU32 = AtomicU32::new(DEAD_PID);

impl GetPid for StagedDeath {
    fn get_pid() -> u32 {
        CALLER.load(Relaxed)
    }

    fn get_start_time() -> u64 {
        100 + Self::get_pid() as u64
    }

    fn is_live(pid: u32) -> Result<bool, Error> {
        check!(pid == DEAD_PID || pid == LIVE_PID);
        Ok(pid == LIVE_PID)
    }

    fn is_live_since(pid: u32, _start_time: u64) -> Result<bool, Error> {
        // A concurrent replacement can pair an old PID with the new start
        // time. No PID's liveness changes here, so ignore the time.
        Self::is_live(pid)
    }
}

/// Worker A or B.
struct Writer<'a> {
    participant: Lent<'a, Participant>,
    key: usize,
    succeeded: &'a AtomicBool,
}

/// Registrar R's inputs and results. `F` is the writers' thread function.
struct Registrar<'a, F> {
    cache: CacheRef,
    dead: &'static ParticipantSlot,
    dead_registration: u32,
    /// A and B, joined by R while it holds its registration.
    writers: Option<[Thread<'a, F>; 2]>,
    writers_succeeded: &'a [AtomicBool; 2],
    selected: bool,
    registry_full: bool,
    saved_epoch: u64,
}

fn insert(writer: &mut Writer) {
    let key = writer.key;
    let result = writer
        .participant
        .insert(HASHES[key], &KEYS[key], &VALUES[key]);
    writer.succeeded.store(result.is_ok(), Relaxed);
}

fn register_and_verify<F>(r: &mut Registrar<'_, F>) {
    r.selected = both_reserved_in_epoch_four(r.cache);
    assume(r.selected);
    if !r.selected {
        r.join_writers();
        return;
    }

    let registered = r.cache.register_participant();
    r.registry_full = registered.is_err();
    let initial_registration = match &registered {
        Ok(lock) => registration_id(lock.slot()),
        Err(error) => {
            check!(*error == Error::ParticipantRegistryFull);
            0
        }
    };
    r.join_writers();

    r.selected = both_published_in_epoch_four(r.cache);
    assume(r.selected);
    if !r.selected {
        return;
    }
    check!(r.writers_succeeded[0].load(Relaxed) && r.writers_succeeded[1].load(Relaxed));
    r.saved_epoch = check_after_race(r.cache, r.registry_full);
    match registered {
        Ok(mut replacement) => r.verify_replacement(&mut replacement, initial_registration),
        Err(_) => {
            let mut retried = check_ok!(r.cache.register_participant());
            r.verify_replacement(&mut retried, 0);
        }
    }
}

impl<F> Registrar<'_, F> {
    fn join_writers(&mut self) {
        if let Some(threads) = self.writers.take() {
            for thread in threads {
                thread.join();
            }
        }
    }

    /// `initial_registration` is 0 for a registration retried after
    /// registry-full.
    fn verify_replacement(&self, replacement: &mut Participant, initial_registration: u32) {
        let slot = replacement.slot();
        check!(core::ptr::eq(slot, self.dead));
        let registration = registration_id(slot);
        check!(registration != 0 && registration != self.dead_registration);
        check!(initial_registration == 0 || registration == initial_registration);
        check!(pinned_epoch(slot).load(SeqCst) == 0);

        // Do not insert C before these reads: promotion may age epoch 4 out.
        check_lookup(replacement, 1);
        check_lookup(replacement, 2);
        check_ok!(replacement.insert(HASHES[RECOVERY], &KEYS[RECOVERY], &VALUES[RECOVERY]));
        check_lookup(replacement, RECOVERY);
        check_final(self.cache, self.saved_epoch);
        check!(registration_id(slot) == registration);
    }
}

#[unsafe(no_mangle)]
extern "C" fn main() -> i32 {
    static STORAGE: <Cfg as StaticParams>::Storage = CacheStorage::new();
    // SAFETY: zeroed static storage, used only through this cache.
    let cache = check_ok!(unsafe { Cache::initialize(STORAGE.as_mut_ptr(), STORAGE.len(), Cfg) });

    // The dead owner never runs its lock's drop: production reaping owns the
    // cleanup, so the registration is leaked. No worker receives it.
    let abandoned = check_ok!(cache.register_participant());
    let dead = abandoned.slot();
    core::mem::forget(abandoned);
    let dead_registration = registration_id(dead);
    pinned_epoch(dead).store(DEAD_PIN, Release);
    CALLER.store(LIVE_PID, Relaxed);

    let mut first = check_ok!(cache.register_participant());
    let mut second = check_ok!(cache.register_participant());
    for _ in 0..7 {
        check_ok!(first.insert(HASHES[SETUP], &KEYS[SETUP], &VALUES[SETUP]));
    }
    check_setup(cache);

    let (first_slot, second_slot) = (first.slot(), second.slot());
    let succeeded = [AtomicBool::new(false), AtomicBool::new(false)];
    let mut a = Writer {
        participant: Lent::new(&mut first),
        key: 1,
        succeeded: &succeeded[0],
    };
    let mut b = Writer {
        participant: Lent::new(&mut second),
        key: 2,
        succeeded: &succeeded[1],
    };
    // R keeps its replacement registration while joining A and B; main
    // joins only R. R's argument holds A's and B's thread handles, so it is
    // built inside the writers' scope and lent to R from a nested one.
    let (selected, registry_full, saved_epoch) = scope(|writers| {
        let mut registrar = Registrar {
            cache,
            dead,
            dead_registration,
            writers: Some([writers.spawn(insert, &mut a), writers.spawn(insert, &mut b)]),
            writers_succeeded: &succeeded,
            selected: false,
            registry_full: false,
            saved_epoch: 0,
        };
        scope(|s| s.spawn(register_and_verify, &mut registrar).join());
        let Registrar {
            selected,
            registry_full,
            saved_epoch,
            ..
        } = registrar;
        (selected, registry_full, saved_epoch)
    });

    check!(pinned_epoch(first_slot).load(SeqCst) == 0);
    check!(pinned_epoch(second_slot).load(SeqCst) == 0);
    // GenMC already pruned the unselected executions in R; natively
    // assume() is a no-op, so skip the scenario explicitly.
    if !selected {
        return 0;
    }
    check!(succeeded[0].load(Relaxed) && succeeded[1].load(Relaxed));
    check_replacement_released(cache);

    witness!("COMPETING_SLOT_REAPING", registry_full);
    witness!(
        "PUBLISHED_WITH_DEFERRED_ROTATION",
        !registry_full && saved_epoch == 4
    );
    0
}

/// The exact value of key `index` is a hit written into the caller's buffer.
fn check_lookup(participant: &mut Participant, index: usize) {
    let mut output = output_buffer!(1);
    let output_start = output.bytes().as_ptr();
    let capacity = output.capacity();
    let (value_start, value_len) =
        match participant.lookup(HASHES[index], &KEYS[index], &mut output) {
            Ok(Some(value)) => (value.as_ptr(), value.len()),
            _ => (core::ptr::null(), 0),
        };
    check!(!value_start.is_null(), "the key hits");
    check!(value_len == capacity);
    check!(value_start == output_start);
    check!(output.words()[0] == VALUES[index][0] as u64);
}

/// R's observation that both workers reserved in epoch 4 (or overshot a
/// sealed arena; see the assumptions).
fn both_reserved_in_epoch_four(cache: CacheRef) -> bool {
    // Only the control word: loading the occupancy too would add a racing
    // read that only enlarges the explored state space.
    arena_ctl(cache, EPOCH_FOUR_ARENA, Relaxed).bump == 96
}

/// Both records after S's in arena 1 have their epoch-4 header published.
fn both_published_in_epoch_four(cache: CacheRef) -> bool {
    [32usize, 64]
        .iter()
        .all(|&offset| record_header(cache, EPOCH_FOUR_ARENA, offset, Relaxed).epoch == 4)
}

fn check_setup(cache: CacheRef) {
    check!(global_epoch(cache).load(Relaxed) == 4);
    check!(rotation_owner(cache, SeqCst).registration_id() == 0);
    check!(arena(cache, EPOCH_FOUR_ARENA) == state(4, false, 32, 1));
    let dead = participant(cache, 0);
    check!(!is_free(dead));
    check!(pinned_epoch(dead).load(SeqCst) == DEAD_PIN);
}

/// R's checks right after the race; returns the epoch.
fn check_after_race(cache: CacheRef, registry_full: bool) -> u64 {
    let epoch = global_epoch(cache).load(Relaxed);
    check!(epoch == 4 || epoch == 5);
    check!(!registry_full || epoch == 5);
    check_quiescent(cache);
    check!(is_free(participant(cache, 0)) == registry_full);
    check!(arena(cache, EPOCH_FOUR_ARENA) == state(4, true, 96, 3));
    let recycled = arena(cache, EPOCH_FIVE_ARENA);
    if epoch == 5 {
        check!(recycled == state(5, false, 0, 0));
    } else {
        check!(recycled == state(2, true, 96, 1));
    }
    epoch
}

/// After C's insertion: one more epoch than saved, holding only C.
fn check_final(cache: CacheRef, saved_epoch: u64) {
    let final_epoch = saved_epoch + 1;
    check!(global_epoch(cache).load(SeqCst) == final_epoch);
    check_quiescent(cache);
    check!(arena(cache, final_epoch % 3) == state(final_epoch as u32, false, 32, 1));
    check!(arena(cache, EPOCH_FOUR_ARENA) == state(4, true, 96, 3));
    if saved_epoch == 5 {
        // A's and B's promoted records.
        check!(arena(cache, EPOCH_FIVE_ARENA) == state(5, true, 64, 2));
    }
}

/// After R's replacement registration was released.
fn check_replacement_released(cache: CacheRef) {
    let slot = participant(cache, 0);
    check!(is_free(slot));
    check!(pinned_epoch(slot).load(SeqCst) == 0);
    check!(rotation_owner(cache, SeqCst).registration_id() == 0);
}

/// No rotation owner and no pinned participant.
fn check_quiescent(cache: CacheRef) {
    check!(rotation_owner(cache, SeqCst).registration_id() == 0);
    for index in 0..3 {
        check!(pinned_epoch(participant(cache, index)).load(SeqCst) == 0);
    }
}

/// Arena `index`'s control word (relaxed) and exact occupancy (seq_cst).
fn arena(cache: CacheRef, index: u64) -> (u64, u32) {
    (
        arena_ctl_word(cache, index).load(Relaxed),
        arena_exact_occupancy(cache, index).load(SeqCst),
    )
}

/// The expected `arena()` of an incarnation.
const fn state(epoch: u32, sealed: bool, bump: u32, occupancy: u32) -> (u64, u32) {
    (encode_ctl(epoch, sealed, bump), occupancy)
}
