use core::ffi::c_char;

use crate::{allocation, bindings, universal};
use core::fmt::{Display, Formatter};
use core::mem::transmute;
use core::ptr;
use core::str::FromStr;
pub use http::Uri;
use libdd_common::tag::{parse_tags, Tag};
use log::{debug, error, warn, LevelFilter};
use std::borrow::Cow;
use std::ffi::CStr;
use std::num::NonZeroU32;
use std::path::{Path, PathBuf};

#[derive(Copy, Clone, Debug, Default)]
pub enum SystemSettingsState {
    /// Indicates the system settings are not aware of the configuration at
    /// the moment.
    #[default]
    ConfigUnaware,

    /// Indicates the system settings _are_ aware of configuration at the
    /// moment.
    ConfigAware,

    /// Expressly disabled, such as a child process post-fork (forks are not
    /// currently profiled, except certain forks made by SAPIs).
    Disabled,
}

#[derive(Clone, Debug)]
pub struct SystemSettings {
    pub state: SystemSettingsState,
    pub profiling_enabled: bool,
    pub profiling_experimental_features_enabled: bool,
    pub profiling_endpoint_collection_enabled: bool,
    pub profiling_experimental_cpu_time_enabled: bool,
    pub profiling_allocation_enabled: bool,
    pub profiling_allocation_sampling_distance: NonZeroU32,
    pub profiling_timeline_enabled: bool,
    pub profiling_exception_enabled: bool,
    pub profiling_exception_message_enabled: bool,
    pub profiling_wall_time_enabled: bool,
    pub profiling_io_enabled: bool,

    // todo: can't this be Option<String>? I don't think the string can ever be static.
    pub output_pprof: Option<Cow<'static, str>>,
    pub profiling_exception_sampling_distance: u32,
    pub profiling_log_level: LevelFilter,
    pub uri: AgentEndpoint,
}

impl SystemSettings {
    /// Provides "initial" settings, which are all "off"-like values.
    pub const fn initial() -> SystemSettings {
        SystemSettings {
            state: SystemSettingsState::ConfigUnaware,
            profiling_enabled: false,
            profiling_experimental_features_enabled: false,
            profiling_endpoint_collection_enabled: false,
            profiling_experimental_cpu_time_enabled: false,
            profiling_allocation_enabled: false,
            profiling_allocation_sampling_distance: NonZeroU32::MAX,
            profiling_timeline_enabled: false,
            profiling_exception_enabled: false,
            profiling_exception_message_enabled: false,
            profiling_wall_time_enabled: false,
            profiling_io_enabled: false,
            output_pprof: None,
            profiling_exception_sampling_distance: u32::MAX,
            profiling_log_level: LevelFilter::Off,
            uri: AgentEndpoint::Socket(Cow::Borrowed(AgentEndpoint::DEFAULT_UNIX_SOCKET_PATH)),
        }
    }

    /// # Safety
    /// This function must only be called after ZAI config has been
    /// initialized, and before config is uninitialized in shutdown.
    unsafe fn new() -> SystemSettings {
        // Select agent URI/UDS.
        let agent_host = agent_host();
        let trace_agent_port = trace_agent_port();
        let trace_agent_url = trace_agent_url();
        let uri = detect_uri_from_config(trace_agent_url, agent_host, trace_agent_port);
        Self {
            state: SystemSettingsState::ConfigAware,
            profiling_enabled: profiling_enabled(),
            profiling_experimental_features_enabled: profiling_experimental_features_enabled(),
            profiling_endpoint_collection_enabled: profiling_endpoint_collection_enabled(),
            profiling_experimental_cpu_time_enabled: profiling_experimental_cpu_time_enabled(),
            profiling_allocation_enabled: profiling_allocation_enabled(),
            profiling_allocation_sampling_distance: profiling_allocation_sampling_distance(),
            profiling_timeline_enabled: profiling_timeline_enabled(),
            profiling_exception_enabled: profiling_exception_enabled(),
            profiling_exception_message_enabled: profiling_exception_message_enabled(),
            profiling_wall_time_enabled: profiling_wall_time_enabled(),
            profiling_io_enabled: profiling_io_enabled(),
            output_pprof: profiling_output_pprof(),
            profiling_exception_sampling_distance: profiling_exception_sampling_distance(),
            profiling_log_level: profiling_log_level(),
            uri,
        }
    }
}

static mut SYSTEM_SETTINGS: SystemSettings = SystemSettings::initial();

impl SystemSettings {
    /// Returns the "current" system settings, which are always memory-safe
    /// but may point to "initial" values rather than the configured ones,
    /// depending on what point in the lifecycle we're at.
    pub const fn get() -> ptr::NonNull<SystemSettings> {
        let addr = ptr::addr_of_mut!(SYSTEM_SETTINGS);
        // SAFETY: it's derived from a static variable, it's not null.
        unsafe { ptr::NonNull::new_unchecked(addr) }
    }

    /// # Safety
    /// Must be called exactly once on the first request after each minit.
    /// Must be done while the caller holds some kind of mutex across all
    /// threads. Must be done after zai config is initialized in first rinit.
    unsafe fn on_first_request() {
        let mut system_settings = SystemSettings::new();

        // Work around version-specific issues.
        if universal::has_zend_mm_set_custom_handlers_ex() {
            if allocation::allocation_ge84::first_rinit_should_disable_due_to_jit() {
                error!("Memory allocation profiling will be disabled as long as JIT is active. To enable allocation profiling disable JIT or upgrade PHP to at least version 8.4.7. See https://github.com/DataDog/dd-trace-php/pull/3199");
                system_settings.profiling_allocation_enabled = false;
            }
        } else if allocation::allocation_le83::first_rinit_should_disable_due_to_jit() {
            if crate::RUNTIME_PHP_VERSION_ID.load(std::sync::atomic::Ordering::Relaxed) >= 80400 {
                error!("Memory allocation profiling will be disabled as long as JIT is active. To enable allocation profiling disable JIT or upgrade PHP to at least version 8.4.7. See https://github.com/DataDog/dd-trace-php/pull/3199");
            } else {
                error!("Memory allocation profiling will be disabled as long as JIT is active. To enable allocation profiling disable JIT or upgrade PHP to at least version 8.1.21 or 8.2.8. See https://github.com/DataDog/dd-trace-php/pull/2088");
            }
            system_settings.profiling_allocation_enabled = false;
        }

        SystemSettings::log_state(
            (*ptr::addr_of!(SYSTEM_SETTINGS)).state,
            system_settings.state,
            "the first request was received",
        );
        ptr::addr_of_mut!(SYSTEM_SETTINGS).swap(&mut system_settings);
    }

    fn log_state(from: SystemSettingsState, to: SystemSettingsState, reason: &str) {
        debug!("SystemSettings state transitioned from {from:?} to {to:?} because {reason}.");
    }

    /// # Safety
    /// Must be called exactly once per shutdown in either mshutdown or
    /// shutdown, before zai config is shutdown.
    unsafe fn on_shutdown() {
        let system_settings = &mut *ptr::addr_of_mut!(SYSTEM_SETTINGS);
        let state = SystemSettingsState::ConfigUnaware;
        SystemSettings::log_state(
            system_settings.state,
            state,
            "a shutdown command was received",
        );
        *system_settings = SystemSettings {
            state,
            ..SystemSettings::initial()
        };
    }

    unsafe fn on_fork_in_child() {
        let system_settings = &mut *ptr::addr_of_mut!(SYSTEM_SETTINGS);
        let state = SystemSettingsState::Disabled;
        SystemSettings::log_state(
            system_settings.state,
            state,
            "the processed forked, and child processes are not profiled",
        );
        *system_settings = SystemSettings {
            state,
            ..SystemSettings::initial()
        };
    }
}

#[derive(Clone, Debug, Eq, PartialEq, Hash)]
pub enum AgentEndpoint {
    Uri(Uri),
    Socket(Cow<'static, Path>),
}

impl AgentEndpoint {
    /// This is the "default" path for the Unix domain socket for the agent.
    #[cfg(unix)]
    pub const DEFAULT_UNIX_SOCKET_PATH: &Path = {
        // The unsafe stuff is just because it's in a const context and
        // `Path::new` isn't const on stable yet.
        //
        // SAFETY: `Path` is `repr(transparent)` with `OsStr`, which is
        // transparent with a `Slice` type that is transparent with `[u8]` on
        // Unix platforms. We can cast from `*const [u8]` to `*const Path`
        // using unsized pointer-to-pointer casts and Rust guarantees that the
        // length of the metadata is preserved, and this cast is valid because
        // of the `repr(transparent)`s. The last piece is ensuring all the
        // bytes in the `[u8]` are valid for an `OsStr`, but on Unix the
        // implementation of `OsStrExt` for `OsStr` just transmutes the bytes
        // from `&[u8]` to `&OsStr`, so all the bytes must be valid.
        unsafe { &*("/var/run/datadog/apm.socket".as_bytes() as *const [u8] as *const Path) }
    };

    /// This is the "default" URI for the agent.
    pub const DEFAULT_URI_STR: &str = "http://localhost:8126";
}

impl Default for AgentEndpoint {
    /// Returns a socket configuration if the default socket exists, otherwise
    /// it returns http://localhost:8126/. This does not consult environment
    /// variables.
    fn default() -> Self {
        let path = AgentEndpoint::DEFAULT_UNIX_SOCKET_PATH;
        if path.exists() {
            return AgentEndpoint::Socket(path.into());
        }
        AgentEndpoint::Uri(Uri::from_static(AgentEndpoint::DEFAULT_URI_STR))
    }
}

impl TryFrom<AgentEndpoint> for libdd_common::Endpoint {
    type Error = anyhow::Error;

    fn try_from(value: AgentEndpoint) -> Result<Self, Self::Error> {
        libdd_common::Endpoint::try_from(&value)
    }
}

/// Timeout in milliseconds for the agent endpoint connection.
const AGENT_ENDPOINT_TIMEOUT_MS: u64 = 10_000;

impl TryFrom<&AgentEndpoint> for libdd_common::Endpoint {
    type Error = anyhow::Error;

    fn try_from(value: &AgentEndpoint) -> Result<Self, Self::Error> {
        let endpoint = match value {
            AgentEndpoint::Uri(uri) => libdd_profiling::exporter::config::agent(uri.clone()),
            AgentEndpoint::Socket(path) => libdd_profiling::exporter::config::agent_uds(path),
        }?;
        Ok(endpoint.with_timeout(AGENT_ENDPOINT_TIMEOUT_MS))
    }
}

impl Display for AgentEndpoint {
    fn fmt(&self, f: &mut Formatter<'_>) -> std::fmt::Result {
        match self {
            AgentEndpoint::Uri(uri) => write!(f, "{uri}"),
            AgentEndpoint::Socket(path) => write!(f, "unix://{}", path.to_string_lossy()),
        }
    }
}

fn detect_uri_from_config(
    url: Option<Cow<'static, str>>,
    host: Option<Cow<'static, str>>,
    port: Option<u16>,
) -> AgentEndpoint {
    /* Priority:
     *  1. DD_TRACE_AGENT_URL
     *     - RFC allows unix:///path/to/some/socket so parse these out.
     *     - Maybe emit diagnostic if an invalid URL is detected or the path is non-existent, but
     *       continue down the priority list.
     *  2. DD_AGENT_HOST and/or DD_TRACE_AGENT_PORT. If only one is set, default the other.
     *  3. Unix Domain Socket at /var/run/datadog/apm.socket
     *  4. http://localhost:8126
     */
    if let Some(trace_agent_url) = url {
        // check for UDS first
        if let Some(path) = trace_agent_url.strip_prefix("unix://") {
            let path = PathBuf::from(path);
            if path.exists() {
                return AgentEndpoint::Socket(Cow::Owned(path));
            } else {
                warn!(
                    "Unix socket specified in DD_TRACE_AGENT_URL does not exist: {} ",
                    path.to_string_lossy()
                );
            }
        } else {
            match Uri::from_str(trace_agent_url.as_ref()) {
                Ok(uri) => return AgentEndpoint::Uri(uri),
                Err(err) => warn!("DD_TRACE_AGENT_URL was not a valid URL: {err}"),
            }
        }
        // continue down priority list
    }
    if port.is_some() || host.is_some() {
        let host = host.unwrap_or(Cow::Borrowed("localhost"));
        let port = port.unwrap_or(8126u16);
        let url = if host.contains(':') {
            format!("http://[{host}]:{port}")
        } else {
            format!("http://{host}:{port}")
        };

        match Uri::from_str(url.as_str()) {
            Ok(uri) => return AgentEndpoint::Uri(uri),
            Err(err) => {
                warn!("The combination of DD_AGENT_HOST({host}) and DD_TRACE_AGENT_PORT({port}) was not a valid URL: {err}")
            }
        }
        // continue down priority list
    }

    AgentEndpoint::default()
}

/// # Safety
/// Must be called after config first_rinit and before mshutdown.
unsafe fn get_config_str(id: ConfigId) -> Option<&'static str> {
    let ptr = ddog_php_prof_config_get(transmute::<ConfigId, u16>(id));
    if ptr.is_null() {
        return None;
    }
    CStr::from_ptr(ptr).to_str().ok().filter(|s| !s.is_empty())
}

#[repr(u16)]
#[derive(Clone, Copy)]
pub(crate) enum ConfigId {
    ProfilingEnabled = 0,
    ProfilingExperimentalFeaturesEnabled,
    ProfilingEndpointCollectionEnabled,
    ProfilingExperimentalCpuTimeEnabled,
    ProfilingAllocationEnabled,
    ProfilingAllocationSamplingDistance,
    ProfilingTimelineEnabled,
    ProfilingExceptionEnabled,
    ProfilingExceptionMessageEnabled,
    ProfilingExceptionSamplingDistance,
    ProfilingExperimentalIOEnabled,
    ProfilingLogLevel,
    ProfilingOutputPprof,
    ProfilingWallTimeEnabled,

    // todo: do these need to be kept in sync with the tracer?
    AgentHost,
    Env,
    Service,
    Tags,
    TraceAgentPort,
    TraceAgentUrl,
    Version,
    GitCommitSha,
    GitRepositoryUrl,
}

use ConfigId::*;

/// Keep these in sync with the INI defaults.
static DEFAULT_SYSTEM_SETTINGS: SystemSettings = SystemSettings {
    state: SystemSettingsState::ConfigUnaware,
    profiling_enabled: true,
    profiling_experimental_features_enabled: false,
    profiling_endpoint_collection_enabled: true,
    profiling_experimental_cpu_time_enabled: true,
    profiling_allocation_enabled: true,
    // SAFETY: value is > 0.
    profiling_allocation_sampling_distance: unsafe { NonZeroU32::new_unchecked(1024 * 4096) },
    profiling_timeline_enabled: true,
    profiling_exception_enabled: true,
    profiling_exception_message_enabled: false,
    profiling_wall_time_enabled: true,
    profiling_io_enabled: false,
    output_pprof: None,
    profiling_exception_sampling_distance: 100,
    profiling_log_level: LevelFilter::Off,
    uri: AgentEndpoint::Socket(Cow::Borrowed(AgentEndpoint::DEFAULT_UNIX_SOCKET_PATH)),
};

/// # Safety
/// This function must only be called after config has been initialized in
/// rinit, and before it is uninitialized in mshutdown.
unsafe fn profiling_enabled() -> bool {
    get_system_bool(ProfilingEnabled, DEFAULT_SYSTEM_SETTINGS.profiling_enabled)
}

/// # Safety
/// This function must only be called after config has been initialized in
/// rinit, and before it is uninitialized in mshutdown.
unsafe fn profiling_experimental_features_enabled() -> bool {
    profiling_enabled()
        && get_system_bool(
            ProfilingExperimentalFeaturesEnabled,
            DEFAULT_SYSTEM_SETTINGS.profiling_experimental_features_enabled,
        )
}

/// # Safety
/// This function must only be called after config has been initialized in
/// rinit, and before it is uninitialized in mshutdown.
unsafe fn profiling_endpoint_collection_enabled() -> bool {
    profiling_enabled()
        && get_system_bool(
            ProfilingEndpointCollectionEnabled,
            DEFAULT_SYSTEM_SETTINGS.profiling_endpoint_collection_enabled,
        )
}

/// # Safety
/// This function must only be called after config has been initialized in
/// rinit, and before it is uninitialized in mshutdown.
unsafe fn profiling_experimental_cpu_time_enabled() -> bool {
    profiling_enabled()
        && (profiling_experimental_features_enabled()
            || get_system_bool(
                ProfilingExperimentalCpuTimeEnabled,
                DEFAULT_SYSTEM_SETTINGS.profiling_experimental_cpu_time_enabled,
            ))
}

/// # Safety
/// This function must only be called after config has been initialized in
/// rinit, and before it is uninitialized in mshutdown.
unsafe fn profiling_allocation_enabled() -> bool {
    profiling_enabled()
        && get_system_bool(
            ProfilingAllocationEnabled,
            DEFAULT_SYSTEM_SETTINGS.profiling_allocation_enabled,
        )
}

/// # Safety
/// This function must only be called after config has been initialized in
/// rinit, and before it is uninitialized in mshutdown.
unsafe fn profiling_allocation_sampling_distance() -> NonZeroU32 {
    let default = DEFAULT_SYSTEM_SETTINGS
        .profiling_allocation_sampling_distance
        .get();
    let int = get_system_uint32(ProfilingAllocationSamplingDistance, default);
    NonZeroU32::new(int).unwrap_or(NonZeroU32::new(default).unwrap())
}

/// # Safety
/// This function must only be called after config has been initialized in
/// rinit, and before it is uninitialized in mshutdown.
unsafe fn profiling_timeline_enabled() -> bool {
    profiling_enabled()
        && get_system_bool(
            ProfilingTimelineEnabled,
            DEFAULT_SYSTEM_SETTINGS.profiling_timeline_enabled,
        )
}

/// # Safety
/// This function must only be called after config has been initialized in
/// rinit, and before it is uninitialized in mshutdown.
unsafe fn profiling_exception_enabled() -> bool {
    profiling_enabled()
        && get_system_bool(
            ProfilingExceptionEnabled,
            DEFAULT_SYSTEM_SETTINGS.profiling_exception_enabled,
        )
}

/// # Safety
/// This function must only be called after config has been initialized in
/// rinit, and before it is uninitialized in mshutdown.
unsafe fn profiling_exception_message_enabled() -> bool {
    get_system_bool(
        ProfilingExceptionMessageEnabled,
        DEFAULT_SYSTEM_SETTINGS.profiling_exception_message_enabled,
    )
}

/// # Safety
/// This function must only be called after config has been initialized in
/// rinit, and before it is uninitialized in mshutdown.
unsafe fn profiling_exception_sampling_distance() -> u32 {
    get_system_uint32(
        ProfilingExceptionSamplingDistance,
        DEFAULT_SYSTEM_SETTINGS.profiling_exception_sampling_distance,
    )
}

/// # Safety
/// This function must only be called after config has been initialized in
/// rinit, and before it is uninitialized in mshutdown.
unsafe fn profiling_io_enabled() -> bool {
    profiling_enabled()
        && (profiling_experimental_features_enabled()
            || get_system_bool(
                ProfilingExperimentalIOEnabled,
                DEFAULT_SYSTEM_SETTINGS.profiling_io_enabled,
            ))
}

/// # Safety
/// This function must only be called after config has been initialized in
/// first rinit, and before it is uninitialized in mshutdown.
unsafe fn profiling_output_pprof() -> Option<Cow<'static, str>> {
    get_system_str(ProfilingOutputPprof)
}

/// # Safety
/// This function must only be called after config has been initialized in
/// first rinit, and before it is uninitialized in mshutdown.
unsafe fn profiling_wall_time_enabled() -> bool {
    profiling_enabled() && get_system_bool(ProfilingWallTimeEnabled, true)
}

fn parse_bool(s: &str) -> bool {
    let s = s.trim();
    s.eq_ignore_ascii_case("1")
        || s.eq_ignore_ascii_case("on")
        || s.eq_ignore_ascii_case("yes")
        || s.eq_ignore_ascii_case("true")
        || s.eq_ignore_ascii_case("auto")
        || s.parse::<i32>().unwrap_or(0) != 0
}

unsafe fn get_system_bool(id: ConfigId, default: bool) -> bool {
    get_config_str(id).map(parse_bool).unwrap_or(default)
}

unsafe fn get_system_str(config_id: ConfigId) -> Option<Cow<'static, str>> {
    get_config_str(config_id).map(|s| Cow::Owned(s.to_string()))
}

unsafe fn get_str(id: ConfigId) -> Option<String> {
    get_config_str(id).map(str::to_string)
}

unsafe fn get_system_zend_long(config_id: ConfigId) -> Result<i64, u8> {
    get_config_str(config_id)
        .and_then(|s| s.trim().parse().ok())
        .ok_or(0)
}

unsafe fn get_system_uint32(id: ConfigId, default: u32) -> u32 {
    get_config_str(id)
        .and_then(|s| s.trim().parse().ok())
        .unwrap_or(default)
}

/// # Safety
/// This function must only be called after config has been initialized in
/// first rinit, and before it is uninitialized in mshutdown.
unsafe fn agent_host() -> Option<Cow<'static, str>> {
    if ddog_php_prof_config_is_set_by_user(AgentHost as u16) {
        get_system_str(AgentHost)
    } else {
        None
    }
}

/// # Safety
/// This function must only be called after config has been initialized in
/// rinit, and before it is uninitialized in mshutdown.
pub(crate) unsafe fn env() -> Option<String> {
    get_str(Env)
}

/// # Safety
/// This function must only be called after config has been initialized in
/// rinit, and before it is uninitialized in mshutdown.
pub(crate) unsafe fn service() -> Option<String> {
    get_str(Service)
}

/// # Safety
/// This function must only be called after config has been initialized in
/// rinit, and before it is uninitialized in mshutdown.
pub(crate) unsafe fn version() -> Option<String> {
    get_str(Version)
}

/// # Safety
/// This function must only be called after config has been initialized in
/// rinit, and before it is uninitialized in mshutdown.
pub(crate) unsafe fn git_commit_sha() -> Option<String> {
    get_str(GitCommitSha)
}

/// # Safety
/// This function must only be called after config has been initialized in
/// rinit, and before it is uninitialized in mshutdown.
pub(crate) unsafe fn git_repository_url() -> Option<String> {
    get_str(GitRepositoryUrl)
}

/// # Safety
/// This function must only be called after config has been initialized in
/// rinit, and before it is uninitialized in mshutdown.
pub(crate) unsafe fn tags() -> (Vec<Tag>, Option<String>) {
    match get_str(Tags) {
        None => (Vec::new(), None),
        Some(dd_tags) => parse_tags(&dd_tags),
    }
}

/// # Safety
/// This function must only be called after config has been initialized in
/// first rinit, and before it is uninitialized in mshutdown.
unsafe fn trace_agent_port() -> Option<u16> {
    if !ddog_php_prof_config_is_set_by_user(TraceAgentPort as u16) {
        return None;
    }
    let port = get_system_zend_long(TraceAgentPort).unwrap_or(0);
    if port <= 0 || port > i64::from(u16::MAX) {
        None
    } else {
        Some(port as u16)
    }
}

/// # Safety
/// This function must only be called after config has been initialized in
/// first rinit, and before it is uninitialized in mshutdown.
unsafe fn trace_agent_url() -> Option<Cow<'static, str>> {
    get_system_str(TraceAgentUrl)
}

/// # Safety
/// This function must only be called after config has been initialized in
/// first rinit, and before it is uninitialized in mshutdown.
unsafe fn profiling_log_level() -> LevelFilter {
    if !profiling_enabled() {
        return LevelFilter::Off;
    }
    get_config_str(ProfilingLogLevel)
        .and_then(|s| LevelFilter::from_str(s.trim()).ok())
        .unwrap_or_else(|| {
            warn!("config::profiling_log_level() failed to parse");
            DEFAULT_SYSTEM_SETTINGS.profiling_log_level
        })
}

pub(crate) fn minit(module_number: libc::c_int) {
    unsafe {
        let ok = bindings::ddog_php_prof_config_minit(module_number);
        assert!(ok, "ddog_php_prof_config_minit failed");

        // SAFETY: just initialized prof config (getenv in MINIT).
        let mut system_settings = SystemSettings::new();

        // Initialize logging before allocation's rinit, as it logs.
        cfg_if::cfg_if! {
            if #[cfg(debug_assertions)] {
                log::set_max_level(system_settings.profiling_log_level);
            } else {
                crate::logging::log_init(system_settings.profiling_log_level);
            }
        }

        SystemSettings::log_state(
            (*ptr::addr_of!(SYSTEM_SETTINGS)).state,
            system_settings.state,
            "the module was initialized",
        );
        ptr::addr_of_mut!(SYSTEM_SETTINGS).swap(&mut system_settings);

        allocation::minit(&*ptr::addr_of!(SYSTEM_SETTINGS))
    }
}

pub(crate) unsafe fn first_rinit() {
    SystemSettings::on_first_request();
}

pub(crate) unsafe fn shutdown() {
    SystemSettings::on_shutdown();
}

/// # Safety
/// Must be done in the child of a forked process as soon as possible, before
/// threads are spawned.
/// However, it must be done after any config needs to be used to properly
/// shutdown other items.
pub(crate) unsafe fn on_fork_in_child() {
    SystemSettings::on_fork_in_child()
}

#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn test_env_to_ini_name_removed() {
        // Test removed: env_to_ini_name and ZaiStr no longer used (config now uses getenv/sapi_getenv)
        let _: &[(&[u8], &str)] = &[
            (b"DD_SERVICE\0", "datadog.service"),
            (b"DD_ENV\0", "datadog.env"),
            (b"DD_VERSION\0", "datadog.version"),
            (b"DD_GIT_COMMIT_SHA\0", "datadog.git_commit_sha"),
            (b"DD_GIT_REPOSITORY_URL\0", "datadog.git_repository_url"),
            (b"DD_TRACE_AGENT_URL\0", "datadog.trace.agent_url"),
            (b"DD_TRACE_AGENT_PORT\0", "datadog.trace.agent_port"),
            (b"DD_AGENT_HOST\0", "datadog.agent_host"),
            (b"DD_PROFILING_ENABLED\0", "datadog.profiling.enabled"),
            (
                b"DD_PROFILING_EXPERIMENTAL_FEATURES_ENABLED\0",
                "datadog.profiling.experimental_features_enabled",
            ),
            (
                b"DD_PROFILING_ENDPOINT_COLLECTION_ENABLED\0",
                "datadog.profiling.endpoint_collection_enabled",
            ),
            (
                b"DD_PROFILING_EXPERIMENTAL_CPU_TIME_ENABLED\0",
                "datadog.profiling.experimental_cpu_time_enabled",
            ),
            (
                b"DD_PROFILING_EXPERIMENTAL_ALLOCATION_ENABLED\0",
                "datadog.profiling.experimental_allocation_enabled",
            ),
            (
                b"DD_PROFILING_ALLOCATION_ENABLED\0",
                "datadog.profiling.allocation_enabled",
            ),
            (
                b"DD_PROFILING_EXPERIMENTAL_EXCEPTION_SAMPLING_DISTANCE\0",
                "datadog.profiling.experimental_exception_sampling_distance",
            ),
            (
                b"DD_PROFILING_EXPERIMENTAL_TIMELINE_ENABLED\0",
                "datadog.profiling.experimental_timeline_enabled",
            ),
            (
                b"DD_PROFILING_TIMELINE_ENABLED\0",
                "datadog.profiling.timeline_enabled",
            ),
            (
                b"DD_PROFILING_EXPERIMENTAL_IO_ENABLED\0",
                "datadog.profiling.experimental_io_enabled",
            ),
            (b"DD_PROFILING_LOG_LEVEL\0", "datadog.profiling.log_level"),
            (
                b"DD_PROFILING_OUTPUT_PPROF\0",
                "datadog.profiling.output_pprof",
            ),
            (
                b"DD_PROFILING_WALL_TIME_ENABLED\0",
                "datadog.profiling.wall_time_enabled",
            ),
        ];
    }

    #[test]
    fn detect_uri_from_config_works() {
        // expected
        let endpoint = detect_uri_from_config(None, None, None);
        let expected = AgentEndpoint::default();
        assert_eq!(endpoint, expected);

        // ipv4 host
        let endpoint = detect_uri_from_config(None, Some(Cow::Owned("127.0.0.1".to_owned())), None);
        let expected = AgentEndpoint::Uri(Uri::from_static("http://127.0.0.1:8126"));
        assert_eq!(endpoint, expected);

        // ipv6 host
        let endpoint = detect_uri_from_config(None, Some(Cow::Owned("::1".to_owned())), None);
        let expected = AgentEndpoint::Uri(Uri::from_static("http://[::1]:8126"));
        assert_eq!(endpoint, expected);

        // ipv6 host, custom port
        let endpoint = detect_uri_from_config(None, Some(Cow::Owned("::1".to_owned())), Some(9000));
        let expected = AgentEndpoint::Uri(Uri::from_static("http://[::1]:9000"));
        assert_eq!(endpoint, expected);

        // agent_url
        let endpoint =
            detect_uri_from_config(Some(Cow::Owned("http://[::1]:8126".to_owned())), None, None);
        let expected = AgentEndpoint::Uri(Uri::from_static("http://[::1]:8126"));
        assert_eq!(endpoint, expected);

        // fallback on non existing UDS
        let endpoint = detect_uri_from_config(
            Some(Cow::Owned("unix://foo/bar/baz/I/do/not/exist".to_owned())),
            None,
            None,
        );
        let expected = AgentEndpoint::default();
        assert_eq!(endpoint, expected);
    }
}

#[no_mangle]
pub extern "C" fn ddog_php_prof_config_get(config_id: u16) -> *const c_char {
    if config_id >= ConfigId::GitRepositoryUrl as u16 + 1 {
        return b"\0".as_ptr() as *const c_char;
    }
    b"\0".as_ptr() as *const c_char
}

#[no_mangle]
pub extern "C" fn ddog_php_prof_config_is_set_by_user(config_id: u16) -> bool {
    let _ = config_id;
    false
}

#[no_mangle]
pub extern "C" fn ddog_php_prof_config_minit(_module_number: i32) -> bool {
    true
}

#[no_mangle]
pub extern "C" fn ddog_php_prof_config_first_rinit() {}

#[no_mangle]
pub extern "C" fn ddog_php_prof_config_rinit() {}

#[no_mangle]
pub extern "C" fn ddog_php_prof_config_rshutdown() {}

#[no_mangle]
pub extern "C" fn ddog_php_prof_config_mshutdown() {}
