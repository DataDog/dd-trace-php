#[path = "telemetry/sidecar.rs"]
mod sidecar;

pub use sidecar::{resolve_symbols, TelemetrySidecarLogSubmitter};

#[derive(Debug, Clone, Copy)]
pub struct MetricName(pub &'static str);
#[derive(Debug, Clone, Copy)]
pub struct SpanMetricName(pub &'static str);
#[derive(Debug, Clone, Copy)]
pub struct SpanMetaName(pub &'static str);

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
#[repr(C)]
pub enum LogLevel {
    Error,
    Warn,
    #[allow(dead_code)]
    Debug,
}

pub trait SpanMetricsSubmitter {
    fn submit_metric(&mut self, key: SpanMetricName, value: f64);
    fn submit_meta(&mut self, key: SpanMetaName, value: String);
    fn submit_meta_dyn_key(&mut self, key: String, value: String);
    fn submit_metric_dyn_key(&mut self, key: String, value: f64);
}
pub trait SpanMetricsGenerator {
    fn generate_span_metrics(&'_ self, submitter: &mut dyn SpanMetricsSubmitter);
}

pub trait SpanMetaGenerator {
    fn generate_meta(&'_ self, submitter: &mut dyn SpanMetricsSubmitter);
}

pub trait TelemetryMetricSubmitter {
    fn submit_metric(&mut self, key: MetricName, value: f64, tags: TelemetryTags);
}

pub trait TelemetryMetricsGenerator {
    fn generate_telemetry_metrics(&self, submitter: &mut dyn TelemetryMetricSubmitter);
}

pub trait TelemetryLogSubmitter {
    fn submit_log(&mut self, log: TelemetryLog);
}
pub trait TelemetryLogsGenerator {
    fn generate_telemetry_logs(&'_ self, submitter: &mut dyn TelemetryLogSubmitter);
}

#[derive(Default, Debug, PartialEq, Eq, Clone)]
pub struct TelemetryTags {
    data: String,
}
impl TelemetryTags {
    pub fn new() -> Self {
        Self::default()
    }

    pub fn add(&mut self, key: impl AsRef<str>, value: impl AsRef<str>) -> &mut Self {
        if !self.data.is_empty() {
            self.data.push(',');
        }
        self.data.push_str(key.as_ref());
        self.data.push(':');
        self.data.push_str(value.as_ref());
        self
    }

    pub fn into_string(self) -> String {
        self.data
    }
}
impl From<TelemetryTags> for String {
    fn from(tags: TelemetryTags) -> String {
        tags.data
    }
}

pub const WAF_INIT: MetricName = MetricName("waf.init");
pub const WAF_UPDATES: MetricName = MetricName("waf.updates");
pub const WAF_REQUESTS: MetricName = MetricName("waf.requests");
pub const WAF_CONFIG_ERRORS: MetricName = MetricName("waf.config_errors");
pub const RASP_RULE_EVAL: MetricName = MetricName("rasp.rule.eval");
pub const RASP_RULE_MATCH: MetricName = MetricName("rasp.rule.match");
pub const RASP_TIMEOUT: MetricName = MetricName("rasp.timeout");

// not implemented (difficult to count requests on the helper)
#[allow(dead_code)]
pub const RC_REQUESTS_BEFORE_RUNNING: MetricName =
    MetricName("remote_config.requests_before_running");

pub const EVENT_RULES_LOADED: SpanMetricName = SpanMetricName("_dd.appsec.event_rules.loaded");
pub const EVENT_RULES_FAILED: SpanMetricName = SpanMetricName("_dd.appsec.event_rules.error_count");
pub const EVENT_RULES_ERRORS: SpanMetaName = SpanMetaName("_dd.appsec.event_rules.errors");
pub const EVENT_RULES_VERSION: SpanMetaName = SpanMetaName("_dd.appsec.event_rules.version");

pub const WAF_VERSION: SpanMetaName = SpanMetaName("_dd.appsec.waf.version");
pub const WAF_DURATION: SpanMetricName = SpanMetricName("_dd.appsec.waf.duration");
pub const RAST_DURATION: SpanMetricName = SpanMetricName("_dd.appsec.rasp.duration");
pub const RAST_RULE_EVALS: SpanMetricName = SpanMetricName("_dd.appsec.rasp.rule.eval");
pub const RAST_TIMEOUTS: SpanMetricName = SpanMetricName("_dd.appsec.rasp.timeout");

use std::collections::HashMap;
use std::sync::Mutex;

use crate::client::protocol::TelemetryMetric;

/// Collector for time-series telemetry metrics (tel_metrics in protocol)
/// (After https://github.com/DataDog/dd-trace-php/pull/3530, submissions can be
/// done directly)
#[derive(Default, Debug)]
pub struct TelemetryMetricsCollector {
    metrics: HashMap<String, Vec<TelemetryMetric>>,
}
impl TelemetryMetricsCollector {
    pub fn new() -> Self {
        Self::default()
    }

    pub fn take(self) -> HashMap<String, Vec<TelemetryMetric>> {
        self.metrics
    }

    pub fn extend(&mut self, other: TelemetryMetricsCollector) {
        for (key, values) in other.metrics {
            self.metrics.entry(key).or_default().extend(values);
        }
    }
}
impl TelemetryMetricSubmitter for TelemetryMetricsCollector {
    fn submit_metric(&mut self, key: MetricName, value: f64, tags: TelemetryTags) {
        self.metrics
            .entry(key.0.to_string())
            .or_default()
            .push(TelemetryMetric {
                value,
                tags: tags.into_string(),
            });
    }
}

#[derive(Debug, Clone)]
pub struct TelemetryLog {
    pub level: LogLevel,
    pub identifier: String,
    pub message: String,
    pub stack_trace: Option<String>,
    pub tags: Option<TelemetryTags>,
    pub is_sensitive: bool,
}

#[allow(dead_code)] // Used in TelemetryLogSubmitter impl
const MAX_PENDING_LOGS: usize = 100;

pub struct TelemetryLogsCollector {
    logs: Mutex<Vec<TelemetryLog>>,
}
impl TelemetryLogsCollector {
    pub fn new() -> Self {
        Self {
            logs: Mutex::new(Vec::new()),
        }
    }

    pub fn submit_log(&self, log: TelemetryLog) {
        let mut logs = self.logs.lock().unwrap();

        if logs.len() >= MAX_PENDING_LOGS {
            log::warn!("Pending logs queue is full, dropping log");
            return;
        }

        log::trace!(
            "submit_log [{:?}][{}]: {}",
            log.level,
            log.identifier,
            log.message
        );

        logs.push(log);
    }
}
impl Default for TelemetryLogsCollector {
    fn default() -> Self {
        Self::new()
    }
}
impl TelemetryLogSubmitter for TelemetryLogsCollector {
    fn submit_log(&mut self, log: TelemetryLog) {
        let mut logs = self.logs.lock().unwrap();

        if logs.len() >= MAX_PENDING_LOGS {
            log::warn!("Pending logs queue is full, dropping log");
            return;
        }

        log::trace!(
            "submit_log [{:?}][{}]: {}",
            log.level,
            log.identifier,
            log.message
        );

        logs.push(log);
    }
}
impl TelemetryLogsGenerator for TelemetryLogsCollector {
    fn generate_telemetry_logs(&'_ self, submitter: &mut dyn TelemetryLogSubmitter) {
        let mut logs = self.logs.lock().unwrap();
        log::debug!("Draining {} telemetry logs from collector", logs.len());
        for (i, log) in logs.drain(..).enumerate() {
            log::debug!("Submitting log {} of batch", i + 1);
            submitter.submit_log(log);
            log::debug!("Successfully submitted log {}", i + 1);
        }
        log::debug!("Finished draining all logs");
    }
}
