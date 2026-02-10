use std::{borrow::Cow, collections::HashMap, time::Duration};

use crate::telemetry;

#[derive(Default, Debug)]
pub struct CollectingMetricsSubmitter {
    meta: HashMap<Cow<'static, str>, String>,
    metrics: HashMap<Cow<'static, str>, f64>,
}
impl CollectingMetricsSubmitter {
    pub fn take_metrics(&mut self) -> HashMap<Cow<'static, str>, f64> {
        std::mem::take(&mut self.metrics)
    }
    pub fn take_meta(&mut self) -> HashMap<Cow<'static, str>, String> {
        std::mem::take(&mut self.meta)
    }
}
impl telemetry::SpanMetricsSubmitter for CollectingMetricsSubmitter {
    fn submit_metric(&mut self, key: telemetry::SpanMetricName, value: f64) {
        self.metrics.insert(key.0.into(), value);
    }
    fn submit_meta(&mut self, key: telemetry::SpanMetaName, value: String) {
        self.meta.insert(key.0.into(), value);
    }
    fn submit_meta_dyn_key(&mut self, key: String, value: String) {
        self.meta.insert(key.into(), value);
    }
    fn submit_metric_dyn_key(&mut self, key: String, value: f64) {
        self.metrics.insert(key.into(), value);
    }
}

#[derive(Default, Debug)]
pub struct WafMetrics {
    // Ruleset version (context for tag generation)
    rules_version: Option<String>,

    /// Whether a non-RASP evaluation hit an error
    waf_hit_error: bool,

    /// Total WAF execution time in milliseconds (non-RASP calls only)
    waf_duration: Duration,

    /// Whether the WAF hit a timeout during non-RASP calls
    waf_hit_timeout: bool,

    /// Total RASP execution time in milliseconds
    rasp_duration: Duration,

    /// Count of RASP rule evaluations
    rasp_rule_evals: u32,

    /// Count of RASP timeouts
    rasp_timeouts: u32,

    /// Per-rule-type RASP metrics for telemetry
    rasp_per_rule: HashMap<String, RaspRuleMetrics>,

    /// Whether the WAF triggered any rules
    had_triggers: bool,

    /// Whether the request was blocked
    request_blocked: bool,

    /// Whether the input was truncated by the extension
    input_truncated: bool,
}

#[derive(Default, Debug, Clone)]
pub struct RaspRuleMetrics {
    pub evals: u32,
    pub matches: u32,
    pub timeouts: u32,
}

impl WafMetrics {
    pub fn new(rules_version: Option<String>) -> Self {
        Self {
            rules_version,
            waf_hit_error: false,
            waf_duration: Duration::ZERO,
            waf_hit_timeout: false,
            rasp_duration: Duration::ZERO,
            rasp_rule_evals: 0,
            rasp_timeouts: 0,
            rasp_per_rule: HashMap::new(),
            had_triggers: false,
            request_blocked: false,
            input_truncated: false,
        }
    }

    pub fn set_input_truncated(&mut self, input_truncated: bool) {
        self.input_truncated = input_truncated;
    }

    pub fn record_non_rasp_error_eval(&mut self) {
        self.waf_hit_error = true;
    }

    pub fn record_non_rasp_eval(&mut self, run_output: &libddwaf::RunOutput) {
        self.waf_duration += run_output.duration();

        if run_output.timeout() {
            self.waf_hit_timeout = true;
        }
        if run_output.has_events() {
            self.had_triggers = true;
        }
        if run_output.is_blocking() {
            self.request_blocked = true;
        }
    }

    pub fn record_rasp_eval(&mut self, rule_type: &str, run_output: &libddwaf::RunOutput) {
        self.rasp_duration += run_output.duration();
        self.rasp_rule_evals += 1;

        if run_output.timeout() {
            self.rasp_timeouts += 1;
        }

        let entry = self.rasp_per_rule.entry(rule_type.to_string()).or_default();
        entry.evals += 1;

        if run_output.has_events() {
            entry.matches += 1;
        }
        if run_output.is_blocking() {
            self.request_blocked = true;
        }
    }
}
trait RunOutputExt {
    fn has_events(&self) -> bool;
    fn is_blocking(&self) -> bool;
}
impl RunOutputExt for libddwaf::RunOutput {
    fn has_events(&self) -> bool {
        self.events()
            .is_some_and(|events| !events.value().is_empty())
    }
    fn is_blocking(&self) -> bool {
        self.actions().is_some_and(|actions| {
            actions
                .value()
                .iter()
                .any(|action| matches!(action.key().to_str(), Some("block") | Some("redirect")))
        })
    }
}
impl telemetry::TelemetryMetricsGenerator for WafMetrics {
    fn generate_telemetry_metrics(
        &'_ self,
        submitter: &mut dyn telemetry::TelemetryMetricSubmitter,
    ) {
        // waf.requests metrics
        let mut tags = telemetry::TelemetryTags::new();
        tags.add("waf_version", crate::service::Service::waf_version());
        if let Some(ref rules_ver) = self.rules_version {
            tags.add("event_rules_version", rules_ver);
        }
        if self.had_triggers {
            tags.add("rule_triggered", "true");
        }
        if self.request_blocked {
            tags.add("request_blocked", "true");
        }
        if self.waf_hit_timeout {
            tags.add("waf_timeout", "true");
        }
        if self.input_truncated {
            tags.add("input_truncated", "true");
        }
        submitter.submit_metric(telemetry::WAF_REQUESTS, 1.0, tags);

        // Rasp rule metrics
        for (rule_type, metrics) in &self.rasp_per_rule {
            let mut tags = telemetry::TelemetryTags::new();
            tags.add("rule_type", rule_type);
            tags.add("waf_version", crate::service::Service::waf_version());

            if metrics.evals > 0 {
                submitter.submit_metric(
                    telemetry::RASP_RULE_EVAL,
                    metrics.evals as f64,
                    tags.clone(),
                );
            }

            if metrics.matches > 0 {
                submitter.submit_metric(
                    telemetry::RASP_RULE_MATCH,
                    metrics.matches as f64,
                    tags.clone(),
                );
            }

            // tests expect this to always be sent, even if 0
            submitter.submit_metric(telemetry::RASP_TIMEOUT, metrics.timeouts as f64, tags);
        }
    }
}

impl telemetry::SpanMetricsGenerator for WafMetrics {
    fn generate_span_metrics(&'_ self, submitter: &mut dyn telemetry::SpanMetricsSubmitter) {
        if !self.waf_duration.is_zero() {
            submitter.submit_metric(telemetry::WAF_DURATION, self.waf_duration.duration_ms_f64());
        }
        if !self.rasp_duration.is_zero() {
            submitter.submit_metric(
                telemetry::RAST_DURATION,
                self.rasp_duration.duration_ms_f64(),
            );
        }
        if self.rasp_rule_evals > 0 {
            submitter.submit_metric(telemetry::RAST_RULE_EVALS, self.rasp_rule_evals as f64);
        }
        if self.rasp_timeouts > 0 {
            submitter.submit_metric(telemetry::RAST_TIMEOUTS, self.rasp_timeouts as f64);
        }
    }
}

trait DurationExt {
    fn duration_ms_f64(&self) -> f64;
}
impl DurationExt for Duration {
    fn duration_ms_f64(&self) -> f64 {
        self.as_secs() as f64 * 1_000.0 + self.subsec_nanos() as f64 / 1_000_000.0
    }
}
