use crate::sapi_getenv;
pub use datadog_profiling::exporter::Uri;
use libc::c_char;
use std::ffi::CStr;
use std::fmt::{Display, Formatter};
use std::path::Path;
pub use std::path::PathBuf;

pub struct Env {
    pub agent_host: Option<String>,
    pub env: Option<String>,
    pub profiling_enabled: Option<String>,
    pub profiling_experimental_cpu_enabled: Option<String>,
    pub profiling_experimental_cpu_time_enabled: Option<String>,
    pub profiling_log_level: Option<String>,
    pub service: Option<String>,
    pub trace_agent_port: Option<String>,
    pub trace_agent_url: Option<String>,
    pub version: Option<String>,
}

impl Env {
    /// Fetches the env var represented by `name`. Treats an empty string the
    /// same as the env var not being set.
    unsafe fn getenv(name: &CStr) -> Option<String> {
        // CStr doesn't have a len() so turn it into a slice.
        let name = name.to_bytes();

        // Safety: called CStr, so invariants have all been checked by this point.
        let val = sapi_getenv(name.as_ptr() as *const c_char, name.len());
        let val = val.into_string();
        if val.is_some() {
            return val;
        }

        /* If the sapi didn't have an env var, try the libc.
         * Safety: pointer comes from valid CStr.
         */
        let val = libc::getenv(name.as_ptr() as *const c_char);
        if val.is_null() {
            return None;
        }

        /* Safety: `val` has been checked for NULL, though I haven't checked that
         * `libc::getenv` always return a string less than `isize::MAX`.
         */
        let val = CStr::from_ptr(val);
        let maybe_str = val.to_str().ok();
        // treat empty strings the same as no string
        maybe_str.filter(|str| !str.is_empty()).map(String::from)
    }

    /// # Safety
    /// This can only be called during rinit.
    pub unsafe fn get() -> Self {
        let env = Self::getenv(CStr::from_bytes_with_nul_unchecked(b"DD_ENV\0"));
        let profiling_enabled = Self::getenv(CStr::from_bytes_with_nul_unchecked(
            b"DD_PROFILING_ENABLED\0",
        ));
        let profiling_log_level = Self::getenv(CStr::from_bytes_with_nul_unchecked(
            b"DD_PROFILING_LOG_LEVEL\0",
        ));

        // This is the older, undocumented name.
        let profiling_experimental_cpu_enabled = Self::getenv(CStr::from_bytes_with_nul_unchecked(
            b"DD_PROFILING_EXPERIMENTAL_CPU_ENABLED\0",
        ));
        let profiling_experimental_cpu_time_enabled = Self::getenv(
            CStr::from_bytes_with_nul_unchecked(b"DD_PROFILING_EXPERIMENTAL_CPU_TIME_ENABLED\0"),
        );
        let agent_host = Self::getenv(CStr::from_bytes_with_nul_unchecked(b"DD_AGENT_HOST\0"));
        let trace_agent_port = Self::getenv(CStr::from_bytes_with_nul_unchecked(
            b"DD_TRACE_AGENT_PORT\0",
        ));
        let trace_agent_url =
            Self::getenv(CStr::from_bytes_with_nul_unchecked(b"DD_TRACE_AGENT_URL\0"));
        let service = Self::getenv(CStr::from_bytes_with_nul_unchecked(b"DD_SERVICE\0"));
        let version = Self::getenv(CStr::from_bytes_with_nul_unchecked(b"DD_VERSION\0"));
        Self {
            agent_host,
            env,
            profiling_enabled,
            profiling_experimental_cpu_enabled,
            profiling_experimental_cpu_time_enabled,
            profiling_log_level,
            service,
            trace_agent_port,
            trace_agent_url,
            version,
        }
    }
}

#[derive(Clone, Debug, Eq, PartialEq, Hash)]
pub enum AgentEndpoint {
    Uri(Uri),
    Socket(PathBuf),
}

impl Default for AgentEndpoint {
    /// Returns a socket configuration if the default socket exists, otherwise
    /// it returns http://localhost:8126/. This does not consult environment
    /// variables.
    fn default() -> Self {
        let path = Path::new("/var/run/datadog/apm.socket");
        if path.exists() {
            return AgentEndpoint::Socket(path.into());
        }
        AgentEndpoint::Uri(Uri::from_static("http://localhost:8126"))
    }
}

impl TryFrom<AgentEndpoint> for datadog_profiling::exporter::Endpoint {
    type Error = anyhow::Error;

    fn try_from(value: AgentEndpoint) -> Result<Self, Self::Error> {
        match value {
            AgentEndpoint::Uri(uri) => datadog_profiling::exporter::config::agent(uri),
            AgentEndpoint::Socket(path) => datadog_profiling::exporter::config::agent_uds(&path),
        }
    }
}

impl TryFrom<&AgentEndpoint> for datadog_profiling::exporter::Endpoint {
    type Error = anyhow::Error;

    fn try_from(value: &AgentEndpoint) -> Result<Self, Self::Error> {
        match value {
            AgentEndpoint::Uri(uri) => datadog_profiling::exporter::config::agent(uri.clone()),
            AgentEndpoint::Socket(path) => datadog_profiling::exporter::config::agent_uds(path),
        }
    }
}

impl Display for AgentEndpoint {
    fn fmt(&self, f: &mut Formatter<'_>) -> std::fmt::Result {
        match self {
            AgentEndpoint::Uri(uri) => write!(f, "{}", uri),
            AgentEndpoint::Socket(path) => write!(f, "unix://{}", path.to_string_lossy()),
        }
    }
}
