use crate::profiling::bindings::{
    datadog_php_profiling_copy_string_view_into_zval, ddog_php_prof_config_is_set_by_user,
    ddog_php_prof_get_memoized_config, zai_config_get_value, zend_ini_entry, zend_long,
    zend_string, zend_write, zval, StringError, ZaiStr, IS_FALSE, IS_LONG, IS_TRUE,
    ZEND_INI_DISPLAY_ORIG,
};
use crate::profiling::zend::zai_str_from_zstr;
use crate::profiling::{allocation, bindings};
use core::fmt::{Display, Formatter};
use core::mem::transmute;
use core::ptr;
use core::str::FromStr;
pub use http::Uri;
use libc::c_int;
use log::{debug, error, warn, LevelFilter};
use std::borrow::Cow;
use std::ffi::{c_char, c_void, CString};
use std::num::NonZeroU32;
use std::path::{Path, PathBuf};
use std::{slice, str};

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
    pub profiling_experimental_heap_live_enabled: bool,
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
            profiling_experimental_heap_live_enabled: false,
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
            profiling_experimental_heap_live_enabled: profiling_experimental_heap_live_enabled(),
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
        #[cfg(not(php_zend_mm_set_custom_handlers_ex))]
        if allocation::allocation_le83::first_rinit_should_disable_due_to_jit() {
            if bindings::PHP_VERSION_ID >= 80400 {
                error!("Memory allocation profiling will be disabled as long as JIT is active. To enable allocation profiling disable JIT or upgrade PHP to at least version 8.4.7. See https://github.com/DataDog/dd-trace-php/pull/3199");
            } else {
                error!("Memory allocation profiling will be disabled as long as JIT is active. To enable allocation profiling disable JIT or upgrade PHP to at least version 8.1.21 or 8.2.8. See https://github.com/DataDog/dd-trace-php/pull/2088");
            }
            system_settings.profiling_allocation_enabled = false;
            system_settings.profiling_experimental_heap_live_enabled = false;
        }
        #[cfg(php_zend_mm_set_custom_handlers_ex)]
        if allocation::allocation_ge84::first_rinit_should_disable_due_to_jit() {
            error!("Memory allocation profiling will be disabled as long as JIT is active. To enable allocation profiling disable JIT or upgrade PHP to at least version 8.4.7. See https://github.com/DataDog/dd-trace-php/pull/3199");
            system_settings.profiling_allocation_enabled = false;
            system_settings.profiling_experimental_heap_live_enabled = false;
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
/// This function must only be called after config has been initialized in
/// rinit, and before it is uninitialized in rshutdown.
pub(crate) unsafe fn get_value(id: ConfigId) -> &'static mut zval {
    let value = zai_config_get_value(id as u16);
    // Panic: the implementation makes this guarantee.
    assert!(!value.is_null());
    &mut *value
}

unsafe fn get_system_value(id: ConfigId) -> &'static mut zval {
    let value = ddog_php_prof_get_memoized_config(id);
    // Panic: the implementation makes this guarantee.
    assert!(!value.is_null());
    &mut *value
}

pub(crate) unsafe fn config_bool(id: ConfigId) -> bool {
    bool::try_from(get_value(id)).expect("generated BOOL accessor received a non-boolean zval")
}

pub(crate) unsafe fn memoized_config_bool(id: ConfigId) -> bool {
    bool::try_from(get_system_value(id))
        .expect("generated BOOL accessor received a non-boolean memoized zval")
}

pub(crate) unsafe fn config_int(id: ConfigId) -> i64 {
    zend_long::try_from(get_value(id)).expect("generated INT accessor received a non-integer zval")
        as i64
}

pub(crate) unsafe fn memoized_config_int(id: ConfigId) -> i64 {
    zend_long::try_from(get_system_value(id))
        .expect("generated INT accessor received a non-integer memoized zval") as i64
}

#[allow(dead_code)]
pub(crate) unsafe fn config_double(id: ConfigId) -> f64 {
    f64::try_from(get_value(id)).expect("generated DOUBLE accessor received a non-double zval")
}

#[allow(dead_code)]
pub(crate) unsafe fn memoized_config_double(id: ConfigId) -> f64 {
    f64::try_from(get_system_value(id))
        .expect("generated DOUBLE accessor received a non-double memoized zval")
}

pub(crate) unsafe fn config_string(id: ConfigId) -> String {
    String::try_from(get_value(id))
        .unwrap_or_else(|_| panic!("generated STRING accessor received a non-string zval"))
}

pub(crate) unsafe fn memoized_config_string(id: ConfigId) -> String {
    String::try_from(get_system_value(id))
        .unwrap_or_else(|_| panic!("generated STRING accessor received a non-string memoized zval"))
}

pub(crate) use crate::config::ConfigId;
use crate::config::ConfigId::{
    DD_AGENT_HOST as AgentHost, DD_ENV as Env, DD_GIT_COMMIT_SHA as GitCommitSha,
    DD_GIT_REPOSITORY_URL as GitRepositoryUrl,
    DD_PROFILING_ALLOCATION_ENABLED as ProfilingAllocationEnabled,
    DD_PROFILING_ALLOCATION_SAMPLING_DISTANCE as ProfilingAllocationSamplingDistance,
    DD_PROFILING_ENABLED as ProfilingEnabled,
    DD_PROFILING_ENDPOINT_COLLECTION_ENABLED as ProfilingEndpointCollectionEnabled,
    DD_PROFILING_EXCEPTION_ENABLED as ProfilingExceptionEnabled,
    DD_PROFILING_EXCEPTION_MESSAGE_ENABLED as ProfilingExceptionMessageEnabled,
    DD_PROFILING_EXCEPTION_SAMPLING_DISTANCE as ProfilingExceptionSamplingDistance,
    DD_PROFILING_EXPERIMENTAL_CPU_TIME_ENABLED as ProfilingExperimentalCpuTimeEnabled,
    DD_PROFILING_EXPERIMENTAL_FEATURES_ENABLED as ProfilingExperimentalFeaturesEnabled,
    DD_PROFILING_EXPERIMENTAL_HEAP_LIVE_ENABLED as ProfilingExperimentalHeapLiveEnabled,
    DD_PROFILING_EXPERIMENTAL_IO_ENABLED as ProfilingExperimentalIOEnabled,
    DD_PROFILING_LOG_LEVEL as ProfilingLogLevel, DD_PROFILING_OUTPUT_PPROF as ProfilingOutputPprof,
    DD_PROFILING_TIMELINE_ENABLED as ProfilingTimelineEnabled,
    DD_PROFILING_WALLTIME_ENABLED as ProfilingWallTimeEnabled, DD_SERVICE as Service,
    DD_TRACE_AGENT_PORT as TraceAgentPort, DD_TRACE_AGENT_URL as TraceAgentUrl,
    DD_VERSION as Version,
};

/// Keep these in sync with the INI defaults.
static DEFAULT_SYSTEM_SETTINGS: SystemSettings = SystemSettings {
    state: SystemSettingsState::ConfigUnaware,
    profiling_enabled: true,
    profiling_experimental_features_enabled: false,
    profiling_endpoint_collection_enabled: true,
    profiling_experimental_cpu_time_enabled: true,
    profiling_allocation_enabled: true,
    // SAFETY: value is > 0.
    profiling_allocation_sampling_distance: NonZeroU32::new(1024 * 4096).unwrap(),
    profiling_experimental_heap_live_enabled: false,
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
pub(crate) unsafe fn profiling_enabled() -> bool {
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
    let int = get_system_uint32(
        ProfilingAllocationSamplingDistance,
        DEFAULT_SYSTEM_SETTINGS
            .profiling_allocation_sampling_distance
            .get(),
    );
    // SAFETY: ProfilingAllocationSamplingDistance uses parser that ensures a
    // non-zero value.
    unsafe { NonZeroU32::new_unchecked(int) }
}

/// # Safety
/// This function must only be called after config has been initialized in
/// rinit, and before it is uninitialized in mshutdown.
///
/// Honors the umbrella `DD_PROFILING_EXPERIMENTAL_FEATURES_ENABLED` flag the
/// same way `profiling_experimental_cpu_time_enabled` does.
unsafe fn profiling_experimental_heap_live_enabled() -> bool {
    profiling_allocation_enabled()
        && (profiling_experimental_features_enabled()
            || get_system_bool(
                ProfilingExperimentalHeapLiveEnabled,
                DEFAULT_SYSTEM_SETTINGS.profiling_experimental_heap_live_enabled,
            ))
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

unsafe fn get_system_bool(id: ConfigId, default: bool) -> bool {
    get_system_value(id).try_into().unwrap_or(default)
}

unsafe fn get_bool(id: ConfigId, default: bool) -> bool {
    get_value(id).try_into().unwrap_or(default)
}

unsafe fn profiling_enabled_current() -> bool {
    get_bool(ProfilingEnabled, DEFAULT_SYSTEM_SETTINGS.profiling_enabled)
}

unsafe fn profiling_experimental_features_enabled_current() -> bool {
    profiling_enabled_current()
        && get_bool(
            ProfilingExperimentalFeaturesEnabled,
            DEFAULT_SYSTEM_SETTINGS.profiling_experimental_features_enabled,
        )
}

unsafe fn profiling_allocation_enabled_current() -> bool {
    profiling_enabled_current()
        && get_bool(
            ProfilingAllocationEnabled,
            DEFAULT_SYSTEM_SETTINGS.profiling_allocation_enabled,
        )
}

pub(crate) unsafe fn profiling_experimental_heap_live_enabled_current() -> bool {
    profiling_allocation_enabled_current()
        && (profiling_experimental_features_enabled_current()
            || get_bool(
                ProfilingExperimentalHeapLiveEnabled,
                DEFAULT_SYSTEM_SETTINGS.profiling_experimental_heap_live_enabled,
            ))
}

#[track_caller]
unsafe fn get_system_str(config_id: ConfigId) -> Option<Cow<'static, str>> {
    let entry = get_system_value(config_id);
    match Cow::<str>::try_from(entry) {
        Ok(value) => {
            if value.is_empty() {
                None
            } else {
                Some(value)
            }
        }
        Err(err) => {
            let env_var = format!("{config_id:?}");
            match err {
                StringError::Null => panic!("When fetching {env_var}, found a null string pointer inside a zval of type string"),
                StringError::Type(type_code) => panic!("When fetching {env_var}, expected type IS_STRING, found {type_code}"),
            }
        }
    }
}

unsafe fn get_str(id: ConfigId) -> Option<String> {
    let value = get_value(id);
    match String::try_from(value) {
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

unsafe fn get_system_zend_long(config_id: ConfigId) -> Result<zend_long, u8> {
    zend_long::try_from(get_system_value(config_id))
}

unsafe fn get_system_uint32(id: ConfigId, default: u32) -> u32 {
    get_system_value(id).try_into().unwrap_or(default)
}

/// # Safety
/// This function must only be called after config has been initialized in
/// first rinit, and before it is uninitialized in mshutdown.
unsafe fn agent_host() -> Option<Cow<'static, str>> {
    if ddog_php_prof_config_is_set_by_user(AgentHost) {
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
/// rinit, and before it is uninitialized in rshutdown.
pub(crate) unsafe fn tags() -> Option<Vec<(String, String)>> {
    crate::config::get_DD_TAGS().string_pairs()
}

pub(crate) struct ConfigMap {
    id: ConfigId,
    memoized: bool,
}

impl ConfigMap {
    unsafe fn string_pairs(self) -> Option<Vec<(String, String)>> {
        read_config_map(self.id, self.memoized)
    }
}

pub(crate) unsafe fn config_map(id: ConfigId) -> ConfigMap {
    ConfigMap {
        id,
        memoized: false,
    }
}

pub(crate) unsafe fn memoized_config_map(id: ConfigId) -> ConfigMap {
    ConfigMap { id, memoized: true }
}

unsafe fn read_config_map(id: ConfigId, memoized: bool) -> Option<Vec<(String, String)>> {
    struct Context {
        tags: Vec<(String, String)>,
        valid: bool,
    }

    unsafe extern "C" fn visit(
        context: *mut c_void,
        key: *const c_char,
        key_len: usize,
        value: *const c_char,
        value_len: usize,
    ) -> bool {
        let context = &mut *(context.cast::<Context>());
        let key = slice::from_raw_parts(key.cast::<u8>(), key_len);
        let value = slice::from_raw_parts(value.cast::<u8>(), value_len);
        let (Ok(key), Ok(value)) = (str::from_utf8(key), str::from_utf8(value)) else {
            context.valid = false;
            return false;
        };
        context.tags.push((key.to_owned(), value.to_owned()));
        true
    }

    let mut context = Context {
        tags: Vec::new(),
        valid: true,
    };
    let visited = bindings::ddog_php_prof_config_visit_map(
        id as u16,
        memoized,
        (&mut context as *mut Context).cast(),
        Some(visit),
    );
    (visited && context.valid && !context.tags.is_empty()).then_some(context.tags)
}

/// # Safety
/// This function must only be called after config has been initialized in
/// first rinit, and before it is uninitialized in mshutdown.
unsafe fn trace_agent_port() -> Option<u16> {
    if !ddog_php_prof_config_is_set_by_user(TraceAgentPort) {
        return None;
    }
    let port = get_system_zend_long(TraceAgentPort).unwrap_or(0);
    if port <= 0 || port > (u16::MAX as zend_long) {
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
    match get_system_zend_long(ProfilingLogLevel) {
        // If this is an lval, then we know we can transmute it because the parser worked.
        Ok(enabled) => transmute::<zend_long, LevelFilter>(enabled),
        Err(err) => {
            warn!("config::profiling_log_level() failed: {err}");
            DEFAULT_SYSTEM_SETTINGS.profiling_log_level
        }
    }
}

/// Parses the sampling distance and makes sure it is ℤ+ (positive integer > 0)
#[no_mangle]
pub unsafe extern "C" fn ddog_php_prof_config_parse_sampling_distance(
    value: ZaiStr,
    decoded_value: *mut zval,
    _persistent: bool,
) -> bool {
    if value.is_empty() || decoded_value.is_null() {
        return false;
    }
    let decoded_value = &mut *decoded_value;

    match value.into_utf8() {
        Ok(distance) => {
            let parsed_distance: Result<i64, _> = distance.parse();
            match parsed_distance {
                Ok(value) => {
                    if value <= 0 {
                        return false;
                    }
                    decoded_value.value.lval = value as zend_long;
                    decoded_value.u1.type_info = IS_LONG as u32;
                    true
                }
                _ => false,
            }
        }
        _ => false,
    }
}

#[no_mangle]
pub unsafe extern "C" fn ddog_php_prof_config_parse_log_level(
    value: ZaiStr,
    decoded_value: *mut zval,
    _persistent: bool,
) -> bool {
    if value.is_empty() || decoded_value.is_null() {
        return false;
    }

    let decoded_value = &mut *decoded_value;
    match value.into_utf8() {
        Ok(level) => {
            // We need to accept the empty string here as datadog.profiling.log_level = off will be
            // trivially interpreted as the empty string by the PHP ini parser.
            let parsed_level = if level.is_empty() {
                Ok(LevelFilter::Off)
            } else {
                LevelFilter::from_str(level)
            };
            match parsed_level {
                Ok(filter) => {
                    decoded_value.value.lval = filter as zend_long;
                    decoded_value.u1.type_info = IS_LONG as u32;
                    true
                }
                _ => false,
            }
        }
        _ => false,
    }
}

/// This function is used to parse the profiling enabled config value.
/// It behaves similarly to `zai_config_decode_bool` but also accepts "auto" as true.
#[no_mangle]
pub unsafe extern "C" fn ddog_php_prof_config_parse_enabled(
    value: ZaiStr,
    decoded_value: *mut zval,
    _persistent: bool,
) -> bool {
    if decoded_value.is_null() {
        return false;
    }

    let decoded_value = &mut *decoded_value;
    match value.into_utf8() {
        Ok(value) => {
            if value.eq_ignore_ascii_case("1")
                || value.eq_ignore_ascii_case("on")
                || value.eq_ignore_ascii_case("yes")
                || value.eq_ignore_ascii_case("true")
                || value.eq_ignore_ascii_case("auto")
            {
                decoded_value.u1.type_info = IS_TRUE as u32;
            } else {
                decoded_value.u1.type_info = IS_FALSE as u32;
            }
            true
        }
        _ => false,
    }
}

/// Display the profiling enabled config value
#[no_mangle]
pub unsafe extern "C" fn ddog_php_prof_config_display_enabled(
    ini_entry: *mut zend_ini_entry,
    type_: c_int,
) {
    // PHP 8.6 changed this field from u8 to bool, so the cast is redundant only on older PHP.
    #[allow(clippy::unnecessary_cast)]
    let tmp_value: *mut zend_string =
        if type_ as u32 == ZEND_INI_DISPLAY_ORIG && (*ini_entry).modified as u8 != 0 {
            if !(*ini_entry).orig_value.is_null() {
                (*ini_entry).orig_value
            } else {
                ptr::null_mut()
            }
        } else if !(*ini_entry).value.is_null() {
            (*ini_entry).value
        } else {
            ptr::null_mut()
        };

    let mut value: bool = false;
    if !tmp_value.is_null() {
        let str_val = zai_str_from_zstr(tmp_value.as_mut()).into_string();
        value = if str_val.eq_ignore_ascii_case("1")
            || str_val.eq_ignore_ascii_case("on")
            || str_val.eq_ignore_ascii_case("yes")
            || str_val.eq_ignore_ascii_case("true")
            || str_val.eq_ignore_ascii_case("auto")
        {
            true
        } else {
            let str_val = zai_str_from_zstr(tmp_value.as_mut()).into_string();
            str_val.parse::<i32>().unwrap_or(0) != 0
        }
    }

    unsafe {
        if let Some(write_fn) = zend_write {
            let msg = CString::new(if value { "On" } else { "Off" }).unwrap();
            write_fn(msg.as_ptr(), msg.to_bytes().len());
        }
    }
}

#[no_mangle]
pub unsafe extern "C" fn ddog_php_prof_config_parse_utf8_string(
    value: ZaiStr,
    decoded_value: *mut zval,
    persistent: bool,
) -> bool {
    if value.is_empty() || decoded_value.is_null() {
        return false;
    }

    match value.into_utf8() {
        Ok(utf8) => {
            let view = ZaiStr::from(utf8);
            datadog_php_profiling_copy_string_view_into_zval(decoded_value, view, persistent);
            true
        }
        Err(e) => {
            warn!("Error while running config::parse_utf8_string(): {}", e);
            false
        }
    }
}

pub(crate) fn minit(_module_number: libc::c_int) {
    unsafe {
        #[cfg(all(feature = "profiling", not(feature = "tracer")))]
        {
            assert!(bindings::ddog_php_prof_config_minit(_module_number));

            // Make system INI settings available during MINIT, for example
            // for allocation_sampling_distance.
            bindings::zai_config_first_time_rinit(false);
        }

        // SAFETY: common configuration has already initialized the aggregate
        // table in combined mode; standalone initialized it above.
        let mut system_settings = SystemSettings::new();

        // Initialize logging before allocation's rinit, as it logs.
        #[cfg(debug_assertions)]
        log::set_max_level(system_settings.profiling_log_level);
        #[cfg(not(debug_assertions))]
        crate::profiling::logging::log_init(system_settings.profiling_log_level);

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
