use super::Reason;
use crate::dogstatsd;
use ddcommon::tag::Tag;
use log::debug;
use std::borrow::Cow;
use std::time::Duration;

pub struct OverheadMetrics {
    client: dogstatsd::Client,
}

impl OverheadMetrics {
    pub fn new() -> anyhow::Result<Self> {
        cfg_if::cfg_if! {
            if #[cfg(feature = "profiling_metrics")] {
                let client = dogstatsd::Client::new()?;
                Ok(OverheadMetrics { client })
            } else {
                anyhow::anyhow!("profiling metrics are not enabled")
            }
        }
    }

    pub fn record_stack_walk(&mut self, _reason: Reason, _duration: Duration) {
        #[cfg(feature = "profiling_metrics")]
        {
            let reason = match _reason {
                Reason::Alloc => "alloc",
                Reason::Wall => "wall",
            };

            if let Err(err) = self.log_overhead_helper(reason, _duration) {
                debug!("failed emitting metric: {err:#}");
            }
        }
    }

    fn log_overhead_helper(&mut self, reason: &str, duration: Duration) -> anyhow::Result<()> {
        let reason_tag = Tag::new("reason", reason).map_err(|err| anyhow::anyhow!("{err}"))?;

        self.client.histogram(
            Cow::Borrowed("datadog.profiling.php.stack_walk_ns"),
            duration.as_nanos() as f64,
            [&reason_tag],
        )?;
        Ok(())
    }
}
