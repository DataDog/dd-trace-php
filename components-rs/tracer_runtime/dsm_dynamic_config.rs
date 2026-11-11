//! APM_TRACING `lib_config.data_streams_enabled` (DSM2-611).
//!
//! libdd-remote-config does not parse this key yet. This module parses it next to the library's
//! `DynamicConfigFile` and carries it through the existing targeting, priority merge and restore
//! code as one more `ApmTracingConfig`. Delete it once libdd-remote-config has
//! `Configs::DataStreamsEnabled`: the library variant then replaces `ApmTracingConfig`.

use libdd_remote_config::config::agent_config::AgentConfigFile;
use libdd_remote_config::config::agent_task::AgentTaskFile;
use libdd_remote_config::config::dynamic::{self, Configs, DynamicConfigFile};
use libdd_remote_config::{ParseError, ParserRegistry, RemoteConfigContent, RemoteConfigProduct};
use serde_json::Value;

/// INI entry behind `DD_DATA_STREAMS_ENABLED`.
pub const DATA_STREAMS_ENABLED_INI: &str = "datadog.data_streams_enabled";

/// One APM_TRACING setting: a library one, or the one this module adds.
#[derive(Clone)]
pub enum ApmTracingConfig {
    Lib(Configs),
    DataStreamsEnabled(bool),
}

/// The APM_TRACING payload: what the library parses, plus the key it does not.
#[derive(Debug)]
pub struct ApmTracingConfigFile {
    file: DynamicConfigFile,
    data_streams_enabled: Option<bool>,
}

impl RemoteConfigContent for ApmTracingConfigFile {
    const PRODUCT: RemoteConfigProduct = RemoteConfigProduct::ApmTracing;

    fn parse(data: &[u8]) -> Result<Self, ParseError> {
        let file = dynamic::parse_json(data)?;
        // Anything but a boolean is ignored, so it cannot make the file's other settings fail.
        let data_streams_enabled = serde_json::from_slice::<Value>(data)?
            .get("lib_config")
            .and_then(|lib_config| lib_config.get("data_streams_enabled"))
            .and_then(Value::as_bool);
        Ok(ApmTracingConfigFile {
            file,
            data_streams_enabled,
        })
    }
}

impl ApmTracingConfigFile {
    /// Merge priority, see `DynamicConfigFile::priority`.
    pub fn priority(&self) -> u8 {
        self.file.priority()
    }

    pub fn configs(&self) -> Vec<ApmTracingConfig> {
        let mut configs: Vec<ApmTracingConfig> = <Vec<Configs>>::from(self.file.lib_config.clone())
            .into_iter()
            .map(ApmTracingConfig::Lib)
            .collect();
        if let Some(enabled) = self.data_streams_enabled {
            configs.push(ApmTracingConfig::DataStreamsEnabled(enabled));
        }
        configs
    }
}

/// `libdd_remote_config::default_registry()` with `ApmTracingConfigFile` in place of the
/// library's APM_TRACING parser, which a registry cannot replace.
///
/// The parser list is copied from `default_registry()`: update it whenever the libdatadog
/// submodule changes that list.
pub fn registry() -> Result<ParserRegistry, libdd_remote_config::AlreadyRegistered> {
    ParserRegistry::new()
        .with::<AgentConfigFile>()?
        .with::<AgentTaskFile>()?
        .with::<ApmTracingConfigFile>()
}

#[cfg(test)]
mod tests {
    use super::*;

    fn parse(json: &str) -> ApmTracingConfigFile {
        ApmTracingConfigFile::parse(json.as_bytes()).unwrap()
    }

    fn lib_config(json: &str) -> String {
        format!(r#"{{"action":"enable","lib_config":{json}}}"#)
    }

    fn is_lib(config: &ApmTracingConfig, expected: fn(&Configs) -> bool) -> bool {
        matches!(config, ApmTracingConfig::Lib(c) if expected(c))
    }

    #[test]
    fn parses_data_streams_enabled_both_ways() {
        for value in [true, false] {
            let file = parse(&lib_config(&format!(
                r#"{{"data_streams_enabled":{value}}}"#
            )));
            assert!(matches!(
                file.configs()[..],
                [ApmTracingConfig::DataStreamsEnabled(v)] if v == value
            ));
        }
    }

    #[test]
    fn absent_or_null_yields_no_entry() {
        assert!(parse(&lib_config("{}")).configs().is_empty());
        assert!(parse(&lib_config(r#"{"data_streams_enabled":null}"#))
            .configs()
            .is_empty());
    }

    #[test]
    fn non_boolean_is_ignored_and_siblings_still_apply() {
        for value in [r#""true""#, "1", "0", "[]", "{}"] {
            let file = parse(&lib_config(&format!(
                r#"{{"tracing_enabled":true,"tracing_sampling_rate":0.5,"data_streams_enabled":{value}}}"#
            )));
            let configs = file.configs();
            assert_eq!(configs.len(), 2, "value {value}");
            assert!(is_lib(
                &configs[0],
                |c| matches!(c, Configs::TracingSamplingRate(r) if *r == 0.5)
            ));
            assert!(is_lib(&configs[1], |c| matches!(
                c,
                Configs::TracingEnabled(true)
            )));
        }
    }

    #[test]
    fn missing_lib_config_is_an_error_as_in_the_library() {
        assert!(ApmTracingConfigFile::parse(br#"{"action":"enable"}"#).is_err());
        assert!(ApmTracingConfigFile::parse(b"not json").is_err());
    }

    #[test]
    fn keeps_library_settings_alongside() {
        let file = parse(&lib_config(
            r#"{"tracing_enabled":true,"data_streams_enabled":true}"#,
        ));
        let configs = file.configs();
        assert_eq!(configs.len(), 2);
        assert!(is_lib(&configs[0], |c| matches!(
            c,
            Configs::TracingEnabled(true)
        )));
        assert!(matches!(
            configs[1],
            ApmTracingConfig::DataStreamsEnabled(true)
        ));
    }

    #[test]
    fn priority_follows_the_service_target() {
        let with_target = |target: &str| {
            parse(&format!(
                r#"{{"action":"enable","service_target":{target},"lib_config":{{}}}}"#
            ))
            .priority()
        };
        assert_eq!(with_target(r#"{"service":"s","env":"e"}"#), 0);
        assert_eq!(with_target(r#"{"service":"s","env":"*"}"#), 1);
        assert_eq!(with_target(r#"{"service":"*","env":"e"}"#), 2);
        assert_eq!(parse(&lib_config("{}")).priority(), 4);
    }

    #[test]
    fn registry_parses_apm_tracing_into_this_type() {
        let registry = registry().unwrap();
        let payload = lib_config(r#"{"data_streams_enabled":true}"#);
        let parsed = registry
            .parse(RemoteConfigProduct::ApmTracing, payload.as_bytes())
            .unwrap()
            .expect("APM_TRACING has a parser");
        let file = parsed
            .downcast::<ApmTracingConfigFile>()
            .expect("parsed as ApmTracingConfigFile");
        assert!(matches!(
            file.configs()[..],
            [ApmTracingConfig::DataStreamsEnabled(true)]
        ));
        assert!(parsed.downcast::<DynamicConfigFile>().is_none());
    }

    #[test]
    fn registry_still_parses_the_default_products() {
        let registry = registry().unwrap();
        for product in [
            RemoteConfigProduct::AgentConfig,
            RemoteConfigProduct::AgentTask,
            RemoteConfigProduct::ApmTracing,
        ] {
            // A parser exists if parsing does not return Ok(None); the payload may be rejected.
            let outcome = registry.parse(product, b"{}");
            assert!(!matches!(outcome, Ok(None)), "no parser for {product:?}");
        }
    }
}
