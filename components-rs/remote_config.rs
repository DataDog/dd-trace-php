use crate::sidecar::MaybeShmLimiter;
use datadog_live_debugger::debugger_defs::{DebuggerData, DebuggerPayload};
use datadog_live_debugger::{FilterList, LiveDebuggingData, ServiceConfiguration};
use datadog_live_debugger_ffi::data::Probe;
use datadog_live_debugger_ffi::evaluator::{ddog_register_expr_evaluator, Evaluator};
use datadog_live_debugger_ffi::send_data::{
    ddog_debugger_diagnostics_create_unboxed, ddog_snapshot_redacted_type,
};
use datadog_remote_config::fetch::ConfigInvariants;
use datadog_remote_config::{
    RemoteConfigCapabilities, RemoteConfigData, RemoteConfigProduct, Target,
};
use datadog_remote_config::config::dynamic::{Configs, TracingSamplingRuleProvenance};
use datadog_sidecar::service::blocking::SidecarTransport;
use datadog_sidecar::service::{InstanceId, QueueId};
use datadog_sidecar::shm_remote_config::{RemoteConfigManager, RemoteConfigUpdate};
use datadog_sidecar_ffi::ddog_sidecar_send_debugger_diagnostics;
use libdd_common::tag::Tag;
use libdd_common::Endpoint;
use libdd_common_ffi::slice::AsBytes;
use libdd_common_ffi::{CharSlice, MaybeError};
use itertools::Itertools;
use regex_automata::dfa::regex::Regex;
use serde::Serialize;
use std::borrow::Cow;
use std::collections::hash_map::Entry;
use std::collections::{HashMap, HashSet};
use std::ffi::c_char;
use std::mem;
use std::ptr::NonNull;
use std::sync::Arc;
use tracing::debug;
use crate::bytes::{ZendString, OwnedZendString, dangling_zend_string};

pub const DYANMIC_CONFIG_UPDATE_UNMODIFIED: *mut ZendString = 1isize as *mut ZendString;

#[repr(C)]
pub enum DynamicConfigUpdateMode {
    Read,
    ReadWrite,
    Write,
    Restore,
}

pub type DynamicConfigUpdate = for<'a> extern "C" fn(
    config: CharSlice,
    value: OwnedZendString,
    mode: DynamicConfigUpdateMode,
) -> *mut ZendString;

static mut LIVE_DEBUGGER_CALLBACKS: Option<LiveDebuggerCallbacks> = None;
static mut DYNAMIC_CONFIG_UPDATE: Option<DynamicConfigUpdate> = None;

type VecRemoteConfigProduct = libdd_common_ffi::Vec<RemoteConfigProduct>;
#[no_mangle]
pub static mut DDTRACE_REMOTE_CONFIG_PRODUCTS: VecRemoteConfigProduct = libdd_common_ffi::Vec::new();

type VecRemoteConfigCapabilities = libdd_common_ffi::Vec<RemoteConfigCapabilities>;
#[no_mangle]
pub static mut DDTRACE_REMOTE_CONFIG_CAPABILITIES: VecRemoteConfigCapabilities =
    libdd_common_ffi::Vec::new();

#[derive(Default)]
struct DynamicConfig {
    active_config_path: Option<String>,
    configs: Vec<Configs>,
    old_config_values: HashMap<String, Option<OwnedZendString>>,
}

pub struct RemoteConfigState {
    manager: RemoteConfigManager,
    live_debugger: LiveDebuggerState,
    dynamic_config: DynamicConfig,
}

#[repr(C)]
pub struct LiveDebuggerSetup<'a> {
    pub evaluator: &'a Evaluator,
    pub callbacks: LiveDebuggerCallbacks,
}

#[repr(C)]
#[derive(Clone)]
pub struct LiveDebuggerCallbacks {
    pub set_probe: extern "C" fn(probe: Probe, limiter: &MaybeShmLimiter) -> i64,
    pub remove_probe: extern "C" fn(id: i64),
}

#[derive(Default)]
pub struct LiveDebuggerState {
    pub spans_map: HashMap<String, i64>,
    pub active: HashMap<String, Box<(LiveDebuggingData, MaybeShmLimiter)>>,
    pub config_id: String,
    pub allow_dfa: Option<Regex>,
    pub deny_dfa: Option<Regex>,
}

#[no_mangle]
#[allow(static_mut_refs)]
pub unsafe extern "C" fn ddog_init_remote_config(
    live_debugging_enabled: bool,
    appsec_activation: bool,
    appsec_config: bool,
) {
    DDTRACE_REMOTE_CONFIG_PRODUCTS.push(RemoteConfigProduct::ApmTracing);
    DDTRACE_REMOTE_CONFIG_CAPABILITIES.push(RemoteConfigCapabilities::ApmTracingCustomTags);
    DDTRACE_REMOTE_CONFIG_CAPABILITIES.push(RemoteConfigCapabilities::ApmTracingEnabled);
    DDTRACE_REMOTE_CONFIG_CAPABILITIES.push(RemoteConfigCapabilities::ApmTracingHttpHeaderTags);
    DDTRACE_REMOTE_CONFIG_CAPABILITIES.push(RemoteConfigCapabilities::ApmTracingLogsInjection);
    DDTRACE_REMOTE_CONFIG_CAPABILITIES.push(RemoteConfigCapabilities::ApmTracingSampleRate);
    DDTRACE_REMOTE_CONFIG_CAPABILITIES.push(RemoteConfigCapabilities::ApmTracingSampleRules);

    DDTRACE_REMOTE_CONFIG_PRODUCTS.push(RemoteConfigProduct::AsmFeatures);
    DDTRACE_REMOTE_CONFIG_CAPABILITIES.push(RemoteConfigCapabilities::AsmAutoUserInstrumMode);

    if appsec_activation {
        DDTRACE_REMOTE_CONFIG_CAPABILITIES.push(RemoteConfigCapabilities::AsmActivation);
    }

    if live_debugging_enabled {
        DDTRACE_REMOTE_CONFIG_PRODUCTS.push(RemoteConfigProduct::LiveDebugger)
    }

    if appsec_config {
        DDTRACE_REMOTE_CONFIG_PRODUCTS.push(RemoteConfigProduct::AsmData);
        DDTRACE_REMOTE_CONFIG_PRODUCTS.push(RemoteConfigProduct::AsmDD);
        DDTRACE_REMOTE_CONFIG_PRODUCTS.push(RemoteConfigProduct::Asm);
        [
            RemoteConfigCapabilities::AsmIpBlocking,
            RemoteConfigCapabilities::AsmDdRules,
            RemoteConfigCapabilities::AsmExclusions,
            RemoteConfigCapabilities::AsmRequestBlocking,
            RemoteConfigCapabilities::AsmResponseBlocking,
            RemoteConfigCapabilities::AsmUserBlocking,
            RemoteConfigCapabilities::AsmCustomRules,
            RemoteConfigCapabilities::AsmCustomBlockingResponse,
            RemoteConfigCapabilities::AsmTrustedIps,
            RemoteConfigCapabilities::AsmRaspLfi,
            RemoteConfigCapabilities::AsmRaspSsrf,
            RemoteConfigCapabilities::AsmRaspSqli,
            RemoteConfigCapabilities::AsmTraceTaggingRules,
            RemoteConfigCapabilities::AsmDdMulticonfig,
            RemoteConfigCapabilities::AsmEndpointFingerprint,
            RemoteConfigCapabilities::AsmSessionFingerprint,
            RemoteConfigCapabilities::AsmNetworkFingerprint,
            RemoteConfigCapabilities::AsmHeaderFingerprint,
            RemoteConfigCapabilities::AsmProcessorOverrides,
            RemoteConfigCapabilities::AsmCustomDataScanners,
        ]
        .iter()
        .for_each(|c| DDTRACE_REMOTE_CONFIG_CAPABILITIES.push(*c));
    }
}

// Per-thread state
#[no_mangle]
pub unsafe extern "C" fn ddog_init_remote_config_state(
    endpoint: &Endpoint,
) -> Box<RemoteConfigState> {
    Box::new(RemoteConfigState {
        manager: RemoteConfigManager::new(ConfigInvariants {
            language: "php".to_string(),
            tracer_version: include_str!("../VERSION").trim().into(),
            endpoint: endpoint.clone(),
        }),
        live_debugger: LiveDebuggerState::default(),
        dynamic_config: Default::default(),
    })
}

#[derive(Serialize)]
struct SampleRule<'a> {
    #[serde(skip_serializing_if = "Option::is_none")]
    name: Option<&'a str>,
    service: &'a str,
    resource: &'a str,
    #[serde(skip_serializing_if = "HashMap::is_empty")]
    tags: HashMap<&'a str, &'a str>,
    #[serde(rename = "_provenance")]
    provenance: TracingSamplingRuleProvenance,
    sample_rate: f64,
}

fn bool_config(value: &bool) -> Cow<'static, str> {
    Cow::Borrowed(if *value { "1" } else { "0" })
}

fn map_config_name(config: &Configs) -> &'static str {
    match config {
        Configs::TracingHeaderTags(_) => "datadog.trace.header_tags",
        Configs::TracingSampleRate(_) => "datadog.trace.sample_rate",
        Configs::LogInjectionEnabled(_) => "datadog.logs_injection",
        Configs::TracingTags(_) => "datadog.tags",
        Configs::TracingEnabled(_) => "datadog.trace.enabled",
        Configs::TracingSamplingRules(_) => "datadog.trace.sampling_rules",
        Configs::DynamicInstrumentationEnabled(_) => "datadog.dynamic_instrumentation.enabled",
        Configs::ExceptionReplayEnabled(_) => "datadog.exception_replay_enabled",
        Configs::CodeOriginEnabled(_) => "datadog.code_origin_for_spans_enabled",
    }
}

fn map_config_value(config: &Configs) -> Cow<'_, str> {
    match config {
        Configs::TracingHeaderTags(tags) => tags.iter().map(|(k, _)| k).join(",").into(),
        Configs::TracingSampleRate(rate) => rate.to_string().into(),
        Configs::LogInjectionEnabled(enabled) => bool_config(enabled),
        Configs::TracingTags(tags) => tags.join(",").into(),
        Configs::TracingEnabled(enabled) => bool_config(enabled),
        Configs::TracingSamplingRules(rules) => {
            let map: Vec<_> = rules
                .iter()
                .map(|r| SampleRule {
                    name: r.name.as_deref(),
                    service: r.service.as_str(),
                    resource: r.resource.as_str(),
                    tags: r
                        .tags
                        .iter()
                        .map(|t| (t.key.as_str(), t.value_glob.as_str()))
                        .collect(),
                    provenance: r.provenance,
                    sample_rate: r.sample_rate,
                })
                .collect();
            serde_json::to_string(&map).unwrap().into()
        }
        Configs::DynamicInstrumentationEnabled(enabled) => bool_config(enabled),
        Configs::ExceptionReplayEnabled(enabled) => bool_config(enabled),
        Configs::CodeOriginEnabled(enabled) => bool_config(enabled),
    }
}

fn use_rc_config<'a>(config: &Configs, user_value: &'a [u8], _rc_value: &'a str) -> bool {
    match config {
        Configs::DynamicInstrumentationEnabled(_) | Configs::ExceptionReplayEnabled(_) | Configs::CodeOriginEnabled(_) => {
            let user_str = String::from_utf8_lossy(user_value);
            user_str.parse::<i32>().unwrap_or(0) != 0 || user_str.eq_ignore_ascii_case("true") || user_str.eq_ignore_ascii_case("yes") || user_str.eq_ignore_ascii_case("on")
        },
        _ => true,
    }
}

fn reset_old_config(name: &str, val: Option<OwnedZendString>) {
    unsafe {
        if let Some(val) = val {
            DYNAMIC_CONFIG_UPDATE.unwrap()(name.into(), val, DynamicConfigUpdateMode::Write);
        } else {
            DYNAMIC_CONFIG_UPDATE.unwrap()(name.into(), dangling_zend_string(), DynamicConfigUpdateMode::Restore);
        }
    }
}

fn remove_old_configs(remote_config: &mut RemoteConfigState) {
    for (name, val) in remote_config.dynamic_config.old_config_values.drain() {
        reset_old_config(name.as_str(), val);
    }
    remote_config.dynamic_config.old_config_values.clear();
    remote_config.dynamic_config.active_config_path = None;
}

fn insert_new_configs(
    old_config_values: &mut HashMap<String, Option<OwnedZendString>>,
    old_configs: &mut Vec<Configs>,
    new_configs: Vec<Configs>,
) {
    let mut found_configs = HashSet::new();
    for config in new_configs.iter() {
        let (name, val) = (map_config_name(config), map_config_value(config));
        let (is_update, merged) = {
            let old_value = old_config_values.get(name);
            let user_value = if let Some(old_zstr) = old_value {
                old_zstr.as_ref().map(|v| v.0)
            } else {
                let val = unsafe { DYNAMIC_CONFIG_UPDATE.unwrap()(name.into(), dangling_zend_string(), DynamicConfigUpdateMode::Read) };
                if val == DYANMIC_CONFIG_UPDATE_UNMODIFIED {
                    None
                } else {
                    Some(NonNull::new(val).unwrap())
                }
            };
            (old_value.is_some(), user_value.map(|v| {
                if use_rc_config(config, unsafe { v.as_ref() }.as_ref(), val.as_ref()) {
                    val.as_ref().into()
                } else {
                    OwnedZendString::from_copy(v)
                }
            }).unwrap_or_else(|| val.as_ref().into()))
        };

        let original = unsafe { DYNAMIC_CONFIG_UPDATE }.unwrap()(name.into(), merged, if is_update { DynamicConfigUpdateMode::Write } else { DynamicConfigUpdateMode::ReadWrite });
        if let Some(original) = NonNull::new(original) {
            old_config_values.insert(name.into(), if original.as_ptr() == DYANMIC_CONFIG_UPDATE_UNMODIFIED { None } else { Some(OwnedZendString(original)) });
        }
        found_configs.insert(mem::discriminant(config));
    }
    for config in old_configs.iter() {
        if !found_configs.contains(&mem::discriminant(config)) {
            let name = map_config_name(config);
            if let Some(val) = old_config_values.remove(name) {
                reset_old_config(name, val);
            }
        }
    }
    *old_configs = new_configs;
}

#[no_mangle]
pub extern "C" fn ddog_remote_config_get_path(remote_config: &RemoteConfigState) -> *const c_char {
    remote_config
        .manager
        .active_reader
        .as_ref()
        .map(|r| r.get_path().as_ptr())
        .unwrap_or(std::ptr::null())
}

#[no_mangle]
pub extern "C" fn ddog_process_remote_configs(remote_config: &mut RemoteConfigState) -> bool {
    let mut has_updates = false;
    loop {
        match remote_config.manager.fetch_update() {
            RemoteConfigUpdate::None => break,
            RemoteConfigUpdate::Add {
                value,
                limiter_index,
            } => match value.data {
                RemoteConfigData::LiveDebugger(debugger) => {
                    let val = Box::new((debugger, MaybeShmLimiter::open(limiter_index)));
                    let rc_ref = unsafe { mem::transmute(remote_config as *mut _) }; // sigh, borrow checker
                    let entry = remote_config.live_debugger.active.entry(value.config_id);
                    let (debugger, limiter) = &mut **match entry {
                        Entry::Occupied(mut e) => {
                            e.insert(val);
                            e.into_mut()
                        }
                        Entry::Vacant(e) => e.insert(val),
                    };
                    apply_config(rc_ref, debugger, limiter);
                }
                RemoteConfigData::DynamicConfig(config_data) => {
                    let configs: Vec<Configs> = config_data.lib_config.into();
                    if !configs.is_empty() {
                        insert_new_configs(
                            &mut remote_config.dynamic_config.old_config_values,
                            &mut remote_config.dynamic_config.configs,
                            configs,
                        );
                        remote_config.dynamic_config.active_config_path = Some(value.config_id);
                    }
                }
                RemoteConfigData::Ignored(_) => (),
                RemoteConfigData::TracerFlareConfig(_) => {}
                RemoteConfigData::TracerFlareTask(_) => {}
            },
            RemoteConfigUpdate::Remove(path) => match path.product {
                RemoteConfigProduct::LiveDebugger => {
                    if let Some(boxed) = remote_config.live_debugger.active.remove(&path.config_id)
                    {
                        remove_config(remote_config, &boxed.0);
                    }
                }
                RemoteConfigProduct::ApmTracing => {
                    if Some(path.config_id) == remote_config.dynamic_config.active_config_path {
                        remove_old_configs(remote_config);
                    }
                }
                _ => (),
            },
        }
        has_updates = true
    }
    has_updates
}

fn apply_config(
    remote_config: &mut RemoteConfigState,
    debugger: &LiveDebuggingData,
    limiter: &MaybeShmLimiter,
) {
    if let Some(callbacks) = unsafe { &LIVE_DEBUGGER_CALLBACKS } {
        match debugger {
            LiveDebuggingData::Probe(probe) => {
                debug!("Applying live debugger probe {probe:?}");
                let hook_id = (callbacks.set_probe)(probe.into(), limiter);
                if hook_id >= 0 {
                    remote_config
                        .live_debugger
                        .spans_map
                        .insert(probe.id.clone(), hook_id);
                }
            }
            LiveDebuggingData::ServiceConfiguration(config) => {
                debug!("Applying live debugger service config {config:?}");
                fn build_regex(list: &FilterList) -> Option<Regex> {
                    if list.classes.is_empty() && list.package_prefixes.is_empty() {
                        None
                    } else {
                        let mut regex = "".to_string();
                        for s in list.classes.iter() {
                            if !regex.is_empty() {
                                regex.push('|');
                            }
                            regex.push_str(&regex::escape(s.as_str()));
                        }
                        for s in list.package_prefixes.iter() {
                            if !regex.is_empty() {
                                regex.push('|');
                            }
                            regex.push_str(&regex::escape(s.as_str()));
                            regex.push_str(".*");
                        }
                        Some(Regex::new(regex.as_str()).unwrap())
                    }
                }
                remote_config.live_debugger.config_id = config.id.clone();
                remote_config.live_debugger.allow_dfa = build_regex(&config.allow);
                remote_config.live_debugger.deny_dfa = build_regex(&config.deny);
            }
        }
    }
}

fn remove_config(remote_config: &mut RemoteConfigState, debugger: &LiveDebuggingData) {
    if let Some(callbacks) = unsafe { &LIVE_DEBUGGER_CALLBACKS } {
        match debugger {
            LiveDebuggingData::Probe(probe) => {
                if let Some(id) = remote_config.live_debugger.spans_map.remove(&probe.id) {
                    debug!("Removing live debugger probe {}", probe.id);
                    (callbacks.remove_probe)(id);
                }
            }
            LiveDebuggingData::ServiceConfiguration(ServiceConfiguration { id, .. }) => {
                // There can only be one active service configuration, but I don't want to rely on the order of adding and removing service configurations
                if id == &remote_config.live_debugger.config_id {
                    debug!("Resetting live-debugger service config");
                    remote_config.live_debugger.allow_dfa = None;
                    remote_config.live_debugger.deny_dfa = None;
                }
            }
        }
    }
}

#[no_mangle]
pub extern "C" fn ddog_type_can_be_instrumented(
    remote_config: &RemoteConfigState,
    typename: CharSlice,
) -> bool {
    if ddog_snapshot_redacted_type(typename) {
        return false;
    }

    if let Some(regex) = &remote_config.live_debugger.allow_dfa {
        if !regex.is_match(typename.as_bytes()) {
            return false;
        }
    }

    if let Some(regex) = &remote_config.live_debugger.deny_dfa {
        if regex.is_match(typename.as_bytes()) {
            return false;
        }
    }

    true
}

#[no_mangle]
pub extern "C" fn ddog_global_log_probe_limiter_inc(remote_config: &RemoteConfigState) -> bool {
    if let Some(boxed) = remote_config
        .live_debugger
        .active
        .get(&remote_config.live_debugger.config_id)
    {
        if let (LiveDebuggingData::ServiceConfiguration(config), limiter) = &**boxed {
            limiter.inc(config.sampling_snapshots_per_second)
        } else {
            true
        }
    } else {
        true
    }
}

#[no_mangle]
pub unsafe extern "C" fn ddog_CharSlice_to_owned(str: CharSlice) -> *mut Vec<c_char> {
    Box::into_raw(Box::new(str.as_slice().into()))
}

#[no_mangle]
pub extern "C" fn ddog_remote_configs_service_env_change(
    remote_config: &mut RemoteConfigState,
    service: CharSlice,
    env: CharSlice,
    version: CharSlice,
    tags: &libdd_common_ffi::Vec<Tag>,
) -> bool {
    let new_target = Target {
        service: service.to_utf8_lossy().to_string(),
        env: env.to_utf8_lossy().to_string(),
        app_version: version.to_utf8_lossy().to_string(),
        tags: tags.as_slice().to_vec(),
    };

    if let Some(target) = remote_config.manager.get_target() {
        if **target == new_target {
            return false;
        }
    }

    remote_config.manager.track_target(&Arc::new(new_target));
    ddog_process_remote_configs(remote_config);

    true
}

#[no_mangle]
pub unsafe extern "C" fn ddog_remote_config_alter_dynamic_config(
    remote_config: &mut RemoteConfigState,
    config: CharSlice,
    new_value: OwnedZendString,
) -> bool {
    if let Some(entry) = remote_config
        .dynamic_config
        .old_config_values
        .get_mut(config.try_to_utf8().unwrap())
    {
        let mut ret = false;
        let config_name = config.to_utf8_lossy();
        for config in remote_config.dynamic_config.configs.iter() {
            let name = map_config_name(config);
            if name == config_name.as_ref() {
                let val = map_config_value(config);
                if !use_rc_config(config, new_value.as_ref().as_ref(), val.as_ref()) {
                    ret = true;
                }
                break;
            }
        }
        *entry = Some(new_value);
        return ret;
    }
    true
}

#[no_mangle]
pub unsafe extern "C" fn ddog_setup_remote_config(
    update_config: DynamicConfigUpdate,
    setup: &LiveDebuggerSetup,
) {
    ddog_register_expr_evaluator(setup.evaluator);
    DYNAMIC_CONFIG_UPDATE = Some(update_config);
    LIVE_DEBUGGER_CALLBACKS = Some(setup.callbacks.clone());
}

#[no_mangle]
pub extern "C" fn ddog_rshutdown_remote_config(remote_config: &mut RemoteConfigState) {
    remote_config.live_debugger.spans_map.clear();
    remote_config.dynamic_config.old_config_values.clear();
    remote_config.manager.unload_configs(&[
        RemoteConfigProduct::ApmTracing,
        RemoteConfigProduct::LiveDebugger,
    ]);
}

#[no_mangle]
pub extern "C" fn ddog_shutdown_remote_config(_: Box<RemoteConfigState>) {}

#[no_mangle]
pub extern "C" fn ddog_log_debugger_data(payloads: &Vec<DebuggerPayload>) {
    if !payloads.is_empty() {
        debug!(
            "Submitting debugger data: {}",
            serde_json::to_string(payloads).unwrap()
        );
    }
}

#[no_mangle]
pub extern "C" fn ddog_log_debugger_datum(payload: &DebuggerPayload) {
    debug!(
        "Submitting debugger data: {}",
        serde_json::to_string(payload).unwrap()
    );
}

#[no_mangle]
pub unsafe extern "C" fn ddog_send_debugger_diagnostics<'a>(
    remote_config_state: &RemoteConfigState,
    transport: &mut Box<SidecarTransport>,
    instance_id: &InstanceId,
    queue_id: QueueId,
    probe: &'a Probe,
    timestamp: u64,
) -> MaybeError {
    let service = Cow::Borrowed(
        remote_config_state
            .manager
            .get_target()
            .map_or("", |t| t.service.as_str()),
    );
    let mut payload = ddog_debugger_diagnostics_create_unboxed(
        probe,
        service,
        Cow::Borrowed(&instance_id.runtime_id),
        timestamp,
    );
    let DebuggerData::Diagnostics(ref mut diagnostics) = payload.debugger else {
        unreachable!();
    };
    diagnostics.parent_id = Some(Cow::Borrowed(
        remote_config_state.manager.current_runtime_id.as_str(),
    ));
    debug!(
        "Submitting debugger diagnostics data: {:?}",
        serde_json::to_string(&payload).unwrap()
    );

    ddog_sidecar_send_debugger_diagnostics(transport, instance_id, queue_id, payload)
}
