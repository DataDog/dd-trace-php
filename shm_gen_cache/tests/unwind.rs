//! A caught panic must not leave the surviving participant pinned.
#![cfg(feature = "verify")]

use std::cell::Cell;
use std::panic::{AssertUnwindSafe, catch_unwind};
use std::sync::atomic::Ordering::Relaxed;

use shm_gen_cache::{
    Cache, CacheStorage, Error, GetPid, NoopGetPid, ParticipantSlot, StaticParams, output_buffer,
    static_config, test_access as access,
};

thread_local! {
    static PANIC_AFTER_PUBLICATION: Cell<bool> = const { Cell::new(false) };
}

struct PanicPid;
impl GetPid for PanicPid {
    fn get_pid() -> u32 {
        NoopGetPid::get_pid()
    }
    fn get_start_time() -> u64 {
        NoopGetPid::get_start_time()
    }
    fn is_live(pid: u32) -> Result<bool, Error> {
        NoopGetPid::is_live(pid)
    }
    fn is_live_since(pid: u32, start: u64) -> Result<bool, Error> {
        NoopGetPid::is_live_since(pid, start)
    }
    fn after_record_publication(slot: &ParticipantSlot) {
        if PANIC_AFTER_PUBLICATION.with(|armed| armed.replace(false)) {
            assert_ne!(access::pinned_epoch(slot).load(Relaxed), 0);
            access::pinned_epoch(slot).fetch_or(access::ROTATION_WAITING, Relaxed);
            panic!("test panic before W7");
        }
    }
}

static_config! {
    struct Small: PanicPid {
        participant_capacity: 2,
        bucket_count: 8,
        max_key_size: 8,
        max_value_size: 8,
        max_occupancy: 8,
    }
}

#[test]
fn caught_insert_panic_allows_reuse() {
    let storage: <Small as StaticParams>::Storage = CacheStorage::new();
    let cache = unsafe { Cache::initialize(storage.as_mut_ptr(), storage.len(), Small) }.unwrap();
    let mut lock = cache.register_participant().unwrap();
    PANIC_AFTER_PUBLICATION.with(|armed| armed.set(true));
    assert!(catch_unwind(AssertUnwindSafe(|| lock.insert(1, b"key", b"value"))).is_err());
    assert_eq!(access::pinned_epoch(lock.slot()).load(Relaxed), 0);
    let mut out = output_buffer!(8);
    assert_eq!(lock.lookup(2, b"missing", &mut out).unwrap(), None);
    lock.insert(1, b"key", b"new").unwrap();
    assert_eq!(lock.lookup(1, b"key", &mut out).unwrap(), Some(&b"new"[..]));
    let index = lock.slot_index();
    drop(lock);
    assert!(access::is_free(access::participant(cache, index)));
}

#[test]
fn caught_promotion_panic_allows_reuse() {
    let storage: <Small as StaticParams>::Storage = CacheStorage::new();
    let cache = unsafe { Cache::initialize(storage.as_mut_ptr(), storage.len(), Small) }.unwrap();
    let mut lock = cache.register_participant().unwrap();
    lock.insert(1, b"key", b"value").unwrap();
    access::rotate(
        cache,
        lock.slot_index(),
        access::FIRST_EPOCH,
        access::RotationPolicy::WaitForOwner,
    )
    .unwrap();
    let mut out = output_buffer!(8);
    PANIC_AFTER_PUBLICATION.with(|armed| armed.set(true));
    assert!(
        catch_unwind(AssertUnwindSafe(|| {
            let _ = lock.lookup(1, b"key", &mut out);
        }))
        .is_err()
    );
    assert_eq!(access::pinned_epoch(lock.slot()).load(Relaxed), 0);
    assert_eq!(
        lock.lookup(1, b"key", &mut out).unwrap(),
        Some(&b"value"[..])
    );
}

#[test]
fn drop_clears_a_remaining_pin_before_releasing_registration() {
    let storage: <Small as StaticParams>::Storage = CacheStorage::new();
    let cache = unsafe { Cache::initialize(storage.as_mut_ptr(), storage.len(), Small) }.unwrap();
    let lock = cache.register_participant().unwrap();
    let slot = access::participant(cache, lock.slot_index());
    // Stage an interrupted attempt to exercise Drop's final safeguard.
    access::pinned_epoch(slot).store(access::FIRST_EPOCH | access::ROTATION_WAITING, Relaxed);
    drop(lock);
    assert_eq!(access::pinned_epoch(slot).load(Relaxed), 0);
    assert!(access::is_free(slot));
}
