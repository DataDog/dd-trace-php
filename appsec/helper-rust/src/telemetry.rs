#[path = "telemetry/sidecar.rs"]
mod sidecar;

use crate::client::protocol::{SidecarSettings, TelemetrySettings};
use crate::ffi::sidecar_ffi::{
    ddog_MetricType, ddog_MetricType_DDOG_METRIC_TYPE_COUNT, ddog_MetricType_DDOG_METRIC_TYPE_GAUGE,
};
pub use sidecar::{resolve_symbols, TelemetrySidecarLogSubmitter, TelemetrySidecarMetricSubmitter};
use std::cell::Cell;
use std::collections::HashMap;
use std::sync::Mutex;

pub use sidecar::{SidecarReadyFuture, SidecarStatus};

#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
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
pub const HELPER_WORKER_COUNT: MetricName = MetricName("helper.service_worker_count");

#[derive(Debug, Clone, Copy)]
pub struct KnownMetric {
    pub name: MetricName,
    pub metric_type: ddog_MetricType,
}
pub const KNOWN_METRICS: &[KnownMetric] = &[
    KnownMetric {
        name: WAF_REQUESTS,
        metric_type: ddog_MetricType_DDOG_METRIC_TYPE_COUNT,
    },
    KnownMetric {
        name: WAF_UPDATES,
        metric_type: ddog_MetricType_DDOG_METRIC_TYPE_COUNT,
    },
    KnownMetric {
        name: WAF_INIT,
        metric_type: ddog_MetricType_DDOG_METRIC_TYPE_COUNT,
    },
    KnownMetric {
        name: WAF_CONFIG_ERRORS,
        metric_type: ddog_MetricType_DDOG_METRIC_TYPE_COUNT,
    },
    KnownMetric {
        name: RASP_TIMEOUT,
        metric_type: ddog_MetricType_DDOG_METRIC_TYPE_COUNT,
    },
    KnownMetric {
        name: RASP_RULE_MATCH,
        metric_type: ddog_MetricType_DDOG_METRIC_TYPE_COUNT,
    },
    KnownMetric {
        name: RASP_RULE_EVAL,
        metric_type: ddog_MetricType_DDOG_METRIC_TYPE_COUNT,
    },
    KnownMetric {
        name: HELPER_WORKER_COUNT,
        metric_type: ddog_MetricType_DDOG_METRIC_TYPE_GAUGE,
    },
];

pub fn register_known_metrics(
    sidecar_settings: &SidecarSettings,
    telemetry_settings: &TelemetrySettings,
) -> anyhow::Result<()> {
    for metric in KNOWN_METRICS {
        sidecar::register_metric_ffi(sidecar_settings, telemetry_settings, metric)?;
    }
    Ok(())
}

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

// A "Collector" is a type of fake submitter that instead of submitting telemetry
// directly, stores it inside. It can then be converted into a generator (or implements it directly)
// for submission into the real submitter.
// See TelemetryMetricsCollector and TelemetryLogsCollector.

#[derive(Default, Debug)]
pub struct TelemetryMetricsCollector {
    metrics: HashMap<MetricName, Vec<(f64, TelemetryTags)>>,
}
impl TelemetryMetricsCollector {
    pub fn into_generator(self) -> impl TelemetryMetricsGenerator {
        struct TelemetryMetricsGeneratorImpl {
            metrics: Cell<TelemetryMetricsCollector>,
        }
        impl TelemetryMetricsGenerator for TelemetryMetricsGeneratorImpl {
            fn generate_telemetry_metrics(&self, submitter: &mut dyn TelemetryMetricSubmitter) {
                for (key, values) in self.metrics.take().metrics.into_iter() {
                    for (value, tags) in values {
                        submitter.submit_metric(key, value, tags);
                    }
                }
            }
        }
        TelemetryMetricsGeneratorImpl {
            metrics: Cell::new(self),
        }
    }
}
impl TelemetryMetricSubmitter for TelemetryMetricsCollector {
    fn submit_metric(&mut self, key: MetricName, value: f64, tags: TelemetryTags) {
        self.metrics.entry(key).or_default().push((value, tags));
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
