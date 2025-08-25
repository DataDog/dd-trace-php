use crate::log::Log;
use datadog_sidecar::service::telemetry::path_for_telemetry;

use hashbrown::{Equivalent, HashMap};
use std::collections::HashSet;
use std::ffi::CString;
use std::path::PathBuf;

use datadog_ipc::platform::NamedShmHandle;
use datadog_sidecar::one_way_shared_memory::{open_named_shm, OneWayShmReader};
use datadog_sidecar::service::{
    blocking::{self, SidecarTransport},
    InstanceId, QueueId, SidecarAction,
};
use ddcommon::tag::parse_tags;
use ddcommon_ffi::slice::AsBytes;
use ddcommon_ffi::{self as ffi, CharSlice, MaybeError};
use ddtelemetry::data;
use ddtelemetry::data::metrics::{MetricNamespace, MetricType};
use ddtelemetry::data::{Dependency, Endpoint, Integration, LogLevel};
use ddtelemetry::metrics::MetricContext;
use ddtelemetry::worker::{LogIdentifier, TelemetryActions};
use ddtelemetry_ffi::try_c;
use std::error::Error;
use std::hash::{Hash, Hasher};
use std::str::FromStr;
use zwohash::ZwoHasher;
use std::fs::OpenOptions;
use std::io::Write;

#[cfg(windows)]
macro_rules! windowsify_path {
    ($lit:literal) => {
        const_str::replace!($lit, "/", "\\")
    };
}
#[cfg(unix)]
macro_rules! windowsify_path {
    ($lit:literal) => {
        $lit
    };
}

#[must_use]
#[no_mangle]
pub extern "C" fn ddtrace_detect_composer_installed_json(
    transport: &mut Box<SidecarTransport>,
    instance_id: &InstanceId,
    queue_id: &QueueId,
    path: CharSlice,
) -> bool {
    let pathstr = path.to_utf8_lossy();
    if let Some(index) = pathstr.rfind(windowsify_path!("/vendor/autoload.php")) {
        let path = format!(
            "{}{}",
            &pathstr[..index],
            windowsify_path!("/vendor/composer/installed.json")
        );
        if parse_composer_installed_json(transport, instance_id, queue_id, path).is_ok() {
            return true;
        }
    }
    false
}

fn parse_composer_installed_json(
    transport: &mut Box<SidecarTransport>,
    instance_id: &InstanceId,
    queue_id: &QueueId,
    path: String,
) -> Result<(), Box<dyn Error>> {
    let action = vec![SidecarAction::PhpComposerTelemetryFile(PathBuf::from_str(
        path.as_str(),
    )?)];
    blocking::enqueue_actions(transport, instance_id, queue_id, action)?;

    Ok(())
}

#[derive(Default)]
pub struct SidecarActionsBuffer {
    buffer: Vec<SidecarAction>,
}

#[no_mangle]
pub extern "C" fn ddog_sidecar_telemetry_buffer_alloc() -> Box<SidecarActionsBuffer> {
    SidecarActionsBuffer::default().into()
}

#[no_mangle]
pub extern "C" fn ddog_sidecar_telemetry_buffer_drop(_: Box<SidecarActionsBuffer>) {}

#[no_mangle]
pub unsafe extern "C" fn ddog_sidecar_telemetry_addIntegration_buffer(
    buffer: &mut SidecarActionsBuffer,
    integration_name: CharSlice,
    integration_version: CharSlice,
    integration_enabled: bool,
) {
    let version = integration_version
        .is_empty()
        .then(|| integration_version.to_utf8_lossy().into_owned());

    let action = TelemetryActions::AddIntegration(Integration {
        name: integration_name.to_utf8_lossy().into_owned(),
        enabled: integration_enabled,
        version,
        compatible: None,
        auto_enabled: None,
    });
    buffer.buffer.push(SidecarAction::Telemetry(action));
}

#[no_mangle]
pub unsafe extern "C" fn ddog_sidecar_telemetry_addDependency_buffer(
    buffer: &mut SidecarActionsBuffer,
    dependency_name: CharSlice,
    dependency_version: CharSlice,
) {
    let version =
        (!dependency_version.is_empty()).then(|| dependency_version.to_utf8_lossy().into_owned());

    let action = TelemetryActions::AddDependency(Dependency {
        name: dependency_name.to_utf8_lossy().into_owned(),
        version,
    });
    buffer.buffer.push(SidecarAction::Telemetry(action));
}

#[no_mangle]
pub unsafe extern "C" fn ddog_sidecar_telemetry_enqueueConfig_buffer(
    buffer: &mut SidecarActionsBuffer,
    config_key: CharSlice,
    config_value: CharSlice,
    origin: data::ConfigurationOrigin,
    config_id: CharSlice,
) {
    let config_id = if config_id.is_empty() {
        None
    } else {
        Some(config_id.to_utf8_lossy().into_owned())
    };
    let action = TelemetryActions::AddConfig(data::Configuration {
        name: config_key.to_utf8_lossy().into_owned(),
        value: config_value.to_utf8_lossy().into_owned(),
        origin,
        config_id,
    });
    buffer.buffer.push(SidecarAction::Telemetry(action));
}

#[no_mangle]
// C-unwind to make a panic *here* fall through (as pthread_cancel is implemented as exception, which rust otherwise catches!
pub extern "C-unwind" fn ddog_sidecar_telemetry_buffer_flush(
    transport: &mut Box<SidecarTransport>,
    instance_id: &InstanceId,
    queue_id: &QueueId,
    buffer: Box<SidecarActionsBuffer>,
) -> MaybeError {
    try_c!(blocking::enqueue_actions(
        transport,
        instance_id,
        queue_id,
        buffer.buffer,
    ));

    MaybeError::None
}

#[no_mangle]
pub unsafe extern "C" fn ddog_sidecar_telemetry_register_metric_buffer(
    buffer: &mut SidecarActionsBuffer,
    metric_name: CharSlice,
    metric_type: MetricType,
    namespace: MetricNamespace,
) {
    buffer
        .buffer
        .push(SidecarAction::RegisterTelemetryMetric(MetricContext {
            name: metric_name.to_utf8_lossy().into_owned(),
            namespace,
            metric_type,
            tags: Vec::default(),
            common: true,
        }));
}

#[no_mangle]
pub unsafe extern "C" fn ddog_sidecar_telemetry_add_span_metric_point_buffer(
    buffer: &mut SidecarActionsBuffer,
    metric_name: CharSlice,
    metric_value: f64,
    tags: CharSlice,
) {
    let (tags, _) = parse_tags(&tags.to_utf8_lossy());

    buffer.buffer.push(SidecarAction::AddTelemetryMetricPoint((
        metric_name.to_utf8_lossy().into_owned(),
        metric_value,
        tags,
    )));
}

#[no_mangle]
pub unsafe extern "C" fn ddog_sidecar_telemetry_add_integration_log_buffer(
    category: Log,
    buffer: &mut SidecarActionsBuffer,
    log: CharSlice,
) {
    let mut hasher = ZwoHasher::default();
    log.hash(&mut hasher);

    // Convert from our log category to telemetry log level
    let level = match category {
        Log::Error => LogLevel::Error,
        Log::Warn => LogLevel::Warn,
        _ => LogLevel::Debug,
    };

    let action = TelemetryActions::AddLog((
        LogIdentifier {
            identifier: hasher.finish(),
        },
        data::Log {
            message: log.to_utf8_lossy().into_owned(),
            level,
            stack_trace: None,
            count: 1,
            tags: String::new(),
            is_sensitive: false,
            is_crash: false,
        },
    ));
    buffer.buffer.push(SidecarAction::Telemetry(action));
}

pub struct ShmCache {
    pub config_sent: bool,
    pub integrations: HashSet<String>,
    pub composer_paths: HashSet<PathBuf>,
    pub endpoints: HashSet<Endpoint>,
    pub reader: OneWayShmReader<NamedShmHandle, CString>,
}

#[derive(Hash, Eq, PartialEq)]
struct ShmCacheKey(String, String);

impl Equivalent<ShmCacheKey> for (&str, &str) {
    fn equivalent(&self, key: &ShmCacheKey) -> bool {
        *self.0 == key.0 && *self.1 == key.1
    }
}

pub type ShmCacheMap = HashMap<ShmCacheKey, ShmCache>;

#[no_mangle]
pub extern "C" fn ddog_sidecar_telemetry_cache_new() -> Box<ShmCacheMap> {
    ShmCacheMap::default().into()
}

#[no_mangle]
pub unsafe extern "C" fn ddog_sidecar_telemetry_cache_drop(_: Box<ShmCacheMap>) {}

#[no_mangle]
pub unsafe extern "C" fn ddog_sidecar_telemetry_config_sent(
    cache: &mut ShmCacheMap,
    service: CharSlice,
    env: CharSlice,
) -> bool {
    ddog_sidecar_telemetry_cache_get_or_update(cache, service, env).config_sent
}

unsafe fn ddog_sidecar_telemetry_cache_get_or_update<'a>(
    cache: &'a mut ShmCacheMap,
    service: CharSlice,
    env: CharSlice,
) -> &'a ShmCache {
    fn refresh_cache(cache: &mut ShmCache) {
        let (changed, mut buf) = cache.reader.read();
        if changed {
            // Cache was reset
            if buf.is_empty() {
                cache.reader.clear_reader();
                let (changed, newbuf) = cache.reader.read();
                if changed {
                    buf = newbuf;
                } else {
                    cache.config_sent = false;
                    cache.integrations.clear();
                    cache.composer_paths.clear();
                    return;
                }
            }

            if let Ok((config_sent, integrations, composer_paths, endpoints)) =
                bincode::deserialize::<(bool, HashSet<String>, HashSet<PathBuf>, HashSet<Endpoint>)>(buf)
            {
                cache.config_sent = config_sent;
                cache.integrations = integrations;
                cache.composer_paths = composer_paths;
            }
        }
    }

    let service_str = service.to_utf8_lossy();
    let env_str = env.to_utf8_lossy();

    // I hate you, borrow checker, you get an unsafe from me!
    if let Some(cached_entry) = (&mut *(cache as *mut ShmCacheMap)).get_mut(&(service_str.as_ref(), env_str.as_ref())) {
        refresh_cache(cached_entry);
        return cached_entry;
    }

    let shm_path = path_for_telemetry(&service_str, &env_str);
    let reader = OneWayShmReader::<NamedShmHandle, _>::new(open_named_shm(&shm_path).ok(), shm_path);
    let cached_entry = cache.entry(ShmCacheKey(service_str.into(), env_str.into())).insert(ShmCache {
        reader,
        config_sent: false,
        integrations: HashSet::new(),
        composer_paths: HashSet::new(),
    }).into_mut();

    refresh_cache(cached_entry);
    cached_entry
}

#[no_mangle]
pub unsafe extern "C" fn ddog_sidecar_telemetry_filter_flush(
    transport: &mut Box<SidecarTransport>,
    instance_id: &InstanceId,
    queue_id: &QueueId,
    buffer: &mut SidecarActionsBuffer,
    cache: &mut ShmCacheMap,
    service: CharSlice,
    env: CharSlice,
) -> MaybeError {
    let cache_entry = ddog_sidecar_telemetry_cache_get_or_update(cache, service, env);

    let mut filtered: Vec<SidecarAction> = std::mem::take(&mut buffer.buffer);

    filtered = filtered
        .into_iter()
        .filter(|action| match action {
            SidecarAction::Telemetry(TelemetryActions::AddIntegration(integration)) => {
                !cache_entry.integrations.contains(&integration.name)
            }
            SidecarAction::PhpComposerTelemetryFile(path) => {
                !cache_entry.composer_paths.contains(path)
            }
            _ => true,
        })
        .collect();

    // Proceed with sending whatever remains, whether filtered or not
    try_c!(blocking::enqueue_actions(
        transport,
        instance_id,
        queue_id,
        filtered
    ));

    MaybeError::None
}

#[no_mangle]
pub unsafe extern "C" fn ddog_sidecar_telemetry_are_endpoints_collected(
    cache: &mut ShmCacheMap,
    service: CharSlice,
    env: CharSlice,
) -> bool {
    let cache_entry = ddog_sidecar_telemetry_cache_get_or_update(cache, service, env);
    if let Some(entry) = cache_entry {
        return !entry.endpoints.is_empty();
    }
    false
}
