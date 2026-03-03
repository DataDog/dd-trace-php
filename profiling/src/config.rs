use crate::bindings::zai_config_type::*;
use crate::bindings::{
    datadog_php_profiling_copy_string_view_into_zval, ddog_php_prof_config_is_set_by_user,
    ddog_php_prof_get_memoized_config,
    zai_config_entry, zai_config_get_value, zai_config_minit, zai_config_name,
    zai_config_system_ini_change, zend_ini_entry, zend_long, zend_string, zend_write, zval,
    StringError, ZaiStr, IS_FALSE, IS_LONG, IS_TRUE, ZAI_CONFIG_NAME_BUFSIZ, ZEND_INI_DISPLAY_ORIG,
};
use crate::zend::zai_str_from_zstr;
use crate::{allocation, bindings};
use core::fmt::{Display, Formatter};
use core::mem::transmute;
use core::ptr;
use core::str::FromStr;
pub use http::Uri;
use libc::{c_char, c_int};
use libdd_common::tag::{parse_tags, Tag};
use log::{debug, error, warn, LevelFilter};
use std::borrow::Cow;
use std::ffi::CString;
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
        #[cfg(not(php_zend_mm_set_custom_handlers_ex))]
        if allocation::allocation_le83::first_rinit_should_disable_due_to_jit() {
            if bindings::PHP_VERSION_ID >= 80400 {
                error!("Memory allocation profiling will be disabled as long as JIT is active. To enable allocation profiling disable JIT or upgrade PHP to at least version 8.4.7. See https://github.com/DataDog/dd-trace-php/pull/3199");
            } else {
                error!("Memory allocation profiling will be disabled as long as JIT is active. To enable allocation profiling disable JIT or upgrade PHP to at least version 8.1.21 or 8.2.8. See https://github.com/DataDog/dd-trace-php/pull/2088");
            }
            system_settings.profiling_allocation_enabled = false;
        }
        #[cfg(php_zend_mm_set_custom_handlers_ex)]
        if allocation::allocation_ge84::first_rinit_should_disable_due_to_jit() {
            error!("Memory allocation profiling will be disabled as long as JIT is active. To enable allocation profiling disable JIT or upgrade PHP to at least version 8.4.7. See https://github.com/DataDog/dd-trace-php/pull/3199");
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

unsafe extern "C" fn env_to_ini_name(env_name: ZaiStr, ini_name: *mut zai_config_name) {
    assert!(!ini_name.is_null());
    let ini_name = &mut *ini_name;

    let name: &str = env_name.into_utf8().unwrap();

    assert!(name.starts_with("DD_"));

    // Env var name needs to fit.
    let projection = "datadog.".len() - "DD_".len();
    let null_byte = 1usize;
    assert!(name.len() + projection + null_byte <= (ZAI_CONFIG_NAME_BUFSIZ as usize));

    let (dest_prefix, src_prefix) = if name.starts_with("DD_TRACE_") {
        ("datadog.trace.", "DD_TRACE_")
    } else if name.starts_with("DD_PROFILING_") {
        ("datadog.profiling.", "DD_PROFILING_")
    } else if name.starts_with("DD_APPSEC_") {
        ("datadog.appsec.", "DD_APPSEC_")
    } else {
        ("datadog.", "DD_")
    };

    {
        /* Safety:
         *  1. The src buffer's length is coming from a safe rust slice
         *  2. The length of all these prefixes is less than the size of the
         *     dst buffer (currently 60 bytes);
         *  3. Both pointers are dealing with bytes, and so they are aligned.
         *  4. These pointers do not overlap, the src string is a constant
         *     and the destination is an in-place array in a struct.
         */
        ptr::copy_nonoverlapping(
            dest_prefix.as_ptr() as *const c_char,
            ini_name.ptr.as_mut_ptr(),
            dest_prefix.len(),
        );

        // Miri doesn't like uninitialized bytes
        let buffer = &mut ini_name.ptr[dest_prefix.len()..];
        buffer.fill(c_char::default());
    }

    // Copy in the parts after the prefix, lowercasing as we go. For example,
    // with DD_PROFILING_ENABLED copy `ENABLED` as `enabled` into the
    // destination slice.
    let dest_suffix = &mut ini_name.ptr[dest_prefix.len()..];
    let src_suffix = &name[src_prefix.len()..];
    for (dest, src) in dest_suffix.iter_mut().zip(src_suffix.bytes()) {
        // Casting between same-sized integers is a no-op.
        *dest = src.to_ascii_lowercase() as c_char;
    }

    // Add the null terminator.
    dest_suffix[src_suffix.len()] = b'\0' as c_char;

    // Store the length without the null.
    ini_name.len = dest_prefix.len() + src_suffix.len();
}

/// # Safety
/// This function must only be called after config has been initialized in
/// rinit, and before it is uninitialized in rshutdown.
pub(crate) unsafe fn get_value(id: ConfigId) -> &'static mut zval {
    let value = zai_config_get_value(transmute::<ConfigId, u16>(id));
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

impl ConfigId {
    const fn env_var_name(&self) -> ZaiStr<'_> {
        let bytes: &'static [u8] = match self {
            ProfilingEnabled => b"DD_PROFILING_ENABLED\0",
            ProfilingExperimentalFeaturesEnabled => b"DD_PROFILING_EXPERIMENTAL_FEATURES_ENABLED\0",
            ProfilingEndpointCollectionEnabled => b"DD_PROFILING_ENDPOINT_COLLECTION_ENABLED\0",
            ProfilingExperimentalCpuTimeEnabled => b"DD_PROFILING_EXPERIMENTAL_CPU_TIME_ENABLED\0",
            ProfilingAllocationEnabled => b"DD_PROFILING_ALLOCATION_ENABLED\0",
            ProfilingAllocationSamplingDistance => b"DD_PROFILING_ALLOCATION_SAMPLING_DISTANCE\0",
            ProfilingTimelineEnabled => b"DD_PROFILING_TIMELINE_ENABLED\0",
            ProfilingExceptionEnabled => b"DD_PROFILING_EXCEPTION_ENABLED\0",
            ProfilingExceptionMessageEnabled => b"DD_PROFILING_EXCEPTION_MESSAGE_ENABLED\0",
            ProfilingExceptionSamplingDistance => b"DD_PROFILING_EXCEPTION_SAMPLING_DISTANCE\0",
            ProfilingExperimentalIOEnabled => b"DD_PROFILING_EXPERIMENTAL_IO_ENABLED\0",
            ProfilingLogLevel => b"DD_PROFILING_LOG_LEVEL\0",

            // Note: this group is meant only for debugging and testing. Please
            // don't advertise this group of settings in the docs.
            ProfilingOutputPprof => b"DD_PROFILING_OUTPUT_PPROF\0",
            ProfilingWallTimeEnabled => b"DD_PROFILING_WALLTIME_ENABLED\0",

            AgentHost => b"DD_AGENT_HOST\0",
            Env => b"DD_ENV\0",
            Service => b"DD_SERVICE\0",
            Tags => b"DD_TAGS\0",
            TraceAgentPort => b"DD_TRACE_AGENT_PORT\0",
            TraceAgentUrl => b"DD_TRACE_AGENT_URL\0",
            Version => b"DD_VERSION\0",
            GitCommitSha => b"DD_GIT_COMMIT_SHA\0",
            GitRepositoryUrl => b"DD_GIT_REPOSITORY_URL\0",
        };

        // Safety: all these byte strings are [CStr::from_bytes_with_nul_unchecked] compatible.
        unsafe { ZaiStr::literal(bytes) }
    }
}

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
            let env_var = config_id.env_var_name().into_string_lossy();
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
unsafe extern "C" fn parse_sampling_distance_filter(
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

unsafe extern "C" fn parse_level_filter(
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
/// It behaves similarlry to the "zai_config_decode_bool" but also accepts "auto" as true.
unsafe extern "C" fn parse_profiling_enabled(
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
unsafe extern "C" fn display_profiling_enabled(ini_entry: *mut zend_ini_entry, type_: c_int) {
    let tmp_value: *mut zend_string =
        if type_ as u32 == ZEND_INI_DISPLAY_ORIG && (*ini_entry).modified != 0 {
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

unsafe extern "C" fn parse_utf8_string(
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

pub(crate) fn minit(module_number: libc::c_int) {
    unsafe {
        const CPU_TIME_ALIASES: &[ZaiStr] =
            unsafe { &[ZaiStr::literal(b"DD_PROFILING_EXPERIMENTAL_CPU_ENABLED\0")] };

        const ALLOCATION_ALIASES: &[ZaiStr] = unsafe {
            &[ZaiStr::literal(
                b"DD_PROFILING_EXPERIMENTAL_ALLOCATION_ENABLED\0",
            )]
        };

        const EXCEPTION_ALIASES: &[ZaiStr] = unsafe {
            &[ZaiStr::literal(
                b"DD_PROFILING_EXPERIMENTAL_EXCEPTION_ENABLED\0",
            )]
        };

        const EXCEPTION_SAMPLING_DISTANCE_ALIASES: &[ZaiStr] = unsafe {
            &[ZaiStr::literal(
                b"DD_PROFILING_EXPERIMENTAL_EXCEPTION_SAMPLING_DISTANCE\0",
            )]
        };

        const TIMELINE_ALIASES: &[ZaiStr] = unsafe {
            &[ZaiStr::literal(
                b"DD_PROFILING_EXPERIMENTAL_TIMELINE_ENABLED\0",
            )]
        };

        // Note that function pointers cannot appear in const functions, so we
        // can't extract each entry into a helper function.
        static mut ENTRIES: &mut [zai_config_entry] = unsafe {
            &mut [
                zai_config_entry {
                    id: transmute::<ConfigId, u16>(ProfilingEnabled),
                    name: ProfilingEnabled.env_var_name(),
                    type_: ZAI_CONFIG_TYPE_CUSTOM,
                    default_encoded_value: ZaiStr::literal(b"1\0"),
                    aliases: ptr::null_mut(),
                    aliases_count: 0,
                    ini_change: Some(zai_config_system_ini_change),
                    parser: Some(parse_profiling_enabled),
                    displayer: Some(display_profiling_enabled),
                    env_config_fallback: None,
                },
                zai_config_entry {
                    id: transmute::<ConfigId, u16>(ProfilingExperimentalFeaturesEnabled),
                    name: ProfilingExperimentalFeaturesEnabled.env_var_name(),
                    type_: ZAI_CONFIG_TYPE_BOOL,
                    default_encoded_value: ZaiStr::literal(b"0\0"),
                    aliases: ptr::null_mut(),
                    aliases_count: 0,
                    ini_change: Some(zai_config_system_ini_change),
                    parser: None,
                    displayer: None,
                    env_config_fallback: None,
                },
                zai_config_entry {
                    id: transmute::<ConfigId, u16>(ProfilingEndpointCollectionEnabled),
                    name: ProfilingEndpointCollectionEnabled.env_var_name(),
                    type_: ZAI_CONFIG_TYPE_BOOL,
                    default_encoded_value: ZaiStr::literal(b"1\0"),
                    aliases: ptr::null_mut(),
                    aliases_count: 0,
                    ini_change: Some(zai_config_system_ini_change),
                    parser: None,
                    displayer: None,
                    env_config_fallback: None,
                },
                zai_config_entry {
                    id: transmute::<ConfigId, u16>(ProfilingExperimentalCpuTimeEnabled),
                    name: ProfilingExperimentalCpuTimeEnabled.env_var_name(),
                    type_: ZAI_CONFIG_TYPE_BOOL,
                    default_encoded_value: ZaiStr::literal(b"1\0"),
                    aliases: CPU_TIME_ALIASES.as_ptr(),
                    aliases_count: CPU_TIME_ALIASES.len() as u8,
                    ini_change: Some(zai_config_system_ini_change),
                    parser: None,
                    displayer: None,
                    env_config_fallback: None,
                },
                zai_config_entry {
                    id: transmute::<ConfigId, u16>(ProfilingAllocationEnabled),
                    name: ProfilingAllocationEnabled.env_var_name(),
                    type_: ZAI_CONFIG_TYPE_BOOL,
                    default_encoded_value: ZaiStr::literal(b"1\0"),
                    aliases: ALLOCATION_ALIASES.as_ptr(),
                    aliases_count: ALLOCATION_ALIASES.len() as u8,
                    ini_change: Some(zai_config_system_ini_change),
                    parser: None,
                    displayer: None,
                    env_config_fallback: None,
                },
                zai_config_entry {
                    id: transmute::<ConfigId, u16>(ProfilingAllocationSamplingDistance),
                    name: ProfilingAllocationSamplingDistance.env_var_name(),
                    type_: ZAI_CONFIG_TYPE_CUSTOM,
                    default_encoded_value: ZaiStr::literal(b"4194304\0"), // crate::allocation::DEFAULT_ALLOCATION_SAMPLING_INTERVAL
                    aliases: ptr::null_mut(),
                    aliases_count: 0,
                    ini_change: Some(zai_config_system_ini_change),
                    parser: Some(parse_sampling_distance_filter),
                    displayer: None,
                    env_config_fallback: None,
                },
                zai_config_entry {
                    id: transmute::<ConfigId, u16>(ProfilingTimelineEnabled),
                    name: ProfilingTimelineEnabled.env_var_name(),
                    type_: ZAI_CONFIG_TYPE_BOOL,
                    default_encoded_value: ZaiStr::literal(b"1\0"),
                    aliases: TIMELINE_ALIASES.as_ptr(),
                    aliases_count: TIMELINE_ALIASES.len() as u8,
                    ini_change: Some(zai_config_system_ini_change),
                    parser: None,
                    displayer: None,
                    env_config_fallback: None,
                },
                zai_config_entry {
                    id: transmute::<ConfigId, u16>(ProfilingExceptionEnabled),
                    name: ProfilingExceptionEnabled.env_var_name(),
                    type_: ZAI_CONFIG_TYPE_BOOL,
                    default_encoded_value: ZaiStr::literal(b"1\0"),
                    aliases: EXCEPTION_ALIASES.as_ptr(),
                    aliases_count: EXCEPTION_ALIASES.len() as u8,
                    ini_change: Some(zai_config_system_ini_change),
                    parser: None,
                    displayer: None,
                    env_config_fallback: None,
                },
                zai_config_entry {
                    id: transmute::<ConfigId, u16>(ProfilingExceptionMessageEnabled),
                    name: ProfilingExceptionMessageEnabled.env_var_name(),
                    type_: ZAI_CONFIG_TYPE_BOOL,
                    default_encoded_value: ZaiStr::literal(b"0\0"),
                    aliases: ptr::null_mut(),
                    aliases_count: 0,
                    ini_change: Some(zai_config_system_ini_change),
                    parser: None,
                    displayer: None,
                    env_config_fallback: None,
                },
                zai_config_entry {
                    id: transmute::<ConfigId, u16>(ProfilingExceptionSamplingDistance),
                    name: ProfilingExceptionSamplingDistance.env_var_name(),
                    type_: ZAI_CONFIG_TYPE_CUSTOM,
                    default_encoded_value: ZaiStr::literal(b"100\0"),
                    aliases: EXCEPTION_SAMPLING_DISTANCE_ALIASES.as_ptr(),
                    aliases_count: EXCEPTION_SAMPLING_DISTANCE_ALIASES.len() as u8,
                    ini_change: Some(zai_config_system_ini_change),
                    parser: Some(parse_sampling_distance_filter),
                    displayer: None,
                    env_config_fallback: None,
                },
                zai_config_entry {
                    id: transmute::<ConfigId, u16>(ProfilingExperimentalIOEnabled),
                    name: ProfilingExperimentalIOEnabled.env_var_name(),
                    type_: ZAI_CONFIG_TYPE_BOOL,
                    default_encoded_value: ZaiStr::literal(b"0\0"),
                    aliases: ptr::null_mut(),
                    aliases_count: 0,
                    ini_change: Some(zai_config_system_ini_change),
                    parser: None,
                    displayer: None,
                    env_config_fallback: None,
                },
                zai_config_entry {
                    id: transmute::<ConfigId, u16>(ProfilingLogLevel),
                    name: ProfilingLogLevel.env_var_name(),
                    type_: ZAI_CONFIG_TYPE_CUSTOM, // store it as an int
                    default_encoded_value: ZaiStr::literal(b"off\0"),
                    aliases: ptr::null_mut(),
                    aliases_count: 0,
                    ini_change: Some(zai_config_system_ini_change),
                    parser: Some(parse_level_filter),
                    displayer: None,
                    env_config_fallback: None,
                },
                zai_config_entry {
                    id: transmute::<ConfigId, u16>(ProfilingOutputPprof),
                    name: ProfilingOutputPprof.env_var_name(),
                    type_: ZAI_CONFIG_TYPE_STRING,
                    default_encoded_value: ZaiStr::new(),
                    aliases: ptr::null_mut(),
                    aliases_count: 0,
                    ini_change: Some(zai_config_system_ini_change),
                    parser: Some(parse_utf8_string),
                    displayer: None,
                    env_config_fallback: None,
                },
                // At the moment, wall-time cannot be fully disabled. This only
                // controls automatic collection (manual collection is still
                // possible).
                zai_config_entry {
                    id: transmute::<ConfigId, u16>(ProfilingWallTimeEnabled),
                    name: ProfilingWallTimeEnabled.env_var_name(),
                    type_: ZAI_CONFIG_TYPE_BOOL,
                    default_encoded_value: ZaiStr::literal(b"1\0"),
                    aliases: ptr::null_mut(),
                    aliases_count: 0,
                    ini_change: Some(zai_config_system_ini_change),
                    parser: None,
                    displayer: None,
                    env_config_fallback: None,
                },
                zai_config_entry {
                    id: transmute::<ConfigId, u16>(AgentHost),
                    name: AgentHost.env_var_name(),
                    type_: ZAI_CONFIG_TYPE_STRING,
                    default_encoded_value: ZaiStr::new(),
                    aliases: ptr::null_mut(),
                    aliases_count: 0,
                    ini_change: Some(zai_config_system_ini_change),
                    parser: Some(parse_utf8_string),
                    displayer: None,
                    env_config_fallback: None,
                },
                zai_config_entry {
                    id: transmute::<ConfigId, u16>(Env),
                    name: Env.env_var_name(),
                    type_: ZAI_CONFIG_TYPE_STRING,
                    default_encoded_value: ZaiStr::new(),
                    aliases: ptr::null_mut(),
                    aliases_count: 0,
                    ini_change: None,
                    parser: Some(parse_utf8_string),
                    displayer: None,
                    env_config_fallback: None,
                },
                zai_config_entry {
                    id: transmute::<ConfigId, u16>(Service),
                    name: Service.env_var_name(),
                    type_: ZAI_CONFIG_TYPE_STRING,
                    default_encoded_value: ZaiStr::new(),
                    aliases: ptr::null_mut(),
                    aliases_count: 0,
                    ini_change: None,
                    parser: Some(parse_utf8_string),
                    displayer: None,
                    env_config_fallback: None,
                },
                zai_config_entry {
                    id: transmute::<ConfigId, u16>(Tags),
                    name: Tags.env_var_name(),
                    // Using a string here means we're going to parse the
                    // string into tags over and over, but since it needs to
                    // be a valid zval for destruction, we can't just use a
                    // Box::leak of Vec<Tag> or something.
                    type_: ZAI_CONFIG_TYPE_STRING,
                    default_encoded_value: ZaiStr::new(),
                    aliases: ptr::null_mut(),
                    aliases_count: 0,
                    ini_change: None,
                    parser: None,
                    displayer: None,
                    env_config_fallback: None,
                },
                zai_config_entry {
                    id: transmute::<ConfigId, u16>(TraceAgentPort),
                    name: TraceAgentPort.env_var_name(),
                    type_: ZAI_CONFIG_TYPE_INT,
                    default_encoded_value: ZaiStr::literal(b"0\0"),
                    aliases: ptr::null_mut(),
                    aliases_count: 0,
                    ini_change: Some(zai_config_system_ini_change),
                    parser: Some(parse_utf8_string),
                    displayer: None,
                    env_config_fallback: None,
                },
                zai_config_entry {
                    id: transmute::<ConfigId, u16>(TraceAgentUrl),
                    name: TraceAgentUrl.env_var_name(),
                    type_: ZAI_CONFIG_TYPE_STRING, // TYPE?
                    default_encoded_value: ZaiStr::new(),
                    aliases: ptr::null_mut(),
                    aliases_count: 0,
                    ini_change: Some(zai_config_system_ini_change),
                    parser: Some(parse_utf8_string),
                    displayer: None,
                    env_config_fallback: None,
                },
                zai_config_entry {
                    id: transmute::<ConfigId, u16>(Version),
                    name: Version.env_var_name(),
                    type_: ZAI_CONFIG_TYPE_STRING,
                    default_encoded_value: ZaiStr::new(),
                    aliases: ptr::null_mut(),
                    aliases_count: 0,
                    ini_change: None,
                    parser: Some(parse_utf8_string),
                    displayer: None,
                    env_config_fallback: None,
                },
                zai_config_entry {
                    id: transmute::<ConfigId, u16>(GitCommitSha),
                    name: GitCommitSha.env_var_name(),
                    type_: ZAI_CONFIG_TYPE_STRING,
                    default_encoded_value: ZaiStr::new(),
                    aliases: ptr::null_mut(),
                    aliases_count: 0,
                    ini_change: None,
                    parser: Some(parse_utf8_string),
                    displayer: None,
                    env_config_fallback: None,
                },
                zai_config_entry {
                    id: transmute::<ConfigId, u16>(GitRepositoryUrl),
                    name: GitRepositoryUrl.env_var_name(),
                    type_: ZAI_CONFIG_TYPE_STRING,
                    default_encoded_value: ZaiStr::new(),
                    aliases: ptr::null_mut(),
                    aliases_count: 0,
                    ini_change: None,
                    parser: Some(parse_utf8_string),
                    displayer: None,
                    env_config_fallback: None,
                },
            ]
        };

        let entries = &mut *ptr::addr_of_mut!(ENTRIES);
        let tmp = zai_config_minit(
            entries.as_mut_ptr(),
            entries.len(),
            Some(env_to_ini_name),
            module_number,
        );
        assert!(tmp); // It's literally return true in the source.

        // We set this so that we can access config for system INI settings during
        // minit, for example for allocation_sampling_distance.
        let in_request = false;
        bindings::zai_config_first_time_rinit(in_request);

        // SAFETY: just initialized zai config.
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
    use core::mem::MaybeUninit;
    use libc::memcmp;

    #[test]
    fn test_env_to_ini_name() {
        let cases: &[(&[u8], &str)] = &[
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

        for (env_name, expected_ini_name) in cases {
            unsafe {
                let env = ZaiStr::literal(env_name);
                let mut ini = MaybeUninit::uninit();
                env_to_ini_name(env, ini.as_mut_ptr());
                let ini = ini.assume_init();

                // Check that .len matches.
                assert_eq!(
                    expected_ini_name.len(),
                    { ini.len },
                    "Env: {}, expected ini: {}",
                    std::str::from_utf8(env_name).unwrap(),
                    expected_ini_name
                );

                // Check that the bytes match.
                let cmp = memcmp(
                    expected_ini_name.as_ptr().cast(),
                    ini.ptr.as_ptr().cast(),
                    expected_ini_name.len(),
                );
                assert_eq!(0, cmp);

                // Check that it is null terminated.
                assert_eq!(ini.ptr[ini.len] as u8, b'\0');
            }
        }
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
