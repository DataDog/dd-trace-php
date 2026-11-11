//! APM_TRACING `lib_config.data_streams_enabled` (DSM2-611).
//!
//! libdd-remote-config does not parse this key yet. This module parses it next to the library's
//! `DynamicConfigFile` and carries it through the existing targeting, priority merge and restore
//! code as one more `ApmTracingConfig`. Delete it once libdd-remote-config has
//! `Configs::DataStreamsEnabled`: the library variant then replaces `ApmTracingConfig`.

use libdd_remote_config::config::agent_config::AgentConfigFile;
use libdd_remote_config::config::agent_task::AgentTaskFile;
use libdd_remote_config::config::dynamic::{self, Configs, DynamicConfigFile};
use libdd_remote_config::{
    ParseError, ParserRegistry, RemoteConfigContent, RemoteConfigProduct,
};
use serde::Deserialize;

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

#[derive(Deserialize)]
struct ExtraLibConfig {
    #[serde(default)]
    data_streams_enabled: Option<bool>,
}

#[derive(Deserialize)]
struct ExtraFile {
    lib_config: ExtraLibConfig,
}

impl RemoteConfigContent for ApmTracingConfigFile {
    const PRODUCT: RemoteConfigProduct = RemoteConfigProduct::ApmTracing;

    fn parse(data: &[u8]) -> Result<Self, ParseError> {
        let file = dynamic::parse_json(data)?;
        let extra: ExtraFile = serde_json::from_slice(data)?;
        Ok(ApmTracingConfigFile {
            file,
            data_streams_enabled: extra.lib_config.data_streams_enabled,
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
pub fn registry() -> Result<ParserRegistry, libdd_remote_config::AlreadyRegistered> {
    ParserRegistry::new()
        .with::<AgentConfigFile>()?
        .with::<AgentTaskFile>()?
        .with::<ApmTracingConfigFile>()
}

#[cfg(test)]
mod tests {
    use super::*;

    fn names(file: &ApmTracingConfigFile) -> Vec<&'static str> {
        file.configs()
            .iter()
            .map(|c| match c {
                ApmTracingConfig::DataStreamsEnabled(_) => "dsm",
                ApmTracingConfig::Lib(_) => "lib",
            })
            .collect()
    }

    fn parse(json: &str) -> ApmTracingConfigFile {
        ApmTracingConfigFile::parse(json.as_bytes()).unwrap()
    }

    #[test]
    fn parses_data_streams_enabled_both_ways() {
        for value in [true, false] {
            let file = parse(&format!(
                r#"{{"action":"enable","lib_config":{{"data_streams_enabled":{value}}}}}"#
            ));
            assert!(matches!(
                file.configs()[..],
                [ApmTracingConfig::DataStreamsEnabled(v)] if v == value
            ));
        }
    }

    #[test]
    fn absent_or_null_yields_no_entry() {
        assert!(parse(r#"{"action":"enable","lib_config":{}}"#).configs().is_empty());
        assert!(parse(r#"{"action":"enable","lib_config":{"data_streams_enabled":null}}"#)
            .configs()
            .is_empty());
    }

    #[test]
    fn keeps_library_settings_alongside() {
        let file = parse(
            r#"{"action":"enable","lib_config":{"tracing_enabled":true,"data_streams_enabled":true}}"#,
        );
        assert_eq!(names(&file), ["lib", "dsm"]);
    }

    #[test]
    fn registry_registers_apm_tracing_once() {
        registry().unwrap();
    }
}
