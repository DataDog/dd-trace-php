use anyhow::Result;
use std::env;
use std::fmt;
use std::path::PathBuf;
use std::str::FromStr;

#[derive(Clone)]
pub struct Config {
    pub log_file_path: Option<PathBuf>,
    pub log_level: log::Level,
}

impl fmt::Debug for Config {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("Config")
            .field("log_file_path", &self.log_file_path)
            .field("log_level", &self.log_level)
            .finish()
    }
}

impl Config {
    const DEFAULT_LOG_LEVEL: log::Level = log::Level::Info;

    pub fn new(log_file_path: Option<PathBuf>, log_level: &str) -> Self {
        let log_level = if log_level.eq_ignore_ascii_case("warning") {
            "warn"
        } else {
            log_level
        };

        Self {
            log_file_path,
            log_level: log::Level::from_str(log_level).unwrap_or(Self::DEFAULT_LOG_LEVEL),
        }
    }

    /// Load configuration from environment variables
    pub fn from_env() -> Result<Self> {
        let log_file_path = env::var("_DD_SIDECAR_APPSEC_LOG_FILE_PATH")
            .ok()
            .map(PathBuf::from);
        let log_level = env::var("_DD_SIDECAR_APPSEC_LOG_LEVEL").unwrap_or_default();

        Ok(Self::new(log_file_path, &log_level))
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use serial_test::serial;

    #[test]
    #[serial]
    fn test_config_from_env_with_defaults() {
        // Clear env vars to test defaults
        env::remove_var("_DD_SIDECAR_APPSEC_LOG_FILE_PATH");
        env::remove_var("_DD_SIDECAR_APPSEC_LOG_LEVEL");

        let config = Config::from_env().unwrap();

        assert_eq!(config.log_file_path, None);
        assert_eq!(config.log_level, log::Level::Info);
    }

    #[test]
    #[serial]
    fn test_config_from_env_with_custom_values() {
        env::set_var("_DD_SIDECAR_APPSEC_LOG_FILE_PATH", "/custom/log.log");
        env::set_var("_DD_SIDECAR_APPSEC_LOG_LEVEL", "debug");

        let config = Config::from_env().unwrap();

        assert_eq!(config.log_file_path, Some(PathBuf::from("/custom/log.log")));
        assert_eq!(config.log_level, log::Level::Debug);

        // Cleanup
        env::remove_var("_DD_SIDECAR_APPSEC_LOG_FILE_PATH");
        env::remove_var("_DD_SIDECAR_APPSEC_LOG_LEVEL");
    }
}
