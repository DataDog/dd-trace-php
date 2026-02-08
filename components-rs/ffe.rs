use datadog_ffe::rules_based::{
    self as ffe, Attribute, AssignmentReason, AssignmentValue, Configuration, EvaluationContext,
    EvaluationError, Str, UniversalFlagConfig,
};
use std::collections::HashMap;
use std::ffi::{c_char, CStr, CString};
use std::ptr;
use std::sync::{Arc, Mutex};

lazy_static::lazy_static! {
    static ref FFE_CONFIG: Mutex<Option<Configuration>> = Mutex::new(None);
}

/// Opaque handle for FFE resolution details returned to C/PHP.
pub struct FfeResolutionDetails {
    pub value_json: CString,
    pub variant: Option<CString>,
    pub allocation_key: Option<CString>,
    pub reason: i32,       // 0=Static, 1=Default, 2=TargetingMatch, 3=Split, 4=Disabled, 5=Error
    pub error_code: i32,   // 0=None, 1=TypeMismatch, 2=ParseError, 3=FlagNotFound, 4=TargetingKeyMissing, 5=InvalidContext, 6=ProviderNotReady, 7=General
    pub error_message: Option<CString>,
    pub do_log: bool,
}

/// Load FFE configuration from raw JSON bytes.
/// Returns true on success, false on failure.
#[no_mangle]
pub extern "C" fn ddog_ffe_load_config(data: *const u8, len: usize) -> bool {
    if data.is_null() || len == 0 {
        return false;
    }

    let bytes = unsafe { std::slice::from_raw_parts(data, len) };

    let ufc = match UniversalFlagConfig::from_json(bytes.to_vec()) {
        Ok(ufc) => ufc,
        Err(e) => {
            tracing::debug!("Failed to parse FFE config: {e}");
            return false;
        }
    };

    let config = Configuration::from_server_response(ufc);

    if let Ok(mut guard) = FFE_CONFIG.lock() {
        *guard = Some(config);
        tracing::debug!("FFE config loaded successfully, {} bytes", len);
        true
    } else {
        false
    }
}

/// Clear the FFE configuration.
#[no_mangle]
pub extern "C" fn ddog_ffe_clear_config() {
    if let Ok(mut guard) = FFE_CONFIG.lock() {
        *guard = None;
    }
}

/// Check if FFE config is loaded.
#[no_mangle]
pub extern "C" fn ddog_ffe_has_config() -> bool {
    FFE_CONFIG
        .lock()
        .map(|g| g.is_some())
        .unwrap_or(false)
}

/// Evaluate a feature flag.
///
/// # Arguments
/// * `flag_key` - null-terminated flag key string
/// * `expected_type` - 0=String, 1=Integer, 2=Float, 3=Boolean, 4=Object
/// * `context_json` - JSON-encoded evaluation context bytes
/// * `context_json_len` - length of context_json
///
/// Returns a pointer to FfeResolutionDetails (caller must free with ddog_ffe_free_result).
/// Returns null if evaluation cannot be performed.
#[no_mangle]
pub extern "C" fn ddog_ffe_evaluate(
    flag_key: *const c_char,
    expected_type: i32,
    context_json: *const u8,
    context_json_len: usize,
) -> *mut FfeResolutionDetails {
    let flag_key = match unsafe { CStr::from_ptr(flag_key) }.to_str() {
        Ok(s) => s,
        Err(_) => return ptr::null_mut(),
    };

    let expected_type = match expected_type {
        0 => ffe::ExpectedFlagType::String,
        1 => ffe::ExpectedFlagType::Integer,
        2 => ffe::ExpectedFlagType::Float,
        3 => ffe::ExpectedFlagType::Boolean,
        4 => ffe::ExpectedFlagType::Object,
        _ => return ptr::null_mut(),
    };

    // Parse context from JSON
    let context = if !context_json.is_null() && context_json_len > 0 {
        let bytes = unsafe { std::slice::from_raw_parts(context_json, context_json_len) };
        parse_evaluation_context(bytes)
    } else {
        EvaluationContext::new(None, Arc::new(HashMap::new()))
    };

    let guard = match FFE_CONFIG.lock() {
        Ok(g) => g,
        Err(_) => return ptr::null_mut(),
    };

    let config_ref = guard.as_ref();

    let assignment = ffe::get_assignment(
        config_ref,
        flag_key,
        &context,
        expected_type,
        ffe::now(),
    );

    let details = match assignment {
        Ok(assignment) => {
            let value_json = assignment_value_to_json(&assignment.value);
            FfeResolutionDetails {
                value_json: CString::new(value_json).unwrap_or_default(),
                variant: Some(
                    CString::new(assignment.variation_key.as_str())
                        .unwrap_or_default(),
                ),
                allocation_key: Some(
                    CString::new(assignment.allocation_key.as_str())
                        .unwrap_or_default(),
                ),
                reason: match assignment.reason {
                    AssignmentReason::Static => 0,
                    AssignmentReason::TargetingMatch => 2,
                    AssignmentReason::Split => 3,
                },
                error_code: 0,
                error_message: None,
                do_log: assignment.do_log,
            }
        }
        Err(err) => {
            let (error_code, reason, error_message) = match &err {
                EvaluationError::TypeMismatch { expected, found } => (
                    1,
                    5,
                    format!("type mismatch, expected={expected:?}, found={found:?}"),
                ),
                EvaluationError::ConfigurationParseError => {
                    (2, 5, "configuration error".to_string())
                }
                EvaluationError::ConfigurationMissing => {
                    (6, 5, "configuration is missing".to_string())
                }
                EvaluationError::FlagUnrecognizedOrDisabled => {
                    (3, 1, "flag is unrecognized or disabled".to_string())
                }
                EvaluationError::FlagDisabled => (0, 4, String::new()),
                EvaluationError::DefaultAllocationNull => (0, 1, String::new()),
                _ => (7, 5, err.to_string()),
            };

            FfeResolutionDetails {
                value_json: CString::new("null").unwrap_or_default(),
                variant: None,
                allocation_key: None,
                reason,
                error_code,
                error_message: if error_message.is_empty() {
                    None
                } else {
                    Some(CString::new(error_message).unwrap_or_default())
                },
                do_log: false,
            }
        }
    };

    Box::into_raw(Box::new(details))
}

/// Get the JSON-encoded value from the resolution details.
#[no_mangle]
pub extern "C" fn ddog_ffe_result_value(details: *const FfeResolutionDetails) -> *const c_char {
    if details.is_null() {
        return ptr::null();
    }
    unsafe { &*details }.value_json.as_ptr()
}

/// Get the variant key from the resolution details.
#[no_mangle]
pub extern "C" fn ddog_ffe_result_variant(details: *const FfeResolutionDetails) -> *const c_char {
    if details.is_null() {
        return ptr::null();
    }
    unsafe { &*details }
        .variant
        .as_ref()
        .map(|s| s.as_ptr())
        .unwrap_or(ptr::null())
}

/// Get the allocation key from the resolution details.
#[no_mangle]
pub extern "C" fn ddog_ffe_result_allocation_key(
    details: *const FfeResolutionDetails,
) -> *const c_char {
    if details.is_null() {
        return ptr::null();
    }
    unsafe { &*details }
        .allocation_key
        .as_ref()
        .map(|s| s.as_ptr())
        .unwrap_or(ptr::null())
}

/// Get the reason code from the resolution details.
#[no_mangle]
pub extern "C" fn ddog_ffe_result_reason(details: *const FfeResolutionDetails) -> i32 {
    if details.is_null() {
        return -1;
    }
    unsafe { &*details }.reason
}

/// Get the error code from the resolution details.
#[no_mangle]
pub extern "C" fn ddog_ffe_result_error_code(details: *const FfeResolutionDetails) -> i32 {
    if details.is_null() {
        return -1;
    }
    unsafe { &*details }.error_code
}

/// Get the error message from the resolution details.
#[no_mangle]
pub extern "C" fn ddog_ffe_result_error_message(
    details: *const FfeResolutionDetails,
) -> *const c_char {
    if details.is_null() {
        return ptr::null();
    }
    unsafe { &*details }
        .error_message
        .as_ref()
        .map(|s| s.as_ptr())
        .unwrap_or(ptr::null())
}

/// Get the do_log flag from the resolution details.
#[no_mangle]
pub extern "C" fn ddog_ffe_result_do_log(details: *const FfeResolutionDetails) -> bool {
    if details.is_null() {
        return false;
    }
    unsafe { &*details }.do_log
}

/// Free the resolution details allocated by ddog_ffe_evaluate.
#[no_mangle]
pub unsafe extern "C" fn ddog_ffe_free_result(details: *mut FfeResolutionDetails) {
    if !details.is_null() {
        drop(Box::from_raw(details));
    }
}

fn assignment_value_to_json(value: &AssignmentValue) -> String {
    match value {
        AssignmentValue::String(s) => serde_json::to_string(s.as_str()).unwrap_or_default(),
        AssignmentValue::Integer(i) => i.to_string(),
        AssignmentValue::Float(f) => {
            serde_json::Number::from_f64(*f)
                .map(|n| n.to_string())
                .unwrap_or_else(|| f.to_string())
        }
        AssignmentValue::Boolean(b) => b.to_string(),
        AssignmentValue::Json { raw, .. } => raw.get().to_string(),
    }
}

/// Parse a JSON evaluation context into an EvaluationContext.
/// Expected format: {"targeting_key": "...", "attributes": {"key": value, ...}}
fn parse_evaluation_context(bytes: &[u8]) -> EvaluationContext {
    let parsed: serde_json::Value = match serde_json::from_slice(bytes) {
        Ok(v) => v,
        Err(_) => return EvaluationContext::new(None, Arc::new(HashMap::new())),
    };

    let targeting_key = parsed
        .get("targeting_key")
        .and_then(|v| v.as_str())
        .map(Str::from);

    let mut attributes = HashMap::new();
    if let Some(attrs) = parsed.get("attributes").and_then(|v| v.as_object()) {
        for (k, v) in attrs {
            let attr = match v {
                serde_json::Value::String(s) => Attribute::from(s.as_str()),
                serde_json::Value::Number(n) => {
                    if let Some(f) = n.as_f64() {
                        Attribute::from(f)
                    } else {
                        continue;
                    }
                }
                serde_json::Value::Bool(b) => Attribute::from(*b),
                serde_json::Value::Null => continue,
                _ => continue,
            };
            attributes.insert(Str::from(k.as_str()), attr);
        }
    }

    EvaluationContext::new(targeting_key, Arc::new(attributes))
}
