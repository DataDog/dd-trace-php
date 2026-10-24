//! Backend panic during a rotation must release its tenure before P3.
#![cfg(feature = "verify")]
use shm_gen_cache::{
    Cache, CacheStorage, Error, GetPid, NoopGetPid, StaticParams, static_config,
    test_access as access,
};
use std::cell::Cell;
use std::panic::{AssertUnwindSafe, catch_unwind};
use std::sync::atomic::Ordering::Relaxed;

thread_local! { static PANIC_LIVENESS: Cell<bool> = const { Cell::new(false) }; }
struct PanicPid;
impl GetPid for PanicPid {
    fn get_pid() -> u32 {
        NoopGetPid::get_pid()
    }
    fn get_start_time() -> u64 {
        NoopGetPid::get_start_time()
    }
    fn is_live(pid: u32) -> Result<bool, Error> {
        assert!(
            !PANIC_LIVENESS.with(Cell::get),
            "backend panic while owning rotation"
        );
        NoopGetPid::is_live(pid)
    }
    fn is_live_since(pid: u32, start: u64) -> Result<bool, Error> {
        assert!(
            !PANIC_LIVENESS.with(Cell::get),
            "backend panic while owning rotation"
        );
        NoopGetPid::is_live_since(pid, start)
    }
}
static_config! {
    struct Small: PanicPid {
        participant_capacity: 3,
        bucket_count: 8,
        max_key_size: 8,
        max_value_size: 8,
        max_occupancy: 8,
    }
}
#[test]
fn backend_panic_releases_owner_before_registration() {
    let storage: <Small as StaticParams>::Storage = CacheStorage::new();
    let cache = unsafe { Cache::initialize(storage.as_mut_ptr(), storage.len(), Small) }.unwrap();
    let owner = cache.register_participant().unwrap();
    let pinner = cache.register_participant().unwrap();
    let first = access::FIRST_EPOCH;
    for epoch in first..first + 2 {
        access::rotate(
            cache,
            owner.slot_index(),
            epoch,
            access::RotationPolicy::WaitForOwner,
        )
        .unwrap();
    }
    // A writer pinned to the arena that this rotation must reuse. This
    // stages the state of a different writer paused after W2.
    access::pinned_epoch(pinner.slot()).store(first, Relaxed);
    PANIC_LIVENESS.with(|armed| armed.set(true));
    let panic = catch_unwind(AssertUnwindSafe(|| {
        access::rotate(
            cache,
            owner.slot_index(),
            first + 2,
            access::RotationPolicy::WaitForOwner,
        )
    }));
    PANIC_LIVENESS.with(|armed| armed.set(false));
    assert!(panic.is_err());
    assert_eq!(access::rotation_owner(cache, Relaxed).registration_id(), 0);
    let owner_slot = access::participant(cache, owner.slot_index());
    drop(owner);
    assert!(access::is_free(owner_slot));
    assert_eq!(access::rotation_owner(cache, Relaxed).registration_id(), 0);
    drop(pinner);
    let next = cache.register_participant().unwrap();
    access::rotate(
        cache,
        next.slot_index(),
        first + 2,
        access::RotationPolicy::SkipBusyOwner,
    )
    .unwrap();
}
