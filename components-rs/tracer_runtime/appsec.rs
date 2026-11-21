// Copyright 2021-Present Datadog, Inc. https://www.datadoghq.com/
// SPDX-License-Identifier: Apache-2.0

//! The extension's side of the AppSec protocol (see appsec_protocol.rs): the request data C
//! writes as MessagePack is sent to the connection's AppSec client in the sidecar, and the
//! verdict is handed back as a borrowed view.

use datadog_sidecar::appsec::protocol::{
    AppSecAddresses, AppSecBytes, AppSecOutcome, AppSecRaspRule, AppSecRequest,
    AppSecSessionConfig, AppSecVerdict,
};
use datadog_sidecar::service::blocking::{self, SidecarTransport};
use libdd_common_ffi::slice::AsBytes;
use libdd_common_ffi::CharSlice;
use std::cell::Cell;
use std::ffi::c_char;

/// The AppSec settings of the connection, see `AppSecSessionConfig`.
#[repr(C)]
pub struct AppSecSessionConfigFfi<'a> {
    pub rules_file: CharSlice<'a>,
    pub waf_timeout_us: u64,
    pub trace_rate_limit: u32,
    pub obfuscator_key_regex: CharSlice<'a>,
    pub obfuscator_value_regex: CharSlice<'a>,
    pub schema_extraction_enabled: bool,
    pub schema_extraction_sampling_period: f64,
    pub helper_log_file: CharSlice<'a>,
    pub helper_log_level: CharSlice<'a>,
}

/// Sets the AppSec settings sent with the connection config, or none (`config` NULL): the
/// connection then has no AppSec client. Call before `ddog_sidecar_set_connection_config`.
#[no_mangle]
pub extern "C" fn ddog_sidecar_set_appsec_session_config(
    transport: &mut Box<SidecarTransport>,
    config: Option<&AppSecSessionConfigFfi>,
) {
    let config = config.map(|config| AppSecSessionConfig {
        rules_file: config.rules_file.to_utf8_lossy().into_owned(),
        waf_timeout_us: config.waf_timeout_us,
        trace_rate_limit: config.trace_rate_limit,
        obfuscator_key_regex: config.obfuscator_key_regex.to_utf8_lossy().into_owned(),
        obfuscator_value_regex: config.obfuscator_value_regex.to_utf8_lossy().into_owned(),
        schema_extraction_enabled: config.schema_extraction_enabled,
        schema_extraction_sampling_period: config.schema_extraction_sampling_period,
        helper_log_file: config.helper_log_file.as_bytes().to_vec(),
        helper_log_level: config.helper_log_level.to_utf8_lossy().into_owned(),
    });
    blocking::set_appsec_session_config(transport, config);
}

#[repr(C)]
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum AppSecVerdictKind {
    /// No AppSec client handled the request: skip AppSec for the rest of the request.
    Unavailable,
    /// The client failed the request; `error` says why.
    Error,
    Ok,
    /// The sidecar could not be reached; `error` says why.
    TransportError,
}

#[repr(C)]
#[derive(Clone, Copy)]
pub struct AppSecStrPair {
    pub key: CharSlice<'static>,
    pub value: CharSlice<'static>,
}

#[repr(C)]
#[derive(Clone, Copy)]
pub struct AppSecMetric {
    pub key: CharSlice<'static>,
    pub value: f64,
}

#[repr(C)]
#[derive(Clone, Copy)]
pub struct AppSecActionView {
    pub action: CharSlice<'static>,
    pub parameters: *const AppSecStrPair,
    pub parameters_len: usize,
}

/// A verdict, borrowed from its `ddog_AppSecVerdictResult`.
#[repr(C)]
pub struct AppSecVerdictView {
    pub kind: AppSecVerdictKind,
    pub error: CharSlice<'static>,
    pub actions: *const AppSecActionView,
    pub actions_len: usize,
    /// JSON fragments of the triggered events.
    pub triggers: *const CharSlice<'static>,
    pub triggers_len: usize,
    pub force_keep: bool,
    pub meta: *const AppSecStrPair,
    pub meta_len: usize,
    pub metrics: *const AppSecMetric,
    pub metrics_len: usize,
}

/// An owned verdict and the arrays its view points into.
pub struct AppSecVerdictResult {
    view: AppSecVerdictView,
    // The view borrows from these: the heap data does not move with the struct.
    _error: String,
    _outcome: AppSecOutcome,
    _parameters: Vec<Vec<AppSecStrPair>>,
    _actions: Vec<AppSecActionView>,
    _triggers: Vec<CharSlice<'static>>,
    _meta: Vec<AppSecStrPair>,
    _metrics: Vec<AppSecMetric>,
}

fn slice(s: &str) -> CharSlice<'static> {
    // SAFETY: the strings are owned by the AppSecVerdictResult the slice is handed out with,
    // and are neither modified nor dropped before it.
    unsafe { CharSlice::from_raw_parts(s.as_ptr().cast::<c_char>(), s.len()) }
}

impl AppSecVerdictResult {
    fn new(kind: AppSecVerdictKind, error: String, outcome: AppSecOutcome) -> Box<Self> {
        let parameters: Vec<Vec<AppSecStrPair>> = outcome
            .actions
            .iter()
            .map(|action| {
                action
                    .parameters
                    .iter()
                    .map(|(key, value)| AppSecStrPair {
                        key: slice(key),
                        value: slice(value),
                    })
                    .collect()
            })
            .collect();
        let actions: Vec<AppSecActionView> = outcome
            .actions
            .iter()
            .zip(&parameters)
            .map(|(action, parameters)| AppSecActionView {
                action: slice(&action.action),
                parameters: parameters.as_ptr(),
                parameters_len: parameters.len(),
            })
            .collect();
        let triggers: Vec<CharSlice<'static>> = outcome.triggers.iter().map(|t| slice(t)).collect();
        let meta: Vec<AppSecStrPair> = outcome
            .meta
            .iter()
            .map(|(key, value)| AppSecStrPair {
                key: slice(key),
                value: slice(value),
            })
            .collect();
        let metrics: Vec<AppSecMetric> = outcome
            .metrics
            .iter()
            .map(|(key, value)| AppSecMetric {
                key: slice(key),
                value: *value,
            })
            .collect();
        let view = AppSecVerdictView {
            kind,
            error: slice(&error),
            actions: actions.as_ptr(),
            actions_len: actions.len(),
            triggers: triggers.as_ptr(),
            triggers_len: triggers.len(),
            force_keep: outcome.force_keep,
            meta: meta.as_ptr(),
            meta_len: meta.len(),
            metrics: metrics.as_ptr(),
            metrics_len: metrics.len(),
        };
        Box::new(AppSecVerdictResult {
            view,
            _error: error,
            _outcome: outcome,
            _parameters: parameters,
            _actions: actions,
            _triggers: triggers,
            _meta: meta,
            _metrics: metrics,
        })
    }

    fn from_verdict(verdict: AppSecVerdict) -> Box<Self> {
        match verdict {
            AppSecVerdict::Unavailable => Self::new(
                AppSecVerdictKind::Unavailable,
                String::new(),
                Default::default(),
            ),
            AppSecVerdict::Error(error) => {
                Self::new(AppSecVerdictKind::Error, error, Default::default())
            }
            AppSecVerdict::Ok(outcome) => Self::new(AppSecVerdictKind::Ok, String::new(), outcome),
        }
    }

    fn transport_error(error: impl ToString) -> Box<Self> {
        Self::new(
            AppSecVerdictKind::TransportError,
            error.to_string(),
            Default::default(),
        )
    }
}

#[no_mangle]
pub extern "C" fn ddog_appsec_verdict_view(result: &AppSecVerdictResult) -> &AppSecVerdictView {
    &result.view
}

#[no_mangle]
pub extern "C" fn ddog_appsec_verdict_drop(_: Box<AppSecVerdictResult>) {}

fn addresses(msgpack: CharSlice) -> AppSecAddresses {
    AppSecAddresses(AppSecBytes(msgpack.as_bytes().to_vec()))
}

fn send(
    transport: &mut Option<Box<SidecarTransport>>,
    request: AppSecRequest,
    reconnect: bool,
) -> Box<AppSecVerdictResult> {
    if let Some(fd) = MOCK_FD.with(Cell::get) {
        return testing::exchange(fd, &request);
    }
    let Some(transport) = transport else {
        return AppSecVerdictResult::transport_error("no sidecar connection");
    };
    match blocking::send_appsec_request(transport, &request, reconnect) {
        Ok(verdict) => AppSecVerdictResult::from_verdict(verdict),
        Err(error) => AppSecVerdictResult::transport_error(error),
    }
}

/// Starts an AppSec request with `addresses`, a MessagePack map. `*transport` may be NULL, as may
/// be the sidecar connection. The client binds to the connection's current application first,
/// unless `keep_bound_application` is set and some application is bound already.
#[no_mangle]
pub extern "C" fn ddog_appsec_request_init(
    transport: &mut Option<Box<SidecarTransport>>,
    addresses: CharSlice,
    keep_bound_application: bool,
) -> Box<AppSecVerdictResult> {
    let request = AppSecRequest::Init {
        addresses: self::addresses(addresses),
        keep_bound_application,
    };
    // A new connection gets a new client from the replayed session config.
    send(transport, request, true)
}

/// Runs the WAF on `addresses`, a MessagePack map, during the AppSec request: as the RASP check
/// `rasp_rule` (with `rule_variant`) unless empty.
#[no_mangle]
pub extern "C" fn ddog_appsec_request_exec(
    transport: &mut Option<Box<SidecarTransport>>,
    addresses: CharSlice,
    rasp_rule: CharSlice,
    rule_variant: CharSlice,
) -> Box<AppSecVerdictResult> {
    let rasp = (!rasp_rule.is_empty()).then(|| AppSecRaspRule {
        rule: rasp_rule.to_utf8_lossy().into_owned(),
        variant: (!rule_variant.is_empty()).then(|| rule_variant.to_utf8_lossy().into_owned()),
    });
    let request = AppSecRequest::Exec {
        addresses: self::addresses(addresses),
        rasp,
    };
    // The client of a new connection would not know the request.
    send(transport, request, false)
}

/// Finishes the AppSec request with `addresses`, a MessagePack map.
#[no_mangle]
pub extern "C" fn ddog_appsec_request_shutdown(
    transport: &mut Option<Box<SidecarTransport>>,
    addresses: CharSlice,
    api_sec_samp_key: u64,
    input_truncated: bool,
) -> Box<AppSecVerdictResult> {
    let request = AppSecRequest::Shutdown {
        addresses: self::addresses(addresses),
        api_sec_samp_key,
        input_truncated,
    };
    send(transport, request, false)
}

thread_local! {
    static MOCK_FD: Cell<Option<i32>> = const { Cell::new(None) };
}

/// Testing: requests go to the mock helper at `fd` instead of the sidecar, as JSON (a negative
/// fd stops this). The fd stays owned by the caller.
#[no_mangle]
pub extern "C" fn ddog_appsec_testing_set_mock_fd(fd: i32) {
    MOCK_FD.with(|mock| mock.set((fd >= 0).then_some(fd)));
}

/// The mock helper of the extension tests (appsec/tests/mock_helper) speaks JSON in frames of a
/// "dds\0" marker and a native-endian u32 size. Requests are sent as
/// `["<command>", [<args>...]]`, verdicts are scripted as a list of `["<command>", [<args>]]`
/// responses, of which the first counts.
mod testing {
    use super::*;
    use serde_json::Value;
    use std::fmt::Write as _;
    use std::io::{Read, Write};
    use std::mem::ManuallyDrop;
    use std::os::fd::FromRawFd;

    pub(super) fn exchange(fd: i32, request: &AppSecRequest) -> Box<AppSecVerdictResult> {
        // SAFETY: the fd belongs to the caller of ddog_appsec_testing_set_mock_fd; ManuallyDrop
        // keeps it open.
        let mut file = ManuallyDrop::new(unsafe { std::fs::File::from_raw_fd(fd) });
        let Some((command, body)) = encode_request(request) else {
            return AppSecVerdictResult::transport_error("malformed request addresses");
        };
        let mut frame = Vec::with_capacity(body.len() + 8);
        frame.extend_from_slice(b"dds\0");
        let Ok(size) = u32::try_from(body.len()) else {
            return AppSecVerdictResult::transport_error("request too large");
        };
        frame.extend_from_slice(&size.to_ne_bytes());
        frame.extend_from_slice(body.as_bytes());
        if let Err(e) = file.write_all(&frame) {
            return AppSecVerdictResult::transport_error(format!("mock helper write: {e}"));
        }
        let mut header = [0u8; 8];
        if let Err(e) = file.read_exact(&mut header) {
            return AppSecVerdictResult::transport_error(format!("mock helper read: {e}"));
        }
        let size = u32::from_ne_bytes([header[4], header[5], header[6], header[7]]);
        let mut response = vec![0u8; size as usize];
        if let Err(e) = file.read_exact(&mut response) {
            return AppSecVerdictResult::transport_error(format!("mock helper read: {e}"));
        }
        AppSecVerdictResult::from_verdict(decode_response(command, &response))
    }

    pub(super) fn encode_request(request: &AppSecRequest) -> Option<(&'static str, String)> {
        let mut out = String::new();
        let command = match request {
            AppSecRequest::Init { addresses, .. } => {
                out.push_str("[\"request_init\",[");
                write_addresses(&mut out, addresses)?;
                out.push_str("]]");
                "request_init"
            }
            AppSecRequest::Exec { addresses, rasp } => {
                out.push_str("[\"request_exec\",[");
                write_addresses(&mut out, addresses)?;
                out.push_str(",{");
                if let Some(rasp) = rasp {
                    write_str(&mut out, b"rasp_rule");
                    out.push(':');
                    write_str(&mut out, rasp.rule.as_bytes());
                    if let Some(variant) = &rasp.variant {
                        out.push(',');
                        write_str(&mut out, b"rule_variant");
                        out.push(':');
                        write_str(&mut out, variant.as_bytes());
                    }
                }
                out.push_str("}]]");
                "request_exec"
            }
            AppSecRequest::Shutdown {
                addresses,
                api_sec_samp_key,
                input_truncated,
            } => {
                out.push_str("[\"request_shutdown\",[");
                write_addresses(&mut out, addresses)?;
                let _ = write!(out, ",{api_sec_samp_key},{input_truncated}]]");
                "request_shutdown"
            }
        };
        Some((command, out))
    }

    pub(super) fn write_addresses(out: &mut String, addresses: &AppSecAddresses) -> Option<()> {
        let mut data = addresses.0.as_slice();
        let value = rmpv::decode::read_value(&mut data).ok()?;
        write_value(out, &value);
        data.is_empty().then_some(())
    }

    // Written by hand: the mock reports maps in order, duplicate keys included.
    fn write_value(out: &mut String, value: &rmpv::Value) {
        use rmpv::Value;
        match value {
            Value::Nil | Value::Ext(..) => out.push_str("null"),
            Value::Boolean(b) => out.push_str(if *b { "true" } else { "false" }),
            Value::Integer(i) => {
                let _ = write!(out, "{i}");
            }
            Value::F32(f) => write_float(out, f64::from(*f)),
            Value::F64(f) => write_float(out, *f),
            Value::String(s) => write_str(out, s.as_bytes()),
            Value::Binary(b) => write_str(out, b),
            Value::Array(items) => {
                out.push('[');
                for (i, item) in items.iter().enumerate() {
                    if i > 0 {
                        out.push(',');
                    }
                    write_value(out, item);
                }
                out.push(']');
            }
            Value::Map(entries) => {
                out.push('{');
                for (i, (key, value)) in entries.iter().enumerate() {
                    if i > 0 {
                        out.push(',');
                    }
                    write_value(out, key);
                    out.push(':');
                    write_value(out, value);
                }
                out.push('}');
            }
        }
    }

    fn write_float(out: &mut String, f: f64) {
        match serde_json::to_string(&f) {
            Ok(s) => out.push_str(&s),
            Err(_) => out.push_str("null"),
        }
    }

    fn write_str(out: &mut String, bytes: &[u8]) {
        let s = String::from_utf8_lossy(bytes);
        match serde_json::to_string(&*s) {
            Ok(json) => out.push_str(&json),
            Err(_) => out.push_str("\"\""),
        }
    }

    pub(super) fn decode_response(command: &str, response: &[u8]) -> AppSecVerdict {
        match decode_outcome(command, response) {
            Ok(verdict) => verdict,
            Err(error) => AppSecVerdict::Error(error),
        }
    }

    fn string(value: &Value) -> String {
        match value {
            Value::String(s) => s.clone(),
            other => other.to_string(),
        }
    }

    // Scripted responses mirror the former helper replies:
    // `["<command>", [actions, triggers, force_keep, settings, meta, metrics]]` with actions as
    // `[["<action>", {<parameters>}]...]`, `["error", ...]`, plus `["unavailable", []]` for no
    // helper client.
    fn decode_outcome(command: &str, response: &[u8]) -> Result<AppSecVerdict, String> {
        let value: Value = serde_json::from_slice(response)
            .map_err(|e| format!("malformed mock response: {e}"))?;
        let first = value
            .as_array()
            .ok_or("mock response is not a list of responses")?
            .first()
            .ok_or("empty mock response")?;
        let kind = first
            .get(0)
            .and_then(Value::as_str)
            .ok_or("mock response without type")?;
        match kind {
            "error" => return Err("the helper responded with an error".to_owned()),
            "unavailable" => return Ok(AppSecVerdict::Unavailable),
            _ if kind != command => {
                return Err(format!("unexpected response {kind:?} to {command:?}"))
            }
            _ => {}
        }
        let malformed = || format!("malformed mock response to {command:?}");
        let args = first
            .get(1)
            .and_then(Value::as_array)
            .ok_or_else(malformed)?;
        let mut outcome = AppSecOutcome::default();
        if let Some(actions) = args.first() {
            for action in actions.as_array().ok_or_else(malformed)? {
                let name = action
                    .get(0)
                    .and_then(Value::as_str)
                    .ok_or_else(malformed)?;
                if name.is_empty() || name == "ok" {
                    continue;
                }
                let parameters = match action.get(1) {
                    None | Some(Value::Null) => vec![],
                    Some(Value::Array(a)) if a.is_empty() => vec![],
                    Some(Value::Object(params)) => {
                        params.iter().map(|(k, v)| (k.clone(), string(v))).collect()
                    }
                    Some(_) => return Err(malformed()),
                };
                outcome
                    .actions
                    .push(datadog_sidecar::appsec::protocol::AppSecAction {
                        action: name.to_owned(),
                        parameters,
                    });
            }
        }
        // Scripts pass an empty ArrayObject, i.e. {}, for no triggers.
        match args.get(1) {
            None | Some(Value::Null) => {}
            Some(Value::Object(o)) if o.is_empty() => {}
            Some(Value::Array(triggers)) => {
                outcome.triggers = triggers.iter().map(string).collect()
            }
            Some(_) => return Err(malformed()),
        }
        outcome.force_keep = args.get(2).and_then(Value::as_bool).unwrap_or(false);
        if let Some(meta) = args.get(4).and_then(Value::as_object) {
            outcome.meta = meta.iter().map(|(k, v)| (k.clone(), string(v))).collect();
        }
        if let Some(metrics) = args.get(5).and_then(Value::as_object) {
            outcome.metrics = metrics
                .iter()
                .filter_map(|(k, v)| Some((k.clone(), v.as_f64()?)))
                .collect();
        }
        Ok(AppSecVerdict::Ok(outcome))
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn verdict_view_borrows_the_outcome() {
        let result = AppSecVerdictResult::from_verdict(AppSecVerdict::Ok(AppSecOutcome {
            actions: vec![datadog_sidecar::appsec::protocol::AppSecAction {
                action: "block".to_owned(),
                parameters: vec![("status_code".to_owned(), "403".to_owned())],
            }],
            triggers: vec!["{}".to_owned()],
            force_keep: true,
            meta: vec![("_dd.appsec.event_rules.version".to_owned(), "1".to_owned())],
            metrics: vec![("_dd.appsec.waf.duration".to_owned(), 1.5)],
        }));
        let view = ddog_appsec_verdict_view(&result);
        assert_eq!(view.kind, AppSecVerdictKind::Ok);
        assert!(view.force_keep);
        let actions = unsafe { std::slice::from_raw_parts(view.actions, view.actions_len) };
        assert_eq!(actions[0].action.as_bytes(), b"block");
        let params =
            unsafe { std::slice::from_raw_parts(actions[0].parameters, actions[0].parameters_len) };
        assert_eq!(params[0].value.as_bytes(), b"403");
        let metrics = unsafe { std::slice::from_raw_parts(view.metrics, view.metrics_len) };
        assert_eq!(metrics[0].value, 1.5);
    }

    #[test]
    fn mock_json_matches_the_former_command_shapes() {
        // {"a": "x\xff", "a": 1.0}, as the extension writes duplicate keys and non-UTF-8 strings
        let mut map = vec![0x82, 0xa1, b'a', 0xa2, b'x', 0xff, 0xa1, b'a', 0xcb];
        map.extend_from_slice(&1.0f64.to_bits().to_be_bytes());
        let (command, json) = testing::encode_request(&AppSecRequest::Exec {
            addresses: AppSecAddresses(AppSecBytes(map)),
            rasp: Some(AppSecRaspRule {
                rule: "lfi".to_owned(),
                variant: None,
            }),
        })
        .unwrap();
        assert_eq!(command, "request_exec");
        assert_eq!(
            json,
            r#"["request_exec",[{"a":"x�","a":1.0},{"rasp_rule":"lfi"}]]"#
        );

        // [1, -1, 2^32, true, nil, bin "b", {}]
        let mut list = vec![0x97, 0x01, 0xff, 0xcf];
        list.extend_from_slice(&(1u64 << 32).to_be_bytes());
        list.extend_from_slice(&[0xc3, 0xc0, 0xc4, 1, b'b', 0x80]);
        let (_, json) = testing::encode_request(&AppSecRequest::Shutdown {
            addresses: AppSecAddresses(AppSecBytes(list)),
            api_sec_samp_key: 7,
            input_truncated: true,
        })
        .unwrap();
        assert_eq!(
            json,
            r#"["request_shutdown",[[1,-1,4294967296,true,null,"b",{}],7,true]]"#
        );

        assert!(testing::encode_request(&AppSecRequest::Init {
            addresses: AppSecAddresses(AppSecBytes(vec![0x81])),
            keep_bound_application: false,
        })
        .is_none());
    }

    #[test]
    fn mock_responses_become_verdicts() {
        let verdict = testing::decode_response(
            "request_shutdown",
            br#"[["request_shutdown",[[["block",{"status_code":"403","type":"json"}],["ok",{}]],["{\"a\":1}"],true,{},{"m":"v"},{"x":2}]]]"#,
        );
        let AppSecVerdict::Ok(outcome) = verdict else {
            panic!("expected an outcome: {verdict:?}");
        };
        assert_eq!(outcome.actions.len(), 1);
        assert_eq!(outcome.actions[0].action, "block");
        assert_eq!(outcome.triggers, vec![r#"{"a":1}"#.to_owned()]);
        assert!(outcome.force_keep);
        assert_eq!(outcome.meta, vec![("m".to_owned(), "v".to_owned())]);
        assert_eq!(outcome.metrics, vec![("x".to_owned(), 2.0)]);

        assert!(matches!(
            testing::decode_response("request_init", br#"[["error",[]]]"#),
            AppSecVerdict::Error(_)
        ));
        assert!(matches!(
            testing::decode_response("request_init", br#"[["client_init",[]]]"#),
            AppSecVerdict::Error(_)
        ));
        assert_eq!(
            testing::decode_response("request_init", br#"[["unavailable",[]]]"#),
            AppSecVerdict::Unavailable
        );
        assert_eq!(
            testing::decode_response("request_init", br#"[["request_init",[[["ok",[]]],{},{}]]]"#),
            AppSecVerdict::Ok(Default::default())
        );
        // [{"foo": "ok"}] is not a list of actions
        assert!(matches!(
            testing::decode_response("request_init", br#"[["request_init",[{"foo":"ok"}]]]"#),
            AppSecVerdict::Error(_)
        ));
    }
}
