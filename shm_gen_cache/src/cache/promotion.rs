//! Cached, nonblocking quiescence checks for promotion sources.

use core::sync::atomic::Ordering::{Acquire, Release, SeqCst};
use core::sync::atomic::fence;

use super::Cache;
use crate::config::{HotParams, Params};
use crate::participant::ROTATION_WAITING;

/// Process-local sampler, advanced on previous-generation hits that cannot
/// yet be promoted. A changing state lets even one repeatedly looked-up
/// key eventually retry, without contending on another shared counter.
pub(crate) struct PromotionSampler(u64);

impl PromotionSampler {
    pub(crate) fn new(registration_id: u32) -> Self {
        Self(registration_id as u64)
    }

    /// About eight scans per table's worth of eligible hits while readiness
    /// is unknown: probability min(1, 8 / bucket_count). Table capacities
    /// are powers of two. Once ready, lookups bypass the sampler entirely.
    #[inline]
    pub(crate) fn should_recheck(&mut self, bucket_mask: u32) -> bool {
        let mask = bucket_mask >> 3;
        if mask == 0 {
            return true;
        }
        // SplitMix64: distinct registrations start distinct streams, and
        // the mixing avoids correlating checks with particular keys.
        self.0 = self.0.wrapping_add(0x9e3779b97f4a7c15);
        let mut bits = self.0;
        bits = (bits ^ (bits >> 30)).wrapping_mul(0xbf58476d1ce4e5b9);
        bits = (bits ^ (bits >> 27)).wrapping_mul(0x94d049bb133111eb);
        bits ^= bits >> 31;
        bits as u32 & mask == 0
    }
}

impl<P: Params> Cache<'_, P> {
    /// Tries once to certify e - 1 as a promotion source. No ownership,
    /// waits, pin announcements or reaping: a remaining writer defers it.
    #[inline]
    pub(super) fn recheck_promotion_ready(self, hp: &HotParams, e: u64) -> bool {
        // C1: acquiring e imports the seal of e - 1. C2, the SC fence,
        // pairs with reservers' W3: an accepted writer's pin cannot be
        // missed unless the scan sees a later pin value. The acquire pin
        // loads (C3) then import everything published before those unpins
        // (docs/invariants.html, RD1). On the only path that reaches this
        // function, C1's acquire and C2 are redundant: the caller's hit
        // already synchronised with R5(e), which follows the R1F-fenced
        // scan of the tenure that sealed e - 1, and C3 reads at or after
        // what that scan read.
        // Kept nonetheless, as this is a cold branch and keeps the argument
        // local.
        if self.global_epoch().load(Acquire) != e {
            return false;
        }
        fence(SeqCst); // C2
        for i in 0..hp.participant_capacity {
            let pin = self.participant(hp, i).pinned_epoch.load(Acquire);
            if pin & !ROTATION_WAITING == e - 1 {
                return false;
            }
        }
        self.mark_promotion_ready(hp, e - 1); // C4
        true
    }

    /// Release-publishes all index writes imported by a successful scan.
    /// A delayed helper must not overwrite a newer certificate after arena
    /// reuse; monotonic RMWs also preserve earlier release sequences.
    #[inline]
    pub(super) fn mark_promotion_ready(self, hp: &HotParams, epoch: u64) {
        self.arena_of(hp, epoch)
            .promotion_ready_epoch()
            .fetch_max(epoch, Release);
    }
}

#[cfg(test)]
mod tests {
    use super::PromotionSampler;
    use core::sync::atomic::Ordering::Acquire;

    use crate::{Cache, NoopGetPid, Params, StaticParams, static_config};

    static_config! {
        struct Cfg: NoopGetPid {
            participant_capacity: 1,
            bucket_count: 8,
            max_key_size: 1,
            max_value_size: 1,
        }
    }

    #[test]
    fn delayed_certificate_does_not_regress_or_truncate_the_epoch() {
        let storage = <Cfg as StaticParams>::Storage::new();
        // SAFETY: private aligned storage that outlives the cache.
        let cache = unsafe { Cache::initialize(storage.as_mut_ptr(), storage.len(), Cfg) }.unwrap();
        let hp = Cfg.hot();
        // These epochs share both their physical arena and low 32 bits.
        let newer = 2 + 3 * (1u64 << 32);
        cache.mark_promotion_ready(&hp, newer);
        cache.mark_promotion_ready(&hp, 2);
        assert_eq!(
            cache.arena_of(&hp, 2).promotion_ready_epoch().load(Acquire),
            newer
        );
    }

    #[test]
    fn recheck_rate_scales_with_capacity() {
        // Eight buckets: always recheck.
        assert!(PromotionSampler::new(1).should_recheck(7));
        for buckets in [16, 128, 1024, 8192] {
            let mut sampler = PromotionSampler::new(1);
            let trials = buckets * 1024;
            let checks = (0..trials)
                .filter(|_| sampler.should_recheck(buckets - 1))
                .count();
            // Expected count is 8192 for every capacity. This is a fixed,
            // deterministic stream, with generous bounds around the mean.
            assert!((7500..8900).contains(&checks), "{buckets}: {checks}");
        }
    }

    #[test]
    fn registrations_have_different_schedules() {
        let mut a = PromotionSampler::new(1);
        let mut b = PromotionSampler::new(2);
        assert!((0..1024).any(|_| a.should_recheck(1023) != b.should_recheck(1023)));
    }

    /// Nonblocking promotion-readiness scans and occupancy-triggered
    /// rotation. Staged pins stand for paused writers; the concurrency
    /// suite checks their ordering.
    mod readiness {
        use core::sync::atomic::Ordering::{Acquire, Relaxed, Release};

        use crate::arena::ctl::CtlWord;
        use crate::cache::rotation::RotationPolicy;
        use crate::participant::ROTATION_WAITING;
        use crate::{Cache, Error, GetPid, Params, StaticParams, output_buffer, static_config};

        /// Any attempt to reap is a test failure, even if it would report
        /// live.
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

        /// Arena `index`'s control word and exact occupancy counter.
        struct ArenaState {
            sealed: bool,
            bump: u32,
            occupancy: u32,
        }

        fn arena_state<P: Params>(cache: Cache<'_, P>, index: u64) -> ArenaState {
            let hp = cache.params().hot();
            let arena = cache.arena(&hp, index);
            let ctl = CtlWord(arena.ctl().load(Relaxed));
            ArenaState {
                sealed: ctl.sealed(),
                bump: ctl.bump(),
                occupancy: arena.exact_occupancy().load(Relaxed),
            }
        }

        fn promotion_ready_epoch<P: Params>(cache: Cache<'_, P>, index: u64) -> u64 {
            let hp = cache.params().hot();
            cache
                .arena(&hp, index)
                .promotion_ready_epoch()
                .load(Acquire)
        }

        #[test]
        fn readiness_checks_defer_active_writers_without_waiting_or_reaping() {
            let storage = <Cfg as StaticParams>::Storage::new();
            // SAFETY: private, suitably aligned storage that outlives the
            // cache.
            let cache =
                unsafe { Cache::initialize(storage.as_mut_ptr(), storage.len(), Cfg) }.unwrap();
            let mut writer = cache.register_participant().unwrap();
            let mut reader = cache.register_participant().unwrap();
            writer.insert(1, b"k", b"v").unwrap();
            writer.insert(2, b"j", b"w").unwrap();

            // A writer paused before its final unpin. Readiness must handle
            // the waiting flag as part of this pin, not as another epoch.
            let pin = &writer.slot().pinned_epoch;
            pin.store(2 | ROTATION_WAITING, Release);
            cache
                .rotate(
                    reader.slot(),
                    reader.slot_index(),
                    2,
                    RotationPolicy::WaitForOwner,
                )
                .unwrap();
            assert_eq!(cache.global_epoch().load(Relaxed), 3);
            assert_eq!(promotion_ready_epoch(cache, 2), 0);

            let mut output = output_buffer!(1);
            for _ in 0..3 {
                assert_eq!(
                    reader.lookup(1, b"k", &mut output).unwrap(),
                    Some(&b"v"[..])
                );
                assert_eq!(pin.load(Relaxed), 2 | ROTATION_WAITING);
                assert_eq!(promotion_ready_epoch(cache, 2), 0);
                assert_eq!(arena_state(cache, 0).bump, 0);
            }

            pin.store(0, Release);
            assert_eq!(
                reader.lookup(1, b"k", &mut output).unwrap(),
                Some(&b"v"[..])
            );
            assert_eq!(promotion_ready_epoch(cache, 2), 2);
            assert_eq!(arena_state(cache, 0).occupancy, 1);

            // A late pin from an attempt that will fail its seal check must
            // not invalidate the cached certificate or force another scan.
            // An already certified generation can have no more accepted
            // writers.
            pin.store(2, Release);
            assert_eq!(
                reader.lookup(2, b"j", &mut output).unwrap(),
                Some(&b"w"[..])
            );
            assert_eq!(arena_state(cache, 0).occupancy, 2);
            assert_eq!(pin.load(Relaxed), 2);
            pin.store(0, Release);
        }

        #[test]
        fn reused_arena_needs_its_own_readiness_certificate() {
            let storage = <Cfg as StaticParams>::Storage::new();
            // SAFETY: private, suitably aligned storage that outlives the
            // cache.
            let cache =
                unsafe { Cache::initialize(storage.as_mut_ptr(), storage.len(), Cfg) }.unwrap();
            let mut writer = cache.register_participant().unwrap();
            let mut reader = cache.register_participant().unwrap();
            let rotate = |e| {
                cache.rotate(
                    reader.slot(),
                    reader.slot_index(),
                    e,
                    RotationPolicy::WaitForOwner,
                )
            };
            writer.insert(1, b"k", b"v").unwrap();
            for epoch in 2..5 {
                rotate(epoch).unwrap();
            }
            assert_eq!(promotion_ready_epoch(cache, 2), 2);
            writer.insert(1, b"k", b"w").unwrap();
            let pin = &writer.slot().pinned_epoch;
            pin.store(5, Release);
            rotate(5).unwrap();

            let mut output = output_buffer!(1);
            assert_eq!(
                reader.lookup(1, b"k", &mut output).unwrap(),
                Some(&b"w"[..])
            );
            assert_eq!(promotion_ready_epoch(cache, 2), 2);
            assert_eq!(arena_state(cache, 0).bump, 0);
            pin.store(0, Release);
            assert_eq!(
                reader.lookup(1, b"k", &mut output).unwrap(),
                Some(&b"w"[..])
            );
            assert_eq!(promotion_ready_epoch(cache, 2), 5);
            assert_eq!(arena_state(cache, 0).occupancy, 1);
        }

        #[test]
        fn promotions_trigger_rotation_at_the_occupancy_limit() {
            let storage = <LowOccupancy as StaticParams>::Storage::new();
            // SAFETY: private, suitably aligned storage that outlives the
            // cache.
            let cache =
                unsafe { Cache::initialize(storage.as_mut_ptr(), storage.len(), LowOccupancy) }
                    .unwrap();
            let mut participant = cache.register_participant().unwrap();
            participant.insert(1, b"k", b"v").unwrap();
            participant.insert(2, b"j", b"w").unwrap();
            assert_eq!(cache.global_epoch().load(Relaxed), 3);

            let mut output = output_buffer!(1);
            assert_eq!(
                participant.lookup(1, b"k", &mut output).unwrap(),
                Some(&b"v"[..])
            );
            assert_eq!(
                participant.lookup(2, b"j", &mut output).unwrap(),
                Some(&b"w"[..])
            );
            assert_eq!(arena_state(cache, 0).occupancy, 2);
            assert_eq!(cache.global_epoch().load(Relaxed), 4);
            assert!(arena_state(cache, 0).sealed);
            assert_eq!(arena_state(cache, 1).occupancy, 0);

            participant.insert(3, b"i", b"x").unwrap();
            assert_eq!(cache.global_epoch().load(Relaxed), 4);
        }
    }
}
