use crate::{
    client::log::{debug, warning},
    rc::ParsedConfigKey,
    telemetry::{
        LogLevel, TelemetryLog, TelemetryLogsCollector, TelemetryMetricSubmitter, TelemetryTags,
        WAF_CONFIG_ERRORS,
    },
};

use super::{InitDiagnosticsLegacy, Service};

const DIAGNOSTIC_KEYS: &[&str] = &[
    "actions",
    "custom_rules",
    "exclusion_data",
    "exclusions",
    "processors",
    "rules",
    "rules_data",
    "rules_override",
    "scanners",
];

pub fn report_diagnostics_errors(
    rc_path: &str,
    diagnostics: &libddwaf::object::WafOwnedDefaultAllocator<libddwaf::object::WafMap>,
    rules_version: &str,
    metric_submitter: &mut impl TelemetryMetricSubmitter,
    log_submitter: &TelemetryLogsCollector,
) {
    use libddwaf::object::WafObjectType;

    let maybe_parsed_key = ParsedConfigKey::from_rc_path(rc_path);
    let parsed_key = match maybe_parsed_key {
        Some(parsed_key) => {
            debug!(
                "Processing diagnostics for {}: {} keys",
                rc_path,
                diagnostics.len()
            );
            parsed_key
        }
        None => {
            warning!("Failed to parse config key for {}", rc_path,);
            return;
        }
    };

    for &config_key in DIAGNOSTIC_KEYS {
        let Some(keyed) = diagnostics.get_str(config_key) else {
            continue;
        };
        let value = keyed.value();
        if value.object_type() != WafObjectType::Map {
            warning!(
                "Diagnostic key {} for {} is not a map, skipping",
                config_key,
                rc_path
            );
            continue;
        }
        let map = value
            .as_type::<libddwaf::object::WafMap>()
            .expect("type check");
        if map.is_empty() {
            continue;
        }

        debug!(
            "Diagnostic {} for {} has {} entries",
            config_key,
            rc_path,
            map.len()
        );
        for kv in map.iter() {
            let key_str = kv
                .key()
                .as_type::<libddwaf::object::WafString>()
                .and_then(|s| s.as_str().ok());
            debug!(
                "  - key: {:?}, type: {:?}",
                key_str,
                kv.value().object_type()
            );
        }

        let mut tags = TelemetryTags::new();
        tags.add("waf_version", Service::waf_version())
            .add("event_rules_version", rules_version)
            .add("config_key", config_key);

        if let Some(error_keyed) = map.get_str("error") {
            if error_keyed.value().object_type() == WafObjectType::String {
                tags.add("scope", "top-level");
                metric_submitter.submit_metric(WAF_CONFIG_ERRORS, 1.0, tags);

                let message = error_keyed
                    .value()
                    .as_type::<libddwaf::object::WafString>()
                    .and_then(|s| s.as_str().ok().map(|s| s.to_string()))
                    .unwrap_or_default();
                submit_diagnostic_log(
                    log_submitter,
                    &parsed_key,
                    config_key,
                    "error",
                    LogLevel::Error,
                    message,
                );
                continue;
            }
        }

        if let Some(errors_keyed) = map.get_str("errors") {
            let errors = errors_keyed.value();
            if errors.object_type() == WafObjectType::Map {
                let errors_map = errors
                    .as_type::<libddwaf::object::WafMap>()
                    .expect("type check");
                if !errors_map.is_empty() {
                    let mut error_count: u64 = 0;
                    for kv in errors_map.iter() {
                        let arr = kv.value();
                        if arr.object_type() == WafObjectType::Array {
                            error_count += arr
                                .as_type::<libddwaf::object::WafArray>()
                                .expect("type check")
                                .len() as u64;
                        }
                    }

                    if error_count > 0 {
                        let mut error_tags = tags.clone();
                        error_tags.add("scope", "item");
                        metric_submitter.submit_metric(
                            WAF_CONFIG_ERRORS,
                            error_count as f64,
                            error_tags,
                        );

                        let message = waf_object_to_json(errors);
                        submit_diagnostic_log(
                            log_submitter,
                            &parsed_key,
                            config_key,
                            "errors",
                            LogLevel::Error,
                            message,
                        );
                    }
                }
            }
        }

        if let Some(warnings_keyed) = map.get_str("warnings") {
            let warnings = warnings_keyed.value();
            if warnings.object_type() == WafObjectType::Map {
                let warnings_map = warnings
                    .as_type::<libddwaf::object::WafMap>()
                    .expect("type check");
                if !warnings_map.is_empty() {
                    let message = waf_object_to_json(warnings);
                    submit_diagnostic_log(
                        log_submitter,
                        &parsed_key,
                        config_key,
                        "warnings",
                        LogLevel::Warn,
                        message,
                    );
                }
            }
        }
    }
}

pub fn extract_ruleset_version(
    diagnostics: &libddwaf::object::WafOwnedDefaultAllocator<libddwaf::object::WafMap>,
) -> Option<String> {
    use libddwaf::object::WafObjectType;

    let version_keyed = diagnostics.get_str("ruleset_version")?;
    let version = version_keyed.value();
    if version.object_type() != WafObjectType::String {
        return None;
    }
    version
        .as_type::<libddwaf::object::WafString>()
        .and_then(|s| s.as_str().ok())
        .map(|s| s.to_string())
}

pub fn extract_init_diagnostics_legacy(
    diagnostics: &libddwaf::object::WafOwnedDefaultAllocator<libddwaf::object::WafMap>,
) -> InitDiagnosticsLegacy {
    use libddwaf::object::WafObjectType;

    let mut result = InitDiagnosticsLegacy::default();

    let Some(rules_keyed) = diagnostics.get_str("rules") else {
        return result;
    };
    let rules = rules_keyed.value();
    if rules.object_type() != WafObjectType::Map {
        return result;
    }
    let rules_map = rules
        .as_type::<libddwaf::object::WafMap>()
        .expect("type check");

    if let Some(loaded_keyed) = rules_map.get_str("loaded") {
        let loaded = loaded_keyed.value();
        if loaded.object_type() == WafObjectType::Array {
            result.rules_loaded = loaded
                .as_type::<libddwaf::object::WafArray>()
                .expect("type check")
                .len() as u32;
        }
    }

    if let Some(failed_keyed) = rules_map.get_str("failed") {
        let failed = failed_keyed.value();
        if failed.object_type() == WafObjectType::Array {
            result.rules_failed = failed
                .as_type::<libddwaf::object::WafArray>()
                .expect("type check")
                .len() as u32;
        }
    }

    if let Some(errors_keyed) = rules_map.get_str("errors") {
        let errors = errors_keyed.value();
        if errors.object_type() == WafObjectType::Map {
            result.rules_errors =
                serde_json::to_string(errors).unwrap_or_else(|_| "{}".to_string());
        }
    }

    if result.rules_errors.is_empty() {
        result.rules_errors = "{}".to_string();
    }

    result
}

fn submit_diagnostic_log(
    log_submitter: &TelemetryLogsCollector,
    parsed_key: &ParsedConfigKey,
    config_key: &str,
    suffix: &str,
    level: LogLevel,
    message: String,
) {
    let log_type = format!("rc::{}::diagnostic", parsed_key.product);
    let identifier = format!("{}::{}", log_type, suffix);
    let mut log_tags = TelemetryTags::new();
    log_tags
        .add("log_type", &log_type)
        .add("appsec_config_key", config_key)
        .add("rc_config_id", &parsed_key.config_id);
    log_submitter.submit_log(TelemetryLog {
        level,
        identifier,
        message,
        stack_trace: None,
        tags: Some(log_tags),
        is_sensitive: false,
    });
}

fn waf_object_to_json(obj: &libddwaf::object::WafObject) -> String {
    use libddwaf::object::WafObjectType;
    use serde_json::{Map, Value};

    fn convert(obj: &libddwaf::object::WafObject) -> Value {
        match obj.object_type() {
            WafObjectType::Map => {
                let map = obj
                    .as_type::<libddwaf::object::WafMap>()
                    .expect("type check");
                let mut json_map = Map::new();
                for kv in map.iter() {
                    let key = kv
                        .key()
                        .as_type::<libddwaf::object::WafString>()
                        .and_then(|s| s.as_str().ok())
                        .unwrap_or("")
                        .to_string();
                    json_map.insert(key, convert(kv.value()));
                }
                Value::Object(json_map)
            }
            WafObjectType::Array => {
                let arr = obj
                    .as_type::<libddwaf::object::WafArray>()
                    .expect("type check");
                let json_arr: Vec<Value> = arr.iter().map(convert).collect();
                Value::Array(json_arr)
            }
            WafObjectType::String => {
                let s = obj
                    .as_type::<libddwaf::object::WafString>()
                    .and_then(|s| s.as_str().ok())
                    .unwrap_or("");
                Value::String(s.to_string())
            }
            _ => Value::Null,
        }
    }

    serde_json::to_string(&convert(obj)).unwrap_or_else(|_| "{}".to_string())
}
