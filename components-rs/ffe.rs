use datadog_ffe::rules_based::{
    self as ffe, AssignmentReason, AssignmentValue, Attribute, Configuration, EvaluationContext,
    EvaluationError, ExpectedFlagType, Str, UniversalFlagConfig,
};
use std::collections::HashMap;
use std::ffi::{c_char, CStr, CString};
use std::sync::{Arc, Mutex};

/// Holds both the FFE configuration and a "changed" flag atomically behind a
/// single Mutex. This avoids the race where another thread could observe
/// `config` updated but `changed` still false (or vice-versa).
///
/// A `RwLock` would be more appropriate here (many readers via `ddog_ffe_evaluate`,
/// rare writer via `store_config`), but PHP is single-threaded per process so
/// contention is not a practical concern. Keeping a Mutex for simplicity.
struct FfeState {
    config: Option<Configuration>,
    changed: bool,
}

lazy_static::lazy_static! {
    static ref FFE_STATE: Mutex<FfeState> = Mutex::new(FfeState {
        config: None,
        changed: false,
    });
}

/// Called by remote_config when a new FFE configuration arrives via RC.
pub fn store_config(config: Configuration) {
    if let Ok(mut state) = FFE_STATE.lock() {
        state.config = Some(config);
        state.changed = true;
    }
}

/// Called by remote_config when an FFE configuration is removed.
pub fn clear_config() {
    if let Ok(mut state) = FFE_STATE.lock() {
        state.config = None;
        state.changed = true;
    }
}

/// Load a UFC JSON config string directly into the FFE engine.
/// Used by tests to load config without Remote Config.
#[no_mangle]
pub extern "C" fn ddog_ffe_load_config(json: *const c_char) -> bool {
    if json.is_null() {
        return false;
    }
    let json_str = match unsafe { CStr::from_ptr(json) }.to_str() {
        Ok(s) => s,
        Err(_) => return false,
    };
    match UniversalFlagConfig::from_json(json_str.as_bytes().to_vec()) {
        Ok(ufc) => {
            store_config(Configuration::from_server_response(ufc));
            true
        }
        Err(_) => false,
    }
}

/// Check if FFE configuration is loaded.
#[no_mangle]
pub extern "C" fn ddog_ffe_has_config() -> bool {
    FFE_STATE.lock().map(|s| s.config.is_some()).unwrap_or(false)
}

/// Check if FFE config has changed since last check.
/// Resets the changed flag after reading.
#[no_mangle]
pub extern "C" fn ddog_ffe_config_changed() -> bool {
    if let Ok(mut state) = FFE_STATE.lock() {
        let was_changed = state.changed;
        state.changed = false;
        was_changed
    } else {
        false
    }
}

// Reason codes returned to PHP via ddog_ffe_result_reason().
// Must match Provider::$REASON_MAP in src/DDTrace/FeatureFlags/Provider.php.
const REASON_STATIC: i32 = 0;
const REASON_DEFAULT: i32 = 1;
const REASON_TARGETING_MATCH: i32 = 2;
const REASON_SPLIT: i32 = 3;
const REASON_DISABLED: i32 = 4;
const REASON_ERROR: i32 = 5;

// Error codes returned to PHP via ddog_ffe_result_error_code().
// 0 means no error.
const ERROR_NONE: i32 = 0;
const ERROR_TYPE_MISMATCH: i32 = 1;
const ERROR_CONFIG_PARSE: i32 = 2;
const ERROR_FLAG_UNRECOGNIZED: i32 = 3;
const ERROR_CONFIG_MISSING: i32 = 6;
const ERROR_GENERAL: i32 = 7;

// Attribute value types passed from C (matches FfeAttribute.value_type).
const ATTR_TYPE_STRING: i32 = 0;
const ATTR_TYPE_NUMBER: i32 = 1;
const ATTR_TYPE_BOOL: i32 = 2;

// Expected flag type IDs passed from C (matches Provider::$TYPE_MAP).
const TYPE_STRING: i32 = 0;
const TYPE_INTEGER: i32 = 1;
const TYPE_FLOAT: i32 = 2;
const TYPE_BOOLEAN: i32 = 3;
const TYPE_OBJECT: i32 = 4;

/// Opaque handle for FFE evaluation results returned to C/PHP.
pub struct FfeResult {
    pub value_json: CString,
    pub variant: Option<CString>,
    pub allocation_key: Option<CString>,
    pub reason: i32,
    pub error_code: i32,
    pub do_log: bool,
}

/// A single attribute passed from C/PHP for building an EvaluationContext.
#[repr(C)]
pub struct FfeAttribute {
    pub key: *const c_char,
    /// 0 = string, 1 = number, 2 = bool
    pub value_type: i32,
    pub string_value: *const c_char,
    pub number_value: f64,
    pub bool_value: bool,
}

/// Evaluate a feature flag using the stored Configuration.
///
/// Accepts structured attributes from C instead of a JSON blob.
/// `targeting_key` may be null (no targeting key).
/// `attributes` / `attributes_count` describe an array of `FfeAttribute`.
/// Returns null if no config is loaded.
#[no_mangle]
pub extern "C" fn ddog_ffe_evaluate(
    flag_key: *const c_char,
    expected_type: i32,
    targeting_key: *const c_char,
    attributes: *const FfeAttribute,
    attributes_count: usize,
) -> *mut FfeResult {
    if flag_key.is_null() {
        return std::ptr::null_mut();
    }
    let flag_key = match unsafe { CStr::from_ptr(flag_key) }.to_str() {
        Ok(s) => s,
        Err(_) => return std::ptr::null_mut(),
    };

    let expected_type = match expected_type {
        TYPE_STRING => ExpectedFlagType::String,
        TYPE_INTEGER => ExpectedFlagType::Integer,
        TYPE_FLOAT => ExpectedFlagType::Float,
        TYPE_BOOLEAN => ExpectedFlagType::Boolean,
        TYPE_OBJECT => ExpectedFlagType::Object,
        _ => return std::ptr::null_mut(),
    };

    // Build targeting key
    let tk = if targeting_key.is_null() {
        None
    } else {
        match unsafe { CStr::from_ptr(targeting_key) }.to_str() {
            Ok(s) if !s.is_empty() => Some(Str::from(s)),
            _ => None,
        }
    };

    // Build attributes map from the C array
    let mut attrs = HashMap::new();
    if !attributes.is_null() && attributes_count > 0 {
        let slice = unsafe { std::slice::from_raw_parts(attributes, attributes_count) };
        for attr in slice {
            if attr.key.is_null() {
                continue;
            }
            let key = match unsafe { CStr::from_ptr(attr.key) }.to_str() {
                Ok(s) => s,
                Err(_) => continue,
            };
            let value = match attr.value_type {
                ATTR_TYPE_STRING => {
                    if attr.string_value.is_null() {
                        continue;
                    }
                    match unsafe { CStr::from_ptr(attr.string_value) }.to_str() {
                        Ok(s) => Attribute::from(s),
                        Err(_) => continue,
                    }
                }
                ATTR_TYPE_NUMBER => Attribute::from(attr.number_value),
                ATTR_TYPE_BOOL => Attribute::from(attr.bool_value),
                _ => continue,
            };
            attrs.insert(Str::from(key), value);
        }
    }

    let context = EvaluationContext::new(tk, Arc::new(attrs));

    let state = match FFE_STATE.lock() {
        Ok(s) => s,
        Err(_) => return std::ptr::null_mut(),
    };

    let assignment = ffe::get_assignment(
        state.config.as_ref(),
        flag_key,
        &context,
        expected_type,
        ffe::now(),
    );

    let result = match assignment {
        Ok(a) => FfeResult {
            value_json: CString::new(assignment_value_to_json(&a.value)).unwrap_or_default(),
            variant: Some(CString::new(a.variation_key.as_str()).unwrap_or_default()),
            allocation_key: Some(CString::new(a.allocation_key.as_str()).unwrap_or_default()),
            reason: match a.reason {
                AssignmentReason::Static => REASON_STATIC,
                AssignmentReason::TargetingMatch => REASON_TARGETING_MATCH,
                AssignmentReason::Split => REASON_SPLIT,
            },
            error_code: ERROR_NONE,
            do_log: a.do_log,
        },
        Err(err) => {
            let (error_code, reason) = match &err {
                EvaluationError::TypeMismatch { .. } => (ERROR_TYPE_MISMATCH, REASON_ERROR),
                EvaluationError::ConfigurationParseError => (ERROR_CONFIG_PARSE, REASON_ERROR),
                EvaluationError::ConfigurationMissing => (ERROR_CONFIG_MISSING, REASON_ERROR),
                EvaluationError::FlagUnrecognizedOrDisabled => (ERROR_FLAG_UNRECOGNIZED, REASON_DEFAULT),
                EvaluationError::FlagDisabled => (ERROR_NONE, REASON_DISABLED),
                EvaluationError::DefaultAllocationNull => (ERROR_NONE, REASON_DEFAULT),
                _ => (ERROR_GENERAL, REASON_ERROR),
            };
            FfeResult {
                value_json: CString::new("null").unwrap_or_default(),
                variant: None,
                allocation_key: None,
                reason,
                error_code,
                do_log: false,
            }
        }
    };

    Box::into_raw(Box::new(result))
}

#[no_mangle]
pub extern "C" fn ddog_ffe_result_value(r: *const FfeResult) -> *const c_char {
    if r.is_null() {
        return std::ptr::null();
    }
    unsafe { &*r }.value_json.as_ptr()
}

#[no_mangle]
pub extern "C" fn ddog_ffe_result_variant(r: *const FfeResult) -> *const c_char {
    if r.is_null() {
        return std::ptr::null();
    }
    unsafe { &*r }
        .variant
        .as_ref()
        .map(|s| s.as_ptr())
        .unwrap_or(std::ptr::null())
}

#[no_mangle]
pub extern "C" fn ddog_ffe_result_allocation_key(r: *const FfeResult) -> *const c_char {
    if r.is_null() {
        return std::ptr::null();
    }
    unsafe { &*r }
        .allocation_key
        .as_ref()
        .map(|s| s.as_ptr())
        .unwrap_or(std::ptr::null())
}

#[no_mangle]
pub extern "C" fn ddog_ffe_result_reason(r: *const FfeResult) -> i32 {
    if r.is_null() {
        return -1;
    }
    unsafe { &*r }.reason
}

#[no_mangle]
pub extern "C" fn ddog_ffe_result_error_code(r: *const FfeResult) -> i32 {
    if r.is_null() {
        return -1;
    }
    unsafe { &*r }.error_code
}

#[no_mangle]
pub extern "C" fn ddog_ffe_result_do_log(r: *const FfeResult) -> bool {
    if r.is_null() {
        return false;
    }
    unsafe { &*r }.do_log
}

#[no_mangle]
pub unsafe extern "C" fn ddog_ffe_free_result(r: *mut FfeResult) {
    if !r.is_null() {
        drop(Box::from_raw(r));
    }
}

fn assignment_value_to_json(value: &AssignmentValue) -> String {
    match value {
        AssignmentValue::String(s) => serde_json::to_string(s.as_str()).unwrap_or_default(),
        AssignmentValue::Integer(i) => i.to_string(),
        AssignmentValue::Float(f) => serde_json::Number::from_f64(*f)
            .map(|n| n.to_string())
            .unwrap_or_else(|| f.to_string()),
        AssignmentValue::Boolean(b) => b.to_string(),
        AssignmentValue::Json { raw, .. } => raw.get().to_string(),
    }
}
