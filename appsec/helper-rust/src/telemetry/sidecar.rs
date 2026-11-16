use std::cell::Cell;
use std::collections::hash_map::DefaultHasher;
use std::hash::{Hash, Hasher};
use std::time::{Duration, Instant};

use datadog_sidecar::service::telemetry::InProcessTelemetryClient;
use datadog_sidecar::service::telemetry::InternalTelemetryAction;
use libdd_common::tag::parse_tags;
use libdd_telemetry::data::metrics::MetricNamespace;
use libdd_telemetry::data::{Log, LogLevel as TelemetryLogLevel};
use libdd_telemetry::metrics::MetricContext;
use libdd_telemetry::worker::{LogIdentifier, TelemetryActions};

use crate::client::log::{debug, info, warning};
use crate::telemetry::{TelemetryLogSubmitter, TelemetryMetricSubmitter, TelemetryTags};

use super::{KnownMetric, LogLevel, MetricName, TelemetryLog};

pub struct TelemetrySidecarLogSubmitter<'a> {
    client: &'a InProcessTelemetryClient,
}

impl<'a> TelemetrySidecarLogSubmitter<'a> {
    pub fn create(client: &'a InProcessTelemetryClient) -> Box<dyn TelemetryLogSubmitter + 'a> {
        Box::new(Self { client })
    }
}

fn to_telemetry_log_level(level: LogLevel) -> TelemetryLogLevel {
    match level {
        LogLevel::Error => TelemetryLogLevel::Error,
        LogLevel::Warn => TelemetryLogLevel::Warn,
        LogLevel::Debug => TelemetryLogLevel::Debug,
    }
}

impl TelemetryLogSubmitter for TelemetrySidecarLogSubmitter<'_> {
    fn submit_log(&mut self, mut log: TelemetryLog) {
        let mut tags = log.tags.take().unwrap_or_default();
        tags.add("helper_runtime", "rust");
        log.tags = Some(tags);

        debug!(
            "Submitting telemetry log to sidecar: identifier={}, level={:?} (raw={}), message={}",
            log.identifier, log.level, log.level as u8, log.message
        );

        let mut hasher = DefaultHasher::new();
        log.identifier.hash(&mut hasher);
        let log_id = LogIdentifier {
            identifier: hasher.finish(),
        };

        let log_data = Log {
            message: log.message,
            level: to_telemetry_log_level(log.level),
            stack_trace: log.stack_trace,
            count: 1,
            tags: log.tags.map(|t| t.into_string()).unwrap_or_default(),
            is_sensitive: log.is_sensitive,
            is_crash: false,
        };

        submit_action(
            self.client,
            InternalTelemetryAction::TelemetryAction(TelemetryActions::AddLog((log_id, log_data))),
        );
    }
}

pub struct TelemetrySidecarMetricSubmitter<'a> {
    client: &'a InProcessTelemetryClient,
}

impl<'a> TelemetrySidecarMetricSubmitter<'a> {
    pub fn create<'b>(
        client: &'a InProcessTelemetryClient,
        last_registration_time: &'b Cell<Option<Instant>>,
    ) -> Box<dyn TelemetryMetricSubmitter + 'a> {
        // Telemetry clients are evicted after 30 minutes without activity, so refresh metric
        // registration before that deadline. A newly-bound application starts with no timestamp.
        const METRICS_REGISTRATION_REFRESH: Duration = Duration::from_secs(25 * 60);

        let needs_registration = last_registration_time
            .get()
            .is_none_or(|i| i.elapsed() >= METRICS_REGISTRATION_REFRESH);
        if needs_registration {
            if let Err(err) = register_known_metrics(client) {
                warning!("Failed to register known metrics: {err}");
                return Self::noop();
            }
            last_registration_time.set(Some(Instant::now()));
        }

        Box::new(Self { client })
    }

    pub fn noop() -> Box<dyn TelemetryMetricSubmitter + 'static> {
        struct NoopTelemetryMetricSubmitter;
        impl TelemetryMetricSubmitter for NoopTelemetryMetricSubmitter {
            fn submit_metric(&mut self, key: MetricName, _value: f64, _tags: TelemetryTags) {
                debug!(
                    "Not submitting telemetry metric: key={} (see earlier warning)",
                    key.0
                );
            }
        }

        Box::new(NoopTelemetryMetricSubmitter)
    }
}

impl TelemetryMetricSubmitter for TelemetrySidecarMetricSubmitter<'_> {
    fn submit_metric(&mut self, key: MetricName, value: f64, mut tags: TelemetryTags) {
        tags.add("helper_runtime", "rust");

        debug!(
            "Submitting telemetry metric to sidecar: metric={}, value={}, tags={}",
            key.0,
            value,
            tags.clone().into_string()
        );

        let (parsed_tags, error) = parse_tags(&tags.into_string());
        if let Some(error) = error {
            info!("Failed to parse telemetry tags: {error}");
            return;
        }

        submit_action(
            self.client,
            InternalTelemetryAction::AddMetricPoint((value, key.0.to_string(), parsed_tags)),
        );
    }
}

fn submit_action(client: &InProcessTelemetryClient, action: InternalTelemetryAction) {
    if let Err(err) = client.submit(action) {
        info!("Failed to submit telemetry action: {err}");
    }
}

fn register_known_metrics(client: &InProcessTelemetryClient) -> anyhow::Result<()> {
    for metric in super::KNOWN_METRICS {
        register_metric(client, metric)?;
    }
    Ok(())
}

fn register_metric(client: &InProcessTelemetryClient, metric: &KnownMetric) -> anyhow::Result<()> {
    client
        .submit(InternalTelemetryAction::RegisterTelemetryMetric(
            MetricContext {
                name: metric.name.0.to_string(),
                tags: Vec::default(),
                metric_type: metric.metric_type,
                common: true,
                namespace: MetricNamespace::Appsec,
            },
        ))
        .map_err(|err| anyhow::anyhow!("Failed to register metric {}: {err}", metric.name.0))
}
