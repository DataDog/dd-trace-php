use datadog_live_debugger::{LiveDebuggingData, ProbeType, SpanProbe};
use datadog_live_debugger_ffi::data::ProbeTarget;
use datadog_live_debugger_ffi::evaluator::{register_expr_evaluator, Evaluator};
use datadog_remote_config::{RemoteConfigData, RemoteConfigProduct};
use datadog_sidecar::remote_config::{
    RemoteConfigIdentifier, RemoteConfigManager, RemoteConfigUpdate,
};
use ddcommon::Endpoint;
use ddcommon_ffi::slice::AsBytes;
use ddcommon_ffi::CharSlice;
use std::collections::HashMap;
use std::mem;

static mut LIVE_DEBUGGER_CALLBACKS: Option<LiveDebuggerCallbacks> = None;

pub struct RemoteConfigState {
    manager: RemoteConfigManager,
    live_debugger: LiveDebuggerState,
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
    })
}

#[no_mangle]
pub extern "C" fn ddog_process_remote_configs(remote_config: &mut RemoteConfigState) {
    loop {
        match remote_config.manager.fetch_update() {
            RemoteConfigUpdate::None => break,
            RemoteConfigUpdate::Update(update) => match update.data {
                RemoteConfigData::LiveDebugger(debugger) => {
                    apply_config(remote_config, &debugger);
                    remote_config
                        .live_debugger
                        .active
                        .insert(update.config_id, debugger);
                }
            },
            RemoteConfigUpdate::Remove(path) => match path.product {
                RemoteConfigProduct::LiveDebugger => {
                    if let Some(config) = remote_config.live_debugger.active.remove(&path.config_id)
                    {
                        remove_config(remote_config, &config);
                    }
                }
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
pub unsafe extern "C" fn ddog_init_live_debugger(setup: &LiveDebuggerSetup) {
    register_expr_evaluator(setup.evaluator);
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
}

#[no_mangle]
pub extern "C" fn ddog_shutdown_remote_config(_: Box<RemoteConfigState>) {}
