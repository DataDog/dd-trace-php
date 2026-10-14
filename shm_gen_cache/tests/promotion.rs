//! Nonblocking promotion-readiness scans and occupancy-triggered rotation.
//! Staged pins stand for paused writers; the concurrency suite checks their
//! ordering.

#![cfg(feature = "verify")]

use std::sync::atomic::Ordering::{Acquire, Relaxed, Release};

use shm_gen_cache::test_access::{
    ROTATION_WAITING, RotationPolicy, arena_state, global_epoch, pinned_epoch,
    promotion_ready_epoch, rotate,
};
use shm_gen_cache::{Cache, Error, GetPid, StaticParams, output_buffer, static_config};

/// Any attempt to reap is a test failure, even if it would report live.
struct NoReaping;

impl GetPid for NoReaping {
    fn get_pid() -> u32 {
        1
    }

    fn get_start_time() -> u64 {
        0
    }

    fn is_live(_: u32) -> Result<bool, Error> {
        panic!("promotion readiness must not query liveness")
    }

    fn is_live_since(_: u32, _: u64) -> Result<bool, Error> {
        panic!("promotion readiness must not query liveness")
    }
}

static_config! {
    struct Cfg: NoReaping {
        participant_capacity: 2,
        bucket_count: 8, // every unknown-readiness hit retries the scan
        max_key_size: 1,
        max_value_size: 1,
        record_area_size: 256,
        max_occupancy: 8,
        reservation_chunk_size: 8,
    }
}

#[test]
fn readiness_checks_defer_active_writers_without_waiting_or_reaping() {
    let storage = <Cfg as StaticParams>::Storage::new();
    // SAFETY: private, suitably aligned storage that outlives the cache.
    let cache = unsafe { Cache::initialize(storage.as_mut_ptr(), storage.len(), Cfg) }.unwrap();
    let mut writer = cache.register_participant().unwrap();
    let mut reader = cache.register_participant().unwrap();
    writer.insert(1, b"k", b"v").unwrap();
    writer.insert(2, b"j", b"w").unwrap();

    // A writer paused before its final unpin. Readiness must handle the
    // waiting flag as part of this pin, not as another epoch.
    let pin = pinned_epoch(writer.slot());
    pin.store(2 | ROTATION_WAITING, Release);
    rotate(cache, reader.slot_index(), 2, RotationPolicy::WaitForOwner).unwrap();
    assert_eq!(global_epoch(cache).load(Relaxed), 3);
    assert_eq!(promotion_ready_epoch(cache, 2, Acquire), 0);

    let mut output = output_buffer!(1);
    for _ in 0..3 {
        assert_eq!(
            reader.lookup(1, b"k", &mut output).unwrap(),
            Some(&b"v"[..])
        );
        assert_eq!(pin.load(Relaxed), 2 | ROTATION_WAITING);
        assert_eq!(promotion_ready_epoch(cache, 2, Acquire), 0);
        assert_eq!(arena_state(cache, 0, Relaxed).bump, 0);
    }

    pin.store(0, Release);
    assert_eq!(
        reader.lookup(1, b"k", &mut output).unwrap(),
        Some(&b"v"[..])
    );
    assert_eq!(promotion_ready_epoch(cache, 2, Acquire), 2);
    assert_eq!(arena_state(cache, 0, Relaxed).occupancy, Some(1));

    // A late pin from an attempt that will fail its seal check must not
    // invalidate the cached certificate or force another scan. An already
    // certified generation can have no more accepted writers.
    pin.store(2, Release);
    assert_eq!(
        reader.lookup(2, b"j", &mut output).unwrap(),
        Some(&b"w"[..])
    );
    assert_eq!(arena_state(cache, 0, Relaxed).occupancy, Some(2));
    assert_eq!(pin.load(Relaxed), 2);
    pin.store(0, Release);
}

#[test]
fn reused_arena_needs_its_own_readiness_certificate() {
    let storage = <Cfg as StaticParams>::Storage::new();
    // SAFETY: private, suitably aligned storage that outlives the cache.
    let cache = unsafe { Cache::initialize(storage.as_mut_ptr(), storage.len(), Cfg) }.unwrap();
    let mut writer = cache.register_participant().unwrap();
    let mut reader = cache.register_participant().unwrap();
    writer.insert(1, b"k", b"v").unwrap();
    for epoch in 2..5 {
        rotate(
            cache,
            reader.slot_index(),
            epoch,
            RotationPolicy::WaitForOwner,
        )
        .unwrap();
    }
    assert_eq!(promotion_ready_epoch(cache, 2, Acquire), 2);
    writer.insert(1, b"k", b"w").unwrap();
    let pin = pinned_epoch(writer.slot());
    pin.store(5, Release);
    rotate(cache, reader.slot_index(), 5, RotationPolicy::WaitForOwner).unwrap();

    let mut output = output_buffer!(1);
    assert_eq!(
        reader.lookup(1, b"k", &mut output).unwrap(),
        Some(&b"w"[..])
    );
    assert_eq!(promotion_ready_epoch(cache, 2, Acquire), 2);
    assert_eq!(arena_state(cache, 0, Relaxed).bump, 0);
    pin.store(0, Release);
    assert_eq!(
        reader.lookup(1, b"k", &mut output).unwrap(),
        Some(&b"w"[..])
    );
    assert_eq!(promotion_ready_epoch(cache, 2, Acquire), 5);
    assert_eq!(arena_state(cache, 0, Relaxed).occupancy, Some(1));
}

static_config! {
    struct LowOccupancy: NoReaping {
        participant_capacity: 1,
        bucket_count: 8,
        max_key_size: 1,
        max_value_size: 1,
        record_area_size: 256,
        max_occupancy: 2,
        reservation_chunk_size: 8,
    }
}

#[test]
fn promotions_trigger_rotation_at_the_occupancy_limit() {
    let storage = <LowOccupancy as StaticParams>::Storage::new();
    // SAFETY: private, suitably aligned storage that outlives the cache.
    let cache =
        unsafe { Cache::initialize(storage.as_mut_ptr(), storage.len(), LowOccupancy) }.unwrap();
    let mut participant = cache.register_participant().unwrap();
    participant.insert(1, b"k", b"v").unwrap();
    participant.insert(2, b"j", b"w").unwrap();
    assert_eq!(global_epoch(cache).load(Relaxed), 3);

    let mut output = output_buffer!(1);
    assert_eq!(
        participant.lookup(1, b"k", &mut output).unwrap(),
        Some(&b"v"[..])
    );
    assert_eq!(
        participant.lookup(2, b"j", &mut output).unwrap(),
        Some(&b"w"[..])
    );
    assert_eq!(arena_state(cache, 0, Relaxed).occupancy, Some(2));
    assert_eq!(global_epoch(cache).load(Relaxed), 4);
    assert!(arena_state(cache, 0, Relaxed).sealed);
    assert_eq!(arena_state(cache, 1, Relaxed).occupancy, Some(0));

    participant.insert(3, b"i", b"x").unwrap();
    assert_eq!(global_epoch(cache).load(Relaxed), 4);
}
