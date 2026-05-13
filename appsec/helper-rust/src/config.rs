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

    /// Load configuration from environment variables
    pub fn from_env() -> Result<Self> {
        let log_file_path = env::var("_DD_SIDECAR_APPSEC_LOG_FILE_PATH")
            .ok()
            .map(PathBuf::from);

        let log_level = env::var("_DD_SIDECAR_APPSEC_LOG_LEVEL")
            .map(|s| {
                if s.to_lowercase() == "warning" {
                    "warn".into()
                } else {
                    s
                }
            })
            .ok()
            .and_then(|s| log::Level::from_str(&s).ok())
            .unwrap_or(Self::DEFAULT_LOG_LEVEL);

        Ok(Config {
            log_file_path,
            log_level,
        })
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
