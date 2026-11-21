use std::ffi::OsStr;
use std::hash::{Hash, Hasher};
use std::os::unix::ffi::OsStrExt;
use std::path::{Path, PathBuf};

use datadog_sidecar::appsec::protocol::AppSecSessionConfig;
use datadog_sidecar::appsec::AppSecApplication;

/// The WAF settings of a connection, from its session config.
#[derive(PartialEq, Eq, Hash, Clone, Debug)]
pub struct WafSettings {
    pub rules_file: Option<String>,
    pub waf_timeout_us: Option<u64>,
    pub trace_rate_limit: u32,
    pub obfuscator_key_regex: Option<String>,
    pub obfuscator_value_regex: Option<String>,
    pub schema_extraction: SchemaExtraction,
}

impl From<&AppSecSessionConfig> for WafSettings {
    fn from(config: &AppSecSessionConfig) -> Self {
        WafSettings {
            rules_file: non_empty(&config.rules_file),
            waf_timeout_us: (config.waf_timeout_us != 0).then_some(config.waf_timeout_us),
            trace_rate_limit: config.trace_rate_limit,
            obfuscator_key_regex: non_empty(&config.obfuscator_key_regex),
            obfuscator_value_regex: non_empty(&config.obfuscator_value_regex),
            schema_extraction: SchemaExtraction {
                enabled: config.schema_extraction_enabled,
                sampling_period: config.schema_extraction_sampling_period.max(0.0),
            },
        }
    }
}

fn non_empty(value: &str) -> Option<String> {
    (!value.is_empty()).then(|| value.to_owned())
}

#[derive(Debug, Clone)]
pub struct SchemaExtraction {
    pub enabled: bool,
    pub sampling_period: f64,
}
impl PartialEq for SchemaExtraction {
    fn eq(&self, other: &Self) -> bool {
        self.enabled == other.enabled
            && self.sampling_period.to_bits() == other.sampling_period.to_bits()
    }
}
impl Eq for SchemaExtraction {}
impl Hash for SchemaExtraction {
    fn hash<H: Hasher>(&self, state: &mut H) {
        self.enabled.hash(state);
        self.sampling_period.to_bits().hash(state);
    }
}

/// Where the remote configs of the bound application are published.
#[derive(PartialEq, Eq, Hash, Clone, Debug, Default)]
pub struct RemoteConfigSettings {
    shmem_path: Option<PathBuf>,
}

impl RemoteConfigSettings {
    pub fn new(shmem_path: Option<PathBuf>) -> Self {
        Self {
            shmem_path: shmem_path.filter(|path| !path.as_os_str().is_empty()),
        }
    }

    pub fn shmem_path(&self) -> Option<&Path> {
        self.shmem_path.as_deref()
    }
}

/// The service and env telemetry is submitted for.
#[derive(PartialEq, Eq, Hash, Clone, Debug, Default)]
pub struct TelemetrySettings {
    pub service_name: String,
    pub env_name: String,
}

/// The service settings of an application, as the sidecar reports it.
pub fn application_settings(
    application: &AppSecApplication,
) -> (RemoteConfigSettings, TelemetrySettings) {
    let shmem_path = application
        .remote_config_path
        .as_ref()
        .map(|path| PathBuf::from(OsStr::from_bytes(path.as_bytes())));
    (
        RemoteConfigSettings::new(shmem_path),
        TelemetrySettings {
            service_name: application.service.clone(),
            env_name: application.env.clone(),
        },
    )
}
