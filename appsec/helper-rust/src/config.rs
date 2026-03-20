use anyhow::Result;
use std::env;
use std::ffi::OsString;
use std::fmt;
use std::os::unix::ffi::OsStringExt;
use std::path::PathBuf;
use std::str::FromStr;

#[derive(Clone)]
pub struct Config {
    pub socket_path: Vec<u8>,
    pub lock_path: PathBuf,
    pub log_file_path: Option<PathBuf>,
    pub log_level: log::Level,
}

impl fmt::Debug for Config {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        let socket_display = if self.socket_path.first() == Some(&0) {
            let mut display = String::from("@");
            display.push_str(&String::from_utf8_lossy(&self.socket_path[1..]));
            display
        } else {
            String::from_utf8_lossy(&self.socket_path).to_string()
        };

        f.debug_struct("Config")
            .field("socket_path", &socket_display)
            .field("lock_path", &self.lock_path)
            .field("log_file_path", &self.log_file_path)
            .field("log_level", &self.log_level)
            .finish()
    }
}

impl Config {
    #[cfg(target_os = "linux")]
    const DEFAULT_SOCKET_PATH: &'static str = "@/ddappsec";
    #[cfg(not(target_os = "linux"))]
    const DEFAULT_SOCKET_PATH: &'static str = "/tmp/ddappsec.sock";

    const DEFAULT_LOCK_PATH: &'static str = "/tmp/ddappsec.lock";
    const DEFAULT_LOG_LEVEL: log::Level = log::Level::Info;

    /// Load configuration from environment variables
    pub fn from_env() -> Result<Self> {
        let mut socket_path = env::var("_DD_SIDECAR_APPSEC_SOCKET_FILE_PATH")
            .unwrap_or_else(|_| Self::DEFAULT_SOCKET_PATH.to_string())
            .into_bytes();
        if socket_path.first() == Some(&b'@') {
            socket_path[0] = 0u8;
        }

        let lock_path = env::var("_DD_SIDECAR_APPSEC_LOCK_FILE_PATH")
            .unwrap_or_else(|_| Self::DEFAULT_LOCK_PATH.to_string())
            .into();

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
            socket_path,
            lock_path,
            log_file_path,
            log_level,
        })
    }

    pub fn socket_path_as_path(&self) -> PathBuf {
        let os = OsString::from_vec(self.socket_path.clone());
        PathBuf::from(os)
    }

    pub fn is_abstract_socket(&self) -> bool {
        self.socket_path.first() == Some(&b'\0')
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
        env::remove_var("_DD_SIDECAR_APPSEC_SOCKET_FILE_PATH");
        env::remove_var("_DD_SIDECAR_APPSEC_LOCK_FILE_PATH");
        env::remove_var("_DD_SIDECAR_APPSEC_LOG_FILE_PATH");
        env::remove_var("_DD_SIDECAR_APPSEC_LOG_LEVEL");

        let config = Config::from_env().unwrap();

        #[cfg(target_os = "linux")]
        assert_eq!(config.socket_path, b"\0/ddappsec");
        #[cfg(not(target_os = "linux"))]
        assert_eq!(config.socket_path, b"/tmp/ddappsec.sock");

        assert_eq!(config.lock_path, PathBuf::from("/tmp/ddappsec.lock"));
        assert_eq!(config.log_file_path, None);
        assert_eq!(config.log_level, log::Level::Info);
    }

    #[test]
    #[serial]
    fn test_config_from_env_with_custom_values() {
        env::set_var("_DD_SIDECAR_APPSEC_SOCKET_FILE_PATH", "/custom/socket.sock");
        env::set_var("_DD_SIDECAR_APPSEC_LOCK_FILE_PATH", "/custom/lock.lock");
        env::set_var("_DD_SIDECAR_APPSEC_LOG_FILE_PATH", "/custom/log.log");
        env::set_var("_DD_SIDECAR_APPSEC_LOG_LEVEL", "debug");

        let config = Config::from_env().unwrap();

        assert_eq!(config.socket_path, b"/custom/socket.sock");
        assert_eq!(config.lock_path, PathBuf::from("/custom/lock.lock"));
        assert_eq!(config.log_file_path, Some(PathBuf::from("/custom/log.log")));
        assert_eq!(config.log_level, log::Level::Debug);

        // Cleanup
        env::remove_var("_DD_SIDECAR_APPSEC_SOCKET_FILE_PATH");
        env::remove_var("_DD_SIDECAR_APPSEC_LOCK_FILE_PATH");
        env::remove_var("_DD_SIDECAR_APPSEC_LOG_FILE_PATH");
        env::remove_var("_DD_SIDECAR_APPSEC_LOG_LEVEL");
    }
}
