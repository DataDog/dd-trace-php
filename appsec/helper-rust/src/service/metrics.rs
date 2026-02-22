use std::sync::atomic::{AtomicU64, Ordering};

use crate::telemetry::{self, TelemetryMetricSubmitter, TelemetryMetricsGenerator, TelemetryTags};

#[derive(Debug, Default)]
pub struct WorkerCountState {
    state: AtomicU64,
}

impl WorkerCountState {
    const DIRTY_BIT: u64 = 1u64 << 63;
    const COUNT_MASK: u64 = !Self::DIRTY_BIT;

    #[inline]
    pub fn increment(&self) {
        // Ignore the Result: the closure always returns Some(_), so it will eventually succeed.
        let _ = self
            .state
            .fetch_update(Ordering::Relaxed, Ordering::Relaxed, |s| {
                let count = (s & Self::COUNT_MASK).wrapping_add(1);
                Some(count | Self::DIRTY_BIT)
            });
    }

    #[inline]
    pub fn decrement(&self) {
        let _ = self
            .state
            .fetch_update(Ordering::Relaxed, Ordering::Relaxed, |s| {
                let count = (s & Self::COUNT_MASK).wrapping_sub(1);
                Some(count | Self::DIRTY_BIT)
            });
    }

    #[inline]
    fn consume_dirty(&self) -> Option<u64> {
        let prev = self.state.fetch_and(Self::COUNT_MASK, Ordering::Relaxed); // clears dirty bit
        (prev & Self::DIRTY_BIT != 0).then(|| prev & Self::COUNT_MASK)
    }
}
impl TelemetryMetricsGenerator for WorkerCountState {
    fn generate_telemetry_metrics(&self, submitter: &mut dyn TelemetryMetricSubmitter) {
        if let Some(count) = self.consume_dirty() {
            submitter.submit_metric(
                telemetry::HELPER_WORKER_COUNT,
                count as f64,
                TelemetryTags::new(),
            );
        }
    }
}
