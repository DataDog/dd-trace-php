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

    /// Per-(rule_type, rule_variant) RASP metrics for telemetry
    rasp_per_rule: HashMap<(String, String), RaspRuleMetrics>,

    /// Whether the WAF triggered any rules
    had_triggers: bool,

    /// Whether the request was blocked
    request_blocked: bool,

    /// Whether the input was truncated by the extension.
    /// Used as a tag on waf.requests. The separate appsec.waf.input_truncated
    /// metric was deprecated by RFC-1089, as was appsec.waf.truncated_value_size.
    /// Neither is implemented.
    input_truncated: bool,

    /// Whether the trace was rate-limited by the appsec event rate limiter
    /// (i.e. the limiter prevented force-keeping a trace that would otherwise
    /// have been force-kept).
    rate_limited: bool,
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
            rate_limited: false,
        }
    }

    pub fn set_input_truncated(&mut self, input_truncated: bool) {
        self.input_truncated = input_truncated;
    }

    pub fn set_rate_limited(&mut self, rate_limited: bool) {
        self.rate_limited = rate_limited;
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

    pub fn record_rasp_eval(
        &mut self,
        rule_type: &str,
        rule_variant: &str,
        run_output: &libddwaf::RunOutput,
    ) {
        self.rasp_duration += run_output.duration();
        self.rasp_rule_evals += 1;

        if run_output.timeout() {
            self.rasp_timeouts += 1;
        }

        let entry = self
            .rasp_per_rule
            .entry((rule_type.to_string(), rule_variant.to_string()))
            .or_default();
        entry.evals += 1;
        if run_output.has_events() {
            entry.matches += 1;
        }
        if run_output.timeout() {
            entry.timeouts += 1;
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
            actions.value().iter().any(|action| {
                matches!(
                    action.key().to_str(),
                    Some("block_request") | Some("redirect_request")
                )
            })
        })
    }
}
impl telemetry::TelemetryMetricsGenerator for WafMetrics {
    fn generate_telemetry_metrics(
        &'_ self,
        submitter: &mut dyn telemetry::TelemetryMetricSubmitter,
    ) {
        // waf.requests metrics
        // RFC-1012: all boolean tags must be emitted regardless of value.
        let mut tags = telemetry::TelemetryTags::new();
        tags.add("waf_version", crate::service::Service::waf_version());
        tags.add(
            "event_rules_version",
            self.rules_version.as_deref().unwrap_or("unknown"),
        );
        tags.add("rule_triggered", bool_tag(self.had_triggers));
        // block_failure is not tracked: the PHP layer is assumed to always succeed at blocking.
        // Therefore request_blocked == "WAF requested a block" == "block succeeded".
        // request_excluded is not tracked: libddwaf applies exclusion filters internally and
        // does not expose whether a request was excluded in RunOutput.
        tags.add("request_blocked", bool_tag(self.request_blocked));
        tags.add("waf_error", bool_tag(self.waf_hit_error));
        tags.add("waf_timeout", bool_tag(self.waf_hit_timeout));
        tags.add("input_truncated", bool_tag(self.input_truncated));
        tags.add("rate_limited", bool_tag(self.rate_limited));
        submitter.submit_metric(telemetry::WAF_REQUESTS, 1.0, tags);

        // waf.duration distribution: one observation per request, value in microseconds
        if !self.waf_duration.is_zero() {
            let mut dur_tags = telemetry::TelemetryTags::new();
            dur_tags.add("waf_version", crate::service::Service::waf_version());
            dur_tags.add(
                "event_rules_version",
                self.rules_version.as_deref().unwrap_or("unknown"),
            );
            submitter.submit_metric(
                telemetry::WAF_DURATION_DIST,
                self.waf_duration.as_micros() as f64,
                dur_tags,
            );
        }

        // rasp.duration distribution: cumulative internal libddwaf runtime per request, in microseconds
        if !self.rasp_duration.is_zero() {
            let mut dur_tags = telemetry::TelemetryTags::new();
            dur_tags.add("waf_version", crate::service::Service::waf_version());
            dur_tags.add(
                "event_rules_version",
                self.rules_version.as_deref().unwrap_or("unknown"),
            );
            submitter.submit_metric(
                telemetry::RASP_DURATION_DIST,
                self.rasp_duration.as_micros() as f64,
                dur_tags,
            );
        }

        // Rasp rule metrics
        for ((rule_type, rule_variant), metrics) in &self.rasp_per_rule {
            let mut tags = telemetry::TelemetryTags::new();
            tags.add("rule_type", rule_type);
            if !rule_variant.is_empty() {
                tags.add("rule_variant", rule_variant);
            }
            tags.add("waf_version", crate::service::Service::waf_version());
            tags.add(
                "event_rules_version",
                self.rules_version.as_deref().unwrap_or("unknown"),
            );

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
            submitter.submit_metric(
                telemetry::WAF_DURATION,
                self.waf_duration.as_micros() as f64,
            );
        }
        if !self.rasp_duration.is_zero() {
            submitter.submit_metric(
                telemetry::RAST_DURATION,
                self.rasp_duration.as_micros() as f64,
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

fn bool_tag(value: bool) -> &'static str {
    if value {
        "true"
    } else {
        "false"
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
