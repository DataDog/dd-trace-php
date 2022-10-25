use crate::bindings::{
    datadog_php_profiling_parse_utf8, zai_config_entry, zai_config_get_value, zai_config_minit,
    zai_config_name, zai_config_system_ini_change, zai_string_view, zend_long, zval, ZaiConfigType,
    IS_LONG, ZAI_CONFIG_ENTRIES_COUNT_MAX,
};
use crate::sapi_getenv;
pub use datadog_profiling::exporter::Uri;
use libc::{c_char, c_void, memcpy};
use log::{warn, LevelFilter};
use std::ffi::CStr;
use std::fmt::{Display, Formatter};
use std::mem::transmute;
use std::path::Path;
pub use std::path::PathBuf;
use std::str::FromStr;
use ConfigId::*;

pub struct Env {
    pub agent_host: Option<String>,
    pub env: Option<String>,
    pub profiling_enabled: Option<String>,
    pub profiling_endpoint_collection_enabled: Option<String>,
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
        let val = val.into_c_string();
        if val.is_some() {
            return val.map(|c| c.to_string_lossy().to_string());
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

        // treat empty strings the same as no string
        if val.to_bytes().is_empty() {
            None
        } else {
            Some(val.to_string_lossy().to_string())
        }
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

        let profiling_endpoint_collection_enabled = Self::getenv(
            CStr::from_bytes_with_nul_unchecked(b"DD_PROFILING_ENDPOINT_COLLECTION_ENABLED\0"),
        );

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
            profiling_endpoint_collection_enabled,
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

unsafe extern "C" fn env_to_ini_name(env_name: zai_string_view, ini_name: *mut zai_config_name) {
    assert!(!ini_name.is_null());
    let ini_name = &mut *ini_name;

    let name: &str = env_name.to_utf8().unwrap();

    assert!(name.starts_with("DD_"));

    // Env var name needs to fit.
    let projection = "datadog.".len() - "DD_".len();
    let null_byte = 1usize;
    assert!(name.len() + projection + null_byte < ZAI_CONFIG_ENTRIES_COUNT_MAX as usize);

    let (dest_prefix, src_prefix) = if name.starts_with("DD_TRACE_") {
        ("datadog.trace.", "DD_TRACE_")
    } else if name.starts_with("DD_PROFILING_") {
        ("datadog.profiling.", "DD_PROFILING_")
    } else if name.starts_with("DD_APPSEC_") {
        ("datadog.appsec.", "DD_APPSEC_")
    } else {
        ("datadog.", "DD_")
    };

    memcpy(
        ini_name.ptr.as_mut_ptr() as *mut c_void,
        dest_prefix.as_ptr() as *const c_void,
        dest_prefix.len(),
    );

    // Copy in the parts after the prefix, lowercasing as we go. For example,
    // with DD_PROFILING_ENABLED copy `ENABLED` as `enabled` into the
    // destination slice.
    let dest_suffix = &mut ini_name.ptr[dest_prefix.len()..];
    let src_suffix = &name[src_prefix.len()..];
    for (dest, src) in dest_suffix.iter_mut().zip(src_suffix.bytes()) {
        *dest = transmute(src.to_ascii_lowercase());
    }

    // Add the null terminator.
    dest_suffix[src_suffix.len()] = transmute(b'\0');

    // Store the length without the null.
    ini_name.len = (dest_prefix.len() + src_suffix.len()).try_into().unwrap();
}

/// # Safety
/// This function must only be called after config has been initialized in
/// rinit, and before it is uninitialized in rshutdown.
pub(crate) unsafe fn get_value(id: ConfigId) -> &'static mut zval {
    let value = zai_config_get_value(transmute(id));
    // Panic: the implementation makes this guarantee.
    assert!(!value.is_null());
    &mut *value
}

#[repr(u16)]
#[derive(Clone, Copy)]
pub(crate) enum ConfigId {
    ProfilingEnabled = 0,
    ProfilingEndpointCollectionEnabled,
    ProfilingExperimentalCpuTimeEnabled,
    ProfilingLogLevel,

    // todo: do these need to be kept in sync with the tracer?
    AgentHost,
    Env,
    Service,
    Tags,
    TraceAgentPort,
    TraceAgentUrl,
    Version,
}

impl ConfigId {
    const fn to_env_var_name(&self) -> zai_string_view {
        let bytes: &'static [u8] = match self {
            ProfilingEnabled => b"DD_PROFILING_ENABLED\0",
            ProfilingEndpointCollectionEnabled => b"DD_PROFILING_ENDPOINT_COLLECTION_ENABLED\0",
            ProfilingExperimentalCpuTimeEnabled => b"DD_PROFILING_CPU_ENABLED\0",
            ProfilingLogLevel => b"DD_PROFILING_LOG_LEVEL\0",

            AgentHost => b"DD_AGENT_HOST\0",
            Env => b"DD_ENV\0",
            Service => b"DD_SERVICE\0",
            Tags => b"DD_TAGS\0",
            TraceAgentPort => b"DD_TRACE_AGENT_PORT\0",
            TraceAgentUrl => b"DD_TRACE_AGENT_URL\0",
            Version => b"DD_VERSION\0",
        };

        // Safety: all these byte strings are [CStr::from_bytes_with_nul_unchecked] compatible.
        unsafe { zai_string_view::literal(bytes) }
    }
}

/// # Safety
/// This function must only be called after config has been initialized in
/// rinit, and before it is uninitialized in mshutdown.
pub(crate) unsafe fn profiling_enabled() -> bool {
    get_bool(ConfigId::ProfilingEnabled, false)
}

/// # Safety
/// This function must only be called after config has been initialized in
/// rinit, and before it is uninitialized in mshutdown.
pub(crate) unsafe fn profiling_endpoint_collection_enabled() -> bool {
    get_bool(ConfigId::ProfilingEndpointCollectionEnabled, true)
}

/// # Safety
/// This function must only be called after config has been initialized in
/// rinit, and before it is uninitialized in mshutdown.
pub(crate) unsafe fn profiling_experimental_cpu_time_enabled() -> bool {
    get_bool(ProfilingExperimentalCpuTimeEnabled, true)
}

unsafe fn get_bool(id: ConfigId, default: bool) -> bool {
    get_value(id).try_into().unwrap_or(default)
}

unsafe fn get_str(id: ConfigId) -> Option<&'static str> {
    let str: Result<&str, _> = get_value(id).try_into();
    match str {
        Ok(value) => {
            if value.is_empty() {
                None
            } else {
                Some(value)
            }
        }
        Err(_err) => None,
    }
}

/// # Safety
/// This function must only be called after config has been initialized in
/// rinit, and before it is uninitialized in mshutdown.
pub(crate) unsafe fn agent_host() -> Option<&'static str> {
    get_str(ConfigId::AgentHost)
}

/// # Safety
/// This function must only be called after config has been initialized in
/// rinit, and before it is uninitialized in mshutdown.
pub(crate) unsafe fn env() -> Option<&'static str> {
    get_str(ConfigId::Env)
}

/// # Safety
/// This function must only be called after config has been initialized in
/// rinit, and before it is uninitialized in mshutdown.
pub(crate) unsafe fn service() -> Option<&'static str> {
    get_str(ConfigId::Service)
}

/// # Safety
/// This function must only be called after config has been initialized in
/// rinit, and before it is uninitialized in mshutdown.
pub(crate) unsafe fn version() -> Option<&'static str> {
    get_str(ConfigId::Version)
}

/// # Safety
/// This function must only be called after config has been initialized in
/// rinit, and before it is uninitialized in mshutdown.
pub(crate) unsafe fn trace_agent_port() -> Option<u16> {
    let port = get_value(ConfigId::TraceAgentPort)
        .try_into()
        .unwrap_or(0 as zend_long);
    if port <= 0 || port > (u16::MAX as zend_long) {
        None
    } else {
        Some(port as u16)
    }
}

/// # Safety
/// This function must only be called after config has been initialized in
/// rinit, and before it is uninitialized in mshutdown.
pub(crate) unsafe fn trace_agent_url() -> Option<&'static str> {
    get_str(ConfigId::TraceAgentUrl)
}

/// # Safety
/// This function must only be called after config has been initialized in
/// rinit, and before it is uninitialized in mshutdown.
pub(crate) unsafe fn profiling_log_level() -> LevelFilter {
    let value: Result<zend_long, u8> = get_value(ConfigId::ProfilingLogLevel).try_into();
    match value {
        // If this is an lval, then we know we can transmute it because the parser worked.
        Ok(enabled) => transmute(enabled),
        Err(zval_type) => {
            warn!(
                "zval of type {} encountered when calling config::profiling_log_level(), expected type int ({})",
                zval_type, IS_LONG);
            LevelFilter::Off // the default is off
        }
    }
}

unsafe extern "C" fn parse_level_filter(
    value: zai_string_view,
    decoded_value: *mut zval,
    _persistent: bool,
) -> bool {
    if value.ptr.is_null() || decoded_value.is_null() {
        return false;
    }

    let decoded_value = &mut *decoded_value;
    match value.to_utf8() {
        Ok(level) => match LevelFilter::from_str(level) {
            Ok(filter) => {
                decoded_value.value.lval = filter as zend_long;
                decoded_value.u1.type_info = IS_LONG;
                true
            }
            _ => false,
        },
        _ => false,
    }
}

unsafe extern "C" fn parse_utf8_string(
    value: zai_string_view,
    decoded_value: *mut zval,
    persistent: bool,
) -> bool {
    if value.ptr.is_null() || decoded_value.is_null() {
        return false;
    }

    match value.to_utf8() {
        Ok(utf8) => {
            let ptr = utf8.as_ptr() as *const c_char;
            let len = utf8.len() as u64;
            datadog_php_profiling_parse_utf8(decoded_value, ptr, len, persistent);
            true
        }
        Err(e) => {
            warn!("Error while running config::parse_utf8_string(): {}", e);
            false
        }
    }
}

pub(crate) fn minit(module_number: libc::c_int) {
    unsafe {
        const CPU_TIME_ALIASES: &[zai_string_view] = unsafe {
            &[
                zai_string_view::literal(b"DD_PROFILING_EXPERIMENTAL_CPU_ENABLED\0"),
                zai_string_view::literal(b"DD_PROFILING_EXPERIMENTAL_CPU_TIME_ENABLED\0"),
            ]
        };

        // Note that function pointers cannot appear in const functions, so we
        // can't extract each entry into a helper function.
        static mut ENTRIES: &mut [zai_config_entry] = unsafe {
            &mut [
                zai_config_entry {
                    id: transmute(ProfilingEnabled),
                    name: ProfilingEnabled.to_env_var_name(),
                    type_: ZaiConfigType::Bool,
                    default_encoded_value: zai_string_view::literal(b"no\0"),
                    aliases: std::ptr::null_mut(),
                    aliases_count: 0,
                    ini_change: None,
                    parser: None,
                },
                zai_config_entry {
                    id: transmute(ProfilingEndpointCollectionEnabled),
                    name: ProfilingEndpointCollectionEnabled.to_env_var_name(),
                    type_: ZaiConfigType::Bool,
                    default_encoded_value: zai_string_view::literal(b"yes\0"),
                    aliases: CPU_TIME_ALIASES.as_ptr(),
                    aliases_count: CPU_TIME_ALIASES.len() as u8,
                    ini_change: None,
                    parser: None,
                },
                zai_config_entry {
                    id: transmute(ProfilingExperimentalCpuTimeEnabled),
                    name: ProfilingExperimentalCpuTimeEnabled.to_env_var_name(),
                    type_: ZaiConfigType::Bool,
                    default_encoded_value: zai_string_view::literal(b"yes\0"),
                    aliases: std::ptr::null_mut(), // todo: ALIASES
                    aliases_count: 0,
                    ini_change: None,
                    parser: None,
                },
                zai_config_entry {
                    id: transmute(ProfilingLogLevel),
                    name: ProfilingLogLevel.to_env_var_name(),
                    type_: ZaiConfigType::Custom, // store it as an int
                    default_encoded_value: zai_string_view::literal(b"off\0"),
                    aliases: std::ptr::null_mut(),
                    aliases_count: 0,
                    ini_change: Some(zai_config_system_ini_change),
                    parser: Some(parse_level_filter),
                },
                zai_config_entry {
                    id: transmute(AgentHost),
                    name: AgentHost.to_env_var_name(),
                    type_: ZaiConfigType::String,
                    default_encoded_value: zai_string_view::new(),
                    aliases: std::ptr::null_mut(),
                    aliases_count: 0,
                    ini_change: Some(zai_config_system_ini_change),
                    parser: Some(parse_utf8_string),
                },
                zai_config_entry {
                    id: transmute(Env),
                    name: Env.to_env_var_name(),
                    type_: ZaiConfigType::String,
                    default_encoded_value: zai_string_view::new(),
                    aliases: std::ptr::null_mut(),
                    aliases_count: 0,
                    ini_change: None,
                    parser: Some(parse_utf8_string),
                },
                zai_config_entry {
                    id: transmute(Service),
                    name: Service.to_env_var_name(),
                    type_: ZaiConfigType::String,
                    default_encoded_value: zai_string_view::new(),
                    aliases: std::ptr::null_mut(),
                    aliases_count: 0,
                    ini_change: None,
                    parser: Some(parse_utf8_string),
                },
                zai_config_entry {
                    id: transmute(Tags),
                    name: Tags.to_env_var_name(),
                    type_: ZaiConfigType::Map,
                    default_encoded_value: zai_string_view::new(),
                    aliases: std::ptr::null_mut(),
                    aliases_count: 0,
                    ini_change: None,
                    parser: None,
                },
                zai_config_entry {
                    id: transmute(TraceAgentPort),
                    name: TraceAgentPort.to_env_var_name(),
                    type_: ZaiConfigType::Int,
                    default_encoded_value: zai_string_view::literal(b"0\0"),
                    aliases: std::ptr::null_mut(),
                    aliases_count: 0,
                    ini_change: Some(zai_config_system_ini_change),
                    parser: Some(parse_utf8_string),
                },
                zai_config_entry {
                    id: transmute(TraceAgentUrl),
                    name: TraceAgentUrl.to_env_var_name(),
                    type_: ZaiConfigType::String, // TYPE?
                    default_encoded_value: zai_string_view::new(),
                    aliases: std::ptr::null_mut(),
                    aliases_count: 0,
                    ini_change: Some(zai_config_system_ini_change),
                    parser: Some(parse_utf8_string),
                },
                zai_config_entry {
                    id: transmute(Version),
                    name: Version.to_env_var_name(),
                    type_: ZaiConfigType::String,
                    default_encoded_value: zai_string_view::new(),
                    aliases: std::ptr::null_mut(),
                    aliases_count: 0,
                    ini_change: None,
                    parser: Some(parse_utf8_string),
                },
            ]
        };

        let tmp = zai_config_minit(
            ENTRIES.as_mut_ptr(),
            ENTRIES.len().try_into().unwrap(),
            Some(env_to_ini_name),
            module_number,
        );
        assert!(tmp); // It's literally return true in the source.
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use libc::memcmp;
    use std::mem::MaybeUninit;

    #[test]
    fn test_env_to_ini_name() {
        let cases: &[(&[u8], &str)] = &[
            (b"DD_SERVICE\0", "datadog.service"),
            (b"DD_ENV\0", "datadog.env"),
            (b"DD_VERSION\0", "datadog.version"),
            (b"DD_TRACE_AGENT_URL\0", "datadog.trace.agent_url"),
            (b"DD_TRACE_AGENT_PORT\0", "datadog.trace.agent_port"),
            (b"DD_AGENT_HOST\0", "datadog.agent_host"),
            (b"DD_PROFILING_ENABLED\0", "datadog.profiling.enabled"),
            (
                b"DD_PROFILING_ENDPOINT_COLLECTION_ENABLED\0",
                "datadog.profiling.endpoint_collection_enabled",
            ),
            (
                b"DD_PROFILING_EXPERIMENTAL_CPU_TIME_ENABLED\0",
                "datadog.profiling.experimental_cpu_time_enabled",
            ),
            (b"DD_PROFILING_LOG_LEVEL\0", "datadog.profiling.log_level"),
        ];

        for (env_name, expected_ini_name) in cases {
            unsafe {
                let env = zai_string_view::literal(env_name);
                let mut ini = MaybeUninit::uninit();
                env_to_ini_name(env, ini.as_mut_ptr());
                let ini = ini.assume_init();

                // Check that .len matches.
                assert_eq!(
                    expected_ini_name.len(),
                    ini.len as usize,
                    "Env: {}, expected ini: {}",
                    std::str::from_utf8(env_name).unwrap(),
                    expected_ini_name
                );

                // Check that the bytes match.
                let cmp = memcmp(
                    transmute(expected_ini_name.as_ptr()),
                    transmute(ini.ptr.as_ptr()),
                    expected_ini_name.len(),
                );
                assert_eq!(0, cmp);

                // Check that it is null terminated.
                assert_eq!(ini.ptr[ini.len as usize] as u8, b'\0');
            }
        }
    }
}
