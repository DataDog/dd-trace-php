use datadog_live_debugger::{LiveDebuggingData, ProbeType, SpanProbe};
use datadog_live_debugger_ffi::data::ProbeTarget;
use datadog_live_debugger_ffi::evaluator::{register_expr_evaluator, Evaluator};
use datadog_remote_config::dynamic_configuration::data::Configs;
use datadog_remote_config::{RemoteConfigData, RemoteConfigProduct, Target};
use datadog_sidecar::remote_config::{
    RemoteConfigIdentifier, RemoteConfigManager, RemoteConfigUpdate,
};
use ddcommon::Endpoint;
use ddcommon_ffi::slice::AsBytes;
use ddcommon_ffi::CharSlice;
use itertools::Itertools;
use std::collections::{HashMap, HashSet};
use std::ffi::c_char;
use std::mem;

type DynamicConfigUpdate = for <'a> extern "C" fn(config: CharSlice, value: CharSlice, return_old: bool) -> *mut Vec<c_char>;

static mut LIVE_DEBUGGER_CALLBACKS: Option<LiveDebuggerCallbacks> = None;
static mut DYNAMIC_CONFIG_UPDATE: Option<DynamicConfigUpdate> = None;

#[derive(Default)]
struct DynamicConfig {
    path_map: HashMap<String, Target>,
    configs: HashMap<Target, Vec<Configs>>,
    old_config_values: HashMap<String, Vec<c_char>>,
}

pub struct RemoteConfigState {
    manager: RemoteConfigManager,
    live_debugger: LiveDebuggerState,
    dynamic_config: DynamicConfig,
    active_target: Option<Target>,
}

#[repr(C)]
pub struct LiveDebuggerSetup<'a> {
    pub evaluator: &'a Evaluator,
    pub callbacks: LiveDebuggerCallbacks,
}

#[repr(C)]
#[derive(Clone)]
pub struct LiveDebuggerCallbacks {
    pub set_span_probe: extern "C" fn(target: &ProbeTarget) -> i64,
    pub remove_span_probe: extern "C" fn(id: i64),
}

#[derive(Default)]
pub struct LiveDebuggerState {
    pub spans_map: HashMap<String, i64>,
    pub active: HashMap<String, LiveDebuggingData>,
}

// Per-thread state
#[no_mangle]
pub unsafe extern "C" fn ddog_init_remote_config(
    tracer_version: CharSlice,
    endpoint: &Endpoint,
) -> Box<RemoteConfigState> {
    Box::new(RemoteConfigState {
        manager: RemoteConfigManager::new(RemoteConfigIdentifier {
            language: "php".to_string(),
            tracer_version: tracer_version.to_utf8_lossy().into(),
            endpoint: endpoint.clone(),
        }),
        live_debugger: LiveDebuggerState::default(),
        dynamic_config: Default::default(),
        active_target: Default::default(),
    })
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
    }
}

fn remove_old_configs(remote_config: &mut RemoteConfigState) {
    for (name, val) in remote_config.dynamic_config.old_config_values.drain() {
        unsafe { DYNAMIC_CONFIG_UPDATE }.unwrap()(name.as_str().into(), (&val).into(), false);
    }
    remote_config.dynamic_config.old_config_values.clear();
}

fn insert_new_configs(old_config_values: &mut HashMap<String, Vec<c_char>>, old_configs: Option<&Vec<Configs>>, new_configs: &Vec<Configs>) {
    let mut found_configs = HashSet::new();
    for config in new_configs {
        let (name, val) = map_config(&config);
        let is_update = old_config_values.contains_key(name);
        let original = unsafe { DYNAMIC_CONFIG_UPDATE }.unwrap()(name.into(), val.as_str().into(), !is_update);
        if !original.is_null() {
            old_config_values.insert(name.into(), *unsafe { Box::from_raw(original) });
        }
        found_configs.insert(mem::discriminant(config));
    }
    if let Some(old) = old_configs {
        for config in old {
            if !found_configs.contains(&mem::discriminant(config)) {
                let (name, _) = map_config(config);
                if let Some(val) = old_config_values.remove(name) {
                    unsafe { DYNAMIC_CONFIG_UPDATE }.unwrap()(name.into(), (&val).into(), false);
                }
            }
        }
    }
}

#[no_mangle]
pub extern "C" fn ddog_process_remote_configs(remote_config: &mut RemoteConfigState) {
    loop {
        match remote_config.manager.fetch_update() {
            RemoteConfigUpdate::None => break,
            RemoteConfigUpdate::Update(update, target) => match update.data {
                RemoteConfigData::LiveDebugger(debugger) => {
                    apply_config(remote_config, &debugger);
                    remote_config
                        .live_debugger
                        .active
                        .insert(update.config_id, debugger);
                },
                RemoteConfigData::DynamicConfig(config_data) => {
                    let target = target.unwrap();
                    let configs = config_data.lib_config.into();
                    if remote_config.active_target.as_ref().map_or(false, |t| *t == target) {
                        insert_new_configs(&mut remote_config.dynamic_config.old_config_values, remote_config.dynamic_config.configs.get(&target), &configs)
                    }
                    remote_config.dynamic_config.path_map.insert(update.config_id, target.clone());
                    remote_config.dynamic_config.configs.insert(target, configs);
                },
            },
            RemoteConfigUpdate::Remove(path) => match path.product {
                RemoteConfigProduct::LiveDebugger => {
                    if let Some(config) = remote_config.live_debugger.active.remove(&path.config_id) {
                        remove_config(remote_config, &config);
                    }
                },
                RemoteConfigProduct::ApmTracing => {
                    if let Some(target) = remote_config.dynamic_config.path_map.remove(&path.config_id) {
                        if remote_config.active_target == Some(target) {
                            remove_old_configs(remote_config);
                        }
                    }
                },
            },
        }
    }
}

fn apply_config(remote_config: &mut RemoteConfigState, debugger: &LiveDebuggingData) {
    if let Some(callbacks) = unsafe { &LIVE_DEBUGGER_CALLBACKS } {
        match debugger {
            LiveDebuggingData::Probe(probe) => match probe.probe {
                ProbeType::Metric(_) => {}
                ProbeType::Log(_) => {}
                ProbeType::Span(SpanProbe {}) => {
                    let proberef = (&probe.target).into();
                    let id = (callbacks.set_span_probe)(&proberef);
                    if id >= 0 {
                        remote_config
                            .live_debugger
                            .spans_map
                            .insert(probe.id.clone(), id);
                    }
                }
                ProbeType::SpanDecoration(_) => {}
            },
            LiveDebuggingData::ServiceConfiguration(_) => {}
        }
    }
}

fn remove_config(remote_config: &mut RemoteConfigState, debugger: &LiveDebuggingData) {
    if let Some(callbacks) = unsafe { &LIVE_DEBUGGER_CALLBACKS } {
        match debugger {
            LiveDebuggingData::Probe(probe) => match probe.probe {
                ProbeType::Metric(_) => {}
                ProbeType::Log(_) => {}
                ProbeType::Span(SpanProbe {}) => {
                    if let Some(id) = remote_config.live_debugger.spans_map.remove(&probe.id) {
                        (callbacks.remove_span_probe)(id);
                    }
                }
                ProbeType::SpanDecoration(_) => {}
            },
            LiveDebuggingData::ServiceConfiguration(_) => {}
        }
    }
}

#[no_mangle]
pub unsafe extern "C" fn ddog_CharSlice_to_owned(str: CharSlice) -> *mut Vec<c_char> {
    Box::into_raw(Box::new(str.as_slice().into()))
}

#[no_mangle]
pub extern "C" fn ddog_remote_configs_service_env_change(remote_config: &mut RemoteConfigState, service: CharSlice, env: CharSlice) {
    let new_target = Target {
        service: service.to_utf8_lossy().to_string(),
        env: env.to_utf8_lossy().to_string(),
    };

    if Some(&new_target) == remote_config.active_target.as_ref() {
        return;
    }

    if let Some(configs) = remote_config.dynamic_config.configs.get(&new_target) {
        let current_config = remote_config.active_target.as_ref().and_then(|t| remote_config.dynamic_config.configs.get(&t));
        insert_new_configs(&mut remote_config.dynamic_config.old_config_values, current_config, configs);
    } else {
        remove_old_configs(remote_config);
    }

    remote_config.active_target = Some(new_target);
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
pub unsafe extern "C" fn ddog_setup_dynamic_configuration(update_config: DynamicConfigUpdate, setup: &LiveDebuggerSetup) {
    register_expr_evaluator(setup.evaluator);
    DYNAMIC_CONFIG_UPDATE = Some(update_config);
    LIVE_DEBUGGER_CALLBACKS = Some(setup.callbacks.clone());
}

#[no_mangle]
pub extern "C" fn ddog_rinit_remote_config(remote_config: &mut RemoteConfigState) {
    let active = mem::take(&mut remote_config.live_debugger.active);
    for (_, debugger) in &active {
        apply_config(remote_config, debugger);
    }
    remote_config.live_debugger.active = active;
    ddog_process_remote_configs(remote_config);
}

#[no_mangle]
pub extern "C" fn ddog_rshutdown_remote_config(remote_config: &mut RemoteConfigState) {
    remote_config.live_debugger.spans_map.clear();
    remote_config.dynamic_config.old_config_values.clear();
    remote_config.active_target = None;
}

#[no_mangle]
pub extern "C" fn ddog_shutdown_remote_config(_: Box<RemoteConfigState>) {}
