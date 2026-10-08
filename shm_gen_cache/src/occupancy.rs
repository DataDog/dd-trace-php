//! Occupancy accounting: the exact shared counter, or the process-local
//! estimator.

use core::sync::atomic::Ordering::Relaxed;

use crate::arena::ArenaView;
use crate::config::HotParams;

mod sealed {
    pub trait Sealed {}
}

/// How a cache decides that the current arena reached its occupancy target.
///
/// A type parameter rather than a value: it selects the arena layout (the
/// exact counter has its own cache line) and removes the other mode's code
/// at compile time.
pub trait OccupancyMode: sealed::Sealed + Copy + Default + core::fmt::Debug + 'static {
    /// Whether this is the estimator.
    const ESTIMATES: bool;
    /// Per-registration state (zero-sized for the exact counter).
    type Estimator: Default + Clone;

    /// The reservation precheck.
    fn target_reached(
        arena: ArenaView<'_>,
        hp: &HotParams,
        epoch: u64,
        estimator: &mut Self::Estimator,
    ) -> bool;

    /// After publishing an index entry: accounts for it and reports whether
    /// the target is reached as far as this participant knows. `new_entry`
    /// says whether the entry claimed a bucket rather than found the key
    /// already present; `home_occupied` whether the first slot probed held
    /// an entry of this incarnation when read.
    fn entry_published(
        arena: ArenaView<'_>,
        hp: &HotParams,
        epoch: u64,
        estimator: &mut Self::Estimator,
        new_entry: bool,
        home_occupied: bool,
    ) -> bool;
}

/// Exact shared counter of the entries in each arena incarnation.
#[derive(Clone, Copy, Debug, Default)]
pub struct Exact;

/// The (empty) per-registration state of [`Exact`].
#[derive(Clone, Copy, Debug, Default)]
pub struct NoEstimator;

impl sealed::Sealed for Exact {}

impl OccupancyMode for Exact {
    const ESTIMATES: bool = false;
    type Estimator = NoEstimator;

    #[inline(always)]
    fn target_reached(arena: ArenaView<'_>, hp: &HotParams, _: u64, _: &mut NoEstimator) -> bool {
        arena.exact_occupancy().load(Relaxed) >= hp.max_occupancy
    }

    #[inline(always)]
    fn entry_published(
        arena: ArenaView<'_>,
        hp: &HotParams,
        _: u64,
        _: &mut NoEstimator,
        new_entry: bool,
        _home_occupied: bool,
    ) -> bool {
        // Counts every new entry in shared storage.
        let count = if new_entry {
            arena
                .exact_occupancy()
                .fetch_add(1, Relaxed)
                .wrapping_add(1)
        } else {
            arena.exact_occupancy().load(Relaxed)
        };
        count >= hp.max_occupancy
    }
}

#[cfg(feature = "std")]
pub use estimator::{Estimated, OccupancyEstimator};

// f64::sqrt and f64::log2 are not available in core on the pinned toolchain,
// hence std (log2 calls the platform libm).
#[cfg(feature = "std")]
mod estimator {
    use super::{ArenaView, HotParams, OccupancyMode, sealed};

    /// Per-participant statistical estimate of each arena's load.
    #[derive(Clone, Copy, Debug, Default)]
    pub struct Estimated;

    impl sealed::Sealed for Estimated {}

    impl OccupancyMode for Estimated {
        const ESTIMATES: bool = true;
        type Estimator = OccupancyEstimator;

        #[inline(always)]
        fn target_reached(
            _: ArenaView<'_>,
            hp: &HotParams,
            epoch: u64,
            estimator: &mut OccupancyEstimator,
        ) -> bool {
            estimator.target_reached(epoch, hp.target, hp.buckets)
        }

        #[inline(always)]
        fn entry_published(
            _: ArenaView<'_>,
            hp: &HotParams,
            epoch: u64,
            estimator: &mut OccupancyEstimator,
            new_entry: bool,
            home_occupied: bool,
        ) -> bool {
            // A new entry's home slot is a sample of the load; a replacement
            // is not, because its home slot may hold that same key.
            if new_entry {
                estimator.sample(epoch, home_occupied);
            }
            estimator.target_reached(epoch, hp.target, hp.buckets)
        }
    }

    /// Tells a participant when the current arena reached its occupancy
    /// target without maintaining a shared count.
    ///
    /// For a key absent from an arena, the home slot of its probe sequence
    /// holds an entry of that arena's incarnation with probability exactly
    /// equal to the arena's load, whatever the clustering farther along.
    /// Publishing a new entry reads that slot anyway, so every new entry
    /// gives its participant a free Bernoulli sample of the load. The load
    /// grows about linearly with the participant's sample count t, so a
    /// least-squares line through the samples predicts the load now;
    /// averaging them instead would lag the fill. The line has an intercept
    /// because a participant can start sampling mid-epoch.
    ///
    /// Every participant tests its own estimate, and the first one to cross
    /// the target triggers rotation, so a plain test would fire at the most
    /// optimistic of N noisy estimates. Each therefore waits until its
    /// estimate exceeds the target by z standard errors, z being the
    /// expected maximum of N standard normals, which centres the first
    /// crossing on the target. N counts the participants sampling, not the
    /// registered ones: idle registrations add no estimates. A participant
    /// infers it from its own share of the samples, N = (predicted entries) /
    /// (its samples), which also weighs uneven insert rates: one that inserts
    /// a small share sees a large N.
    ///
    /// Process-local: each registration owns one.
    #[derive(Clone, Copy, Debug, Default)]
    pub struct OccupancyEstimator {
        sampled_epoch: u64,
        // Exact sums over samples t = 1..n.
        n: u64,
        sum_t: u64,
        sum_tt: u64,
        sum_x: u64,
        sum_tx: u64,
        decided_at: u64,
        decision: bool,
    }

    impl OccupancyEstimator {
        /// Only keeps the fit away from a handful of points; the z * error
        /// margin already makes a participant with few samples wait for more
        /// evidence.
        pub const MIN_SAMPLES: u64 = 64;
        /// `sum_tt` stays below 2^64 up to here.
        pub const MAX_SAMPLES: u64 = 1 << 21;

        /// A sample from a new entry published in the arena incarnation of
        /// `epoch`.
        #[inline]
        pub fn sample(&mut self, epoch: u64, home_occupied: bool) {
            if epoch != self.sampled_epoch {
                *self = Self::default();
                self.sampled_epoch = epoch;
            }
            if self.n == Self::MAX_SAMPLES {
                return; // Keeps the sums exact; the fit has long settled.
            }
            // No overflow: n <= 2^21, so sum_tt < 2^64.
            self.n += 1;
            let t = self.n;
            self.sum_t += t;
            self.sum_tt += t * t;
            self.sum_x += home_occupied as u64;
            self.sum_tx += if home_occupied { t } else { 0 };
        }

        /// Whether the load of `epoch`'s arena, of `buckets` buckets, reached
        /// `target` (a fraction of them).
        #[inline]
        pub fn target_reached(&mut self, epoch: u64, target: f64, buckets: f64) -> bool {
            if epoch != self.sampled_epoch || self.n < Self::MIN_SAMPLES {
                return false;
            }
            // The fit moves slowly: answer from the last decision until
            // enough new samples arrive. The step grows with the samples so
            // far, so a participant that takes few samples per generation (a
            // small table shared by many) still decides within a few percent
            // of them.
            if self.n - self.decided_at < (self.n / 32).clamp(4, 32) {
                return self.decision;
            }
            self.decide(target, buckets)
        }

        /// Out of line, so the inlined fast paths of store and reserve stay
        /// a comparison: a bigger body costs their callers' inlining.
        #[cold]
        #[inline(never)]
        fn decide(&mut self, target: f64, buckets: f64) -> bool {
            self.decided_at = self.n;
            let dn = self.n as f64;
            let t_mean = self.sum_t as f64 / dn;
            let s_tt = self.sum_tt as f64 - t_mean * self.sum_t as f64;
            let slope = (self.sum_tx as f64 - t_mean * self.sum_x as f64) / s_tt;
            let load = self.sum_x as f64 / dn + slope * (dn - t_mean);
            if load < target - 0.1 {
                self.decision = false; // Far below: skip the error estimate.
                return self.decision;
            }
            // Like std::clamp, passes NaN through.
            let p = load.clamp(0.0, 1.0);
            let error =
                (p * (1.0 - p) * ((1.0 / dn) + ((dn - t_mean) * (dn - t_mean) / s_tt))).sqrt();
            let samplers = p * buckets / dn;
            self.decision = load >= target + expected_max_of_normals(samplers) * error;
            self.decision
        }
    }

    /// E[max of n independent standard normals], interpolated linearly in
    /// log2 n between exact values at powers of two (within 0.01 between
    /// them) and extrapolated beyond 1024; zero for n <= 1.
    fn expected_max_of_normals(n: f64) -> f64 {
        const AT_POWER_OF_TWO: [f64; 11] = [
            0.0, 0.5642, 1.0294, 1.4236, 1.7660, 2.0697, 2.3437, 2.5946, 2.8269, 3.0439, 3.2482,
        ];
        if n <= 1.0 {
            return 0.0;
        }
        let k = n.log2();
        let i = (k as usize).min(AT_POWER_OF_TWO.len() - 2);
        AT_POWER_OF_TWO[i] + (k - i as f64) * (AT_POWER_OF_TWO[i + 1] - AT_POWER_OF_TWO[i])
    }
}
