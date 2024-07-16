use std::borrow::Cow;
use datadog_live_debugger::{FilterList, LiveDebuggingData, ServiceConfiguration};
use datadog_live_debugger_ffi::data::{Probe};
use datadog_live_debugger_ffi::evaluator::{ddog_register_expr_evaluator, Evaluator};
use datadog_dynamic_configuration::{Configs, data::TracingSamplingRuleProvenance};
use datadog_remote_config::{RemoteConfigCapabilities, RemoteConfigData, RemoteConfigProduct, Target};
use datadog_remote_config::fetch::ConfigInvariants;
use datadog_sidecar::shm_remote_config::{RemoteConfigManager, RemoteConfigUpdate};
use ddcommon::Endpoint;
use ddcommon_ffi::slice::AsBytes;
use ddcommon_ffi::{CharSlice, MaybeError};
use itertools::Itertools;
use std::collections::{HashMap, HashSet};
use std::collections::hash_map::Entry;
use std::ffi::c_char;
use std::mem;
use std::sync::Arc;
use serde::Serialize;
use crate::sidecar::MaybeShmLimiter;
use regex_automata::dfa::regex::Regex;
use tracing::debug;
use datadog_live_debugger::debugger_defs::{DebuggerData, DebuggerPayload};
use datadog_live_debugger_ffi::send_data::{ddog_debugger_diagnostics_create_unboxed, ddog_snapshot_redacted_type};
use datadog_sidecar::service::blocking::SidecarTransport;
use datadog_sidecar::service::{InstanceId, QueueId};
use datadog_sidecar_ffi::ddog_sidecar_send_debugger_data;

type DynamicConfigUpdate = for <'a> extern "C" fn(config: CharSlice, value: CharSlice, return_old: bool) -> *mut Vec<c_char>;

static mut LIVE_DEBUGGER_CALLBACKS: Option<LiveDebuggerCallbacks> = None;
static mut DYNAMIC_CONFIG_UPDATE: Option<DynamicConfigUpdate> = None;

#[no_mangle]
pub static DDTRACE_REMOTE_CONFIG_PRODUCTS: [RemoteConfigProduct; 2] = [
    RemoteConfigProduct::ApmTracing,
    RemoteConfigProduct::LiveDebugger,
];

#[no_mangle]
pub static DDTRACE_REMOTE_CONFIG_CAPABILITIES: [RemoteConfigCapabilities; 6] = [
    RemoteConfigCapabilities::ApmTracingCustomTags,
    RemoteConfigCapabilities::ApmTracingEnabled,
    RemoteConfigCapabilities::ApmTracingHttpHeaderTags,
    RemoteConfigCapabilities::ApmTracingLogsInjection,
    RemoteConfigCapabilities::ApmTracingSampleRate,
    RemoteConfigCapabilities::ApmTracingSampleRules,
];

#[derive(Default)]
struct DynamicConfig {
    active_config_path: Option<String>,
    configs: Vec<Configs>,
    old_config_values: HashMap<String, Vec<c_char>>,
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

// Per-thread state
#[no_mangle]
pub unsafe extern "C" fn ddog_init_remote_config(
    tracer_version: CharSlice,
    endpoint: &Endpoint,
) -> Box<RemoteConfigState> {
    Box::new(RemoteConfigState {
        manager: RemoteConfigManager::new(ConfigInvariants {
            language: "php".to_string(),
            tracer_version: tracer_version.to_utf8_lossy().into(),
            endpoint: endpoint.clone(),
            products: DDTRACE_REMOTE_CONFIG_PRODUCTS.to_vec(),
            capabilities: DDTRACE_REMOTE_CONFIG_CAPABILITIES.to_vec(),
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

fn map_config(config: &Configs) -> (&'static str, String) {
    match config {
        Configs::TracingHeaderTags(tags) => {
            ("datadog.trace.header_tags", tags.iter().map(|(k, _)| k).join(","))
        }
        Configs::TracingSampleRate(rate) => {
            ("datadog.trace.sample_rate", rate.to_string())
        }
        Configs::LogInjectionEnabled(enabled) => {
            ("datadog.logs_injection", (if *enabled { "1" } else { "0" }).to_string())
        }
        Configs::TracingTags(tags) => {
            ("datadog.tags", tags.join(","))
        }
        Configs::TracingEnabled(enabled) => {
            ("datadog.trace.enabled", (if *enabled { "1" } else { "0" }).to_string())
        }
        Configs::TracingSamplingRules(rules) => {
            let map: Vec<_> = rules.iter().map(|r| SampleRule {
                name: r.name.as_deref(),
                service: r.service.as_str(),
                resource: r.resource.as_str(),
                tags: r.tags.iter().map(|t| (t.key.as_str(), t.value_glob.as_str())).collect(),
                provenance: r.provenance,
                sample_rate: r.sample_rate,
            }).collect();
            ("datadog.trace.sampling_rules", serde_json::to_string(&map).unwrap())
        }
    }
}

fn remove_old_configs(remote_config: &mut RemoteConfigState) {
    for (name, val) in remote_config.dynamic_config.old_config_values.drain() {
        unsafe { DYNAMIC_CONFIG_UPDATE }.unwrap()(name.as_str().into(), (&val).into(), false);
    }
    remote_config.dynamic_config.old_config_values.clear();
    remote_config.dynamic_config.active_config_path = None;
}

fn insert_new_configs(old_config_values: &mut HashMap<String, Vec<c_char>>, old_configs: &mut Vec<Configs>, new_configs: Vec<Configs>) {
    let mut found_configs = HashSet::new();
    for config in new_configs.iter() {
        let (name, val) = map_config(config);
        let is_update = old_config_values.contains_key(name);
        let original = unsafe { DYNAMIC_CONFIG_UPDATE }.unwrap()(name.into(), val.as_str().into(), !is_update);
        if !original.is_null() {
            old_config_values.insert(name.into(), *unsafe { Box::from_raw(original) });
        }
        found_configs.insert(mem::discriminant(config));
    }
    for config in old_configs.iter() {
        if !found_configs.contains(&mem::discriminant(config)) {
            let (name, _) = map_config(config);
            if let Some(val) = old_config_values.remove(name) {
                unsafe { DYNAMIC_CONFIG_UPDATE }.unwrap()(name.into(), (&val).into(), false);
            }
        }
    }
    *old_configs = new_configs;
}

#[no_mangle]
pub extern "C" fn ddog_process_remote_configs(remote_config: &mut RemoteConfigState) {
    loop {
        match remote_config.manager.fetch_update() {
            RemoteConfigUpdate::None => break,
            RemoteConfigUpdate::Add { value, limiter_index } => match value.data {
                RemoteConfigData::LiveDebugger(debugger) => {
                    let val = Box::new((debugger, MaybeShmLimiter::open(limiter_index)));
                    let rc_ref = unsafe { mem::transmute(remote_config as *mut _) }; // sigh, borrow checker
                    let entry = remote_config.live_debugger.active.entry(value.config_id);
                    let (debugger, limiter) = &mut **match entry {
                        Entry::Occupied(mut e) => {
                            e.insert(val);
                            e.into_mut()
                        }
                        Entry::Vacant(e) => {
                            e.insert(val)
                        }
                    };
                    apply_config(rc_ref, debugger, limiter);
                },
                RemoteConfigData::DynamicConfig(config_data) => {
                    let configs: Vec<Configs> = config_data.lib_config.into();
                    if !configs.is_empty() {
                        insert_new_configs(&mut remote_config.dynamic_config.old_config_values, &mut remote_config.dynamic_config.configs, configs);
                        remote_config.dynamic_config.active_config_path = Some(value.config_id);
                    }
                },
            },
            RemoteConfigUpdate::Remove(path) => match path.product {
                RemoteConfigProduct::LiveDebugger => {
                    if let Some(boxed) = remote_config.live_debugger.active.remove(&path.config_id) {
                        remove_config(remote_config, &boxed.0);
                    }
                },
                RemoteConfigProduct::ApmTracing => {
                    if Some(path.config_id) == remote_config.dynamic_config.active_config_path {
                        remove_old_configs(remote_config);
                    }
                },
            },
        }
    }
}

fn apply_config(remote_config: &mut RemoteConfigState, debugger: &LiveDebuggingData, limiter: &MaybeShmLimiter) {
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
            },
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
            },
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
pub extern "C" fn ddog_type_can_be_instrumented(remote_config: &RemoteConfigState, typename: CharSlice) -> bool {
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
    if let Some(boxed) = remote_config.live_debugger.active.get(&remote_config.live_debugger.config_id) {
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
pub extern "C" fn ddog_remote_configs_service_env_change(remote_config: &mut RemoteConfigState, service: CharSlice, env: CharSlice, version: CharSlice) {
    let new_target = Target {
        service: service.to_utf8_lossy().to_string(),
        env: env.to_utf8_lossy().to_string(),
        app_version: version.to_utf8_lossy().to_string(),
    };

    if let Some(target) = remote_config.manager.get_target() {
        if **target == new_target {
            return;
        }
    }

    remote_config.manager.track_target(&Arc::new(new_target));
    ddog_process_remote_configs(remote_config);
}

#[no_mangle]
pub unsafe extern "C" fn ddog_remote_config_alter_dynamic_config(remote_config: &mut RemoteConfigState, config: CharSlice, new_value: CharSlice) -> bool {
    if let Some(entry) = remote_config.dynamic_config.old_config_values.get_mut(config.try_to_utf8().unwrap()) {
        *entry = new_value.as_slice().into();
        return false;
    }
    true
}

#[no_mangle]
pub unsafe extern "C" fn ddog_setup_remote_config(update_config: DynamicConfigUpdate, setup: &LiveDebuggerSetup) {
    ddog_register_expr_evaluator(setup.evaluator);
    DYNAMIC_CONFIG_UPDATE = Some(update_config);
    LIVE_DEBUGGER_CALLBACKS = Some(setup.callbacks.clone());
}

#[no_mangle]
pub extern "C" fn ddog_rinit_remote_config(remote_config: &mut RemoteConfigState) {
    let active = mem::take(&mut remote_config.live_debugger.active);
    for (_, boxed) in &active {
        let (debugger, limiter) = &**boxed;
        apply_config(remote_config, debugger, limiter);
    }
    remote_config.live_debugger.active = active;
    ddog_process_remote_configs(remote_config);
}

#[no_mangle]
pub extern "C" fn ddog_rshutdown_remote_config(remote_config: &mut RemoteConfigState) {
    remote_config.live_debugger.spans_map.clear();
    remote_config.dynamic_config.old_config_values.clear();
    remote_config.manager.reset();
}

#[no_mangle]
pub extern "C" fn ddog_shutdown_remote_config(_: Box<RemoteConfigState>) {}

#[no_mangle]
pub extern "C" fn ddog_log_debugger_data(payloads: &Vec<DebuggerPayload>) {
    if !payloads.is_empty() {
        debug!("Submitting debugger data: {}", serde_json::to_string(payloads).unwrap());
    }
}

#[no_mangle]
pub extern "C" fn ddog_log_debugger_datum(payload: &DebuggerPayload) {
    debug!("Submitting debugger data: {}", serde_json::to_string(payload).unwrap());
}

#[no_mangle]
pub unsafe extern "C" fn ddog_send_debugger_diagnostics<'a>(remote_config_state: &RemoteConfigState, transport: &mut Box<SidecarTransport>, instance_id: &InstanceId, queue_id: QueueId, probe: &'a Probe, timestamp: u64) -> MaybeError {
    let service = Cow::Borrowed(remote_config_state.manager.get_target().map_or("", |t| t.service.as_str()));
    let mut payload = ddog_debugger_diagnostics_create_unboxed(probe, service, Cow::Borrowed(&instance_id.runtime_id), timestamp);
    let DebuggerData::Diagnostics(ref mut diagnostics) = payload.debugger else { unreachable!(); };
    diagnostics.parent_id = Some(Cow::Borrowed(remote_config_state.manager.current_runtime_id.as_str()));
    debug!("Submitting debugger diagnostics data: {:?}", serde_json::to_string(&payload).unwrap());
    ddog_sidecar_send_debugger_data(transport, instance_id, queue_id, vec![payload])
}
