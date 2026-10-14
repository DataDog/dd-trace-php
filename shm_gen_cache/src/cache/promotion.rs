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
        // Acquiring e imports the seal of e - 1. The subsequent SC fence
        // pairs with reservers' W3: an accepted writer's pin cannot be
        // missed unless the scan sees a later pin value. The acquire pin
        // loads then import everything published before those unpins.
        if self.global_epoch().load(Acquire) != e {
            return false;
        }
        fence(SeqCst);
        for i in 0..hp.participant_capacity {
            let pin = self.participant(hp, i).pinned_epoch.load(Acquire);
            if pin & !ROTATION_WAITING == e - 1 {
                return false;
            }
        }
        self.mark_promotion_ready(hp, e - 1);
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
    fn small_tables_always_recheck() {
        let mut sampler = PromotionSampler::new(1);
        for _ in 0..100 {
            assert!(sampler.should_recheck(7));
        }
    }

    #[test]
    fn recheck_rate_scales_with_capacity() {
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
}
