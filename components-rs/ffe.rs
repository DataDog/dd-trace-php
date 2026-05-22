use datadog_ffe::rules_based::{
    self as ffe, AssignmentReason, AssignmentValue, Attribute, Configuration, EvaluationContext,
    EvaluationError, ExpectedFlagType, Str, UniversalFlagConfig,
};
use std::cell::RefCell;
use std::collections::HashMap;
use std::ffi::{c_char, CStr, CString};
use std::sync::Arc;

struct FfeState {
    config: Option<Configuration>,
    version: u64,
}

thread_local! {
    static FFE_STATE: RefCell<FfeState> = const { RefCell::new(FfeState {
        config: None,
        version: 0,
    }) };
}

pub fn store_config(config: Configuration) {
    FFE_STATE.with(|state| {
        let mut state = state.borrow_mut();
        state.config = Some(config);
        state.version = state.version.wrapping_add(1);
    });
}

pub fn clear_config() {
    FFE_STATE.with(|state| {
        let mut state = state.borrow_mut();
        state.config = None;
        state.version = state.version.wrapping_add(1);
    });
}

#[no_mangle]
pub extern "C" fn ddog_ffe_load_config(json: *const c_char) -> bool {
    if json.is_null() {
        return false;
    }

    let json = match unsafe { CStr::from_ptr(json) }.to_str() {
        Ok(json) => json,
        Err(_) => return false,
    };

    match UniversalFlagConfig::from_json(json.as_bytes().to_vec()) {
        Ok(ufc) => {
            store_config(Configuration::from_server_response(ufc));
            true
        }
        Err(_) => false,
    }
}

#[no_mangle]
pub extern "C" fn ddog_ffe_has_config() -> bool {
    FFE_STATE.with(|state| state.borrow().config.is_some())
}

#[no_mangle]
pub extern "C" fn ddog_ffe_config_version() -> u64 {
    FFE_STATE.with(|state| state.borrow().version)
}

const REASON_STATIC: i32 = 0;
const REASON_DEFAULT: i32 = 1;
const REASON_TARGETING_MATCH: i32 = 2;
const REASON_SPLIT: i32 = 3;
const REASON_DISABLED: i32 = 4;
const REASON_ERROR: i32 = 5;

const ERROR_NONE: i32 = 0;
const ERROR_TYPE_MISMATCH: i32 = 1;
const ERROR_CONFIG_PARSE: i32 = 2;
const ERROR_FLAG_UNRECOGNIZED: i32 = 3;
const ERROR_CONFIG_MISSING: i32 = 6;
const ERROR_GENERAL: i32 = 7;

const ATTR_TYPE_STRING: i32 = 0;
const ATTR_TYPE_NUMBER: i32 = 1;
const ATTR_TYPE_BOOL: i32 = 2;

const TYPE_STRING: i32 = 0;
const TYPE_INTEGER: i32 = 1;
const TYPE_FLOAT: i32 = 2;
const TYPE_BOOLEAN: i32 = 3;
const TYPE_OBJECT: i32 = 4;

pub struct FfeResult {
    pub value_json: CString,
    pub variant: Option<CString>,
    pub allocation_key: Option<CString>,
    pub reason: i32,
    pub error_code: i32,
    pub do_log: bool,
}

#[repr(C)]
pub struct FfeAttribute {
    pub key: *const c_char,
    pub value_type: i32,
    pub string_value: *const c_char,
    pub number_value: f64,
    pub bool_value: bool,
}

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
        Ok(flag_key) => flag_key,
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

    let targeting_key = if targeting_key.is_null() {
        None
    } else {
        match unsafe { CStr::from_ptr(targeting_key) }.to_str() {
            Ok(targeting_key) if !targeting_key.is_empty() => Some(Str::from(targeting_key)),
            _ => None,
        }
    };

    let attributes = parse_attributes(attributes, attributes_count);
    let context = EvaluationContext::new(targeting_key, Arc::new(attributes));

    FFE_STATE.with(|state| {
        let state = state.borrow();
        let assignment = ffe::get_assignment(
            state.config.as_ref(),
            flag_key,
            &context,
            expected_type,
            ffe::now(),
        );

        Box::into_raw(Box::new(result_from_assignment(assignment)))
    })
}

#[no_mangle]
pub extern "C" fn ddog_ffe_result_value(result: *const FfeResult) -> *const c_char {
    if result.is_null() {
        return std::ptr::null();
    }

    unsafe { &*result }.value_json.as_ptr()
}

#[no_mangle]
pub extern "C" fn ddog_ffe_result_variant(result: *const FfeResult) -> *const c_char {
    if result.is_null() {
        return std::ptr::null();
    }

    unsafe { &*result }
        .variant
        .as_ref()
        .map(|value| value.as_ptr())
        .unwrap_or(std::ptr::null())
}

#[no_mangle]
pub extern "C" fn ddog_ffe_result_allocation_key(result: *const FfeResult) -> *const c_char {
    if result.is_null() {
        return std::ptr::null();
    }

    unsafe { &*result }
        .allocation_key
        .as_ref()
        .map(|value| value.as_ptr())
        .unwrap_or(std::ptr::null())
}

#[no_mangle]
pub extern "C" fn ddog_ffe_result_reason(result: *const FfeResult) -> i32 {
    if result.is_null() {
        return REASON_ERROR;
    }

    unsafe { &*result }.reason
}

#[no_mangle]
pub extern "C" fn ddog_ffe_result_error_code(result: *const FfeResult) -> i32 {
    if result.is_null() {
        return ERROR_GENERAL;
    }

    unsafe { &*result }.error_code
}

#[no_mangle]
pub extern "C" fn ddog_ffe_result_do_log(result: *const FfeResult) -> bool {
    if result.is_null() {
        return false;
    }

    unsafe { &*result }.do_log
}

#[no_mangle]
pub unsafe extern "C" fn ddog_ffe_free_result(result: *mut FfeResult) {
    if !result.is_null() {
        drop(Box::from_raw(result));
    }
}

fn parse_attributes(
    attributes: *const FfeAttribute,
    attributes_count: usize,
) -> HashMap<Str, Attribute> {
    let mut parsed = HashMap::new();

    if attributes.is_null() || attributes_count == 0 {
        return parsed;
    }

    let attributes = unsafe { std::slice::from_raw_parts(attributes, attributes_count) };
    for attribute in attributes {
        if attribute.key.is_null() {
            continue;
        }

        let key = match unsafe { CStr::from_ptr(attribute.key) }.to_str() {
            Ok(key) => key,
            Err(_) => continue,
        };

        let value = match attribute.value_type {
            ATTR_TYPE_STRING => {
                if attribute.string_value.is_null() {
                    continue;
                }

                match unsafe { CStr::from_ptr(attribute.string_value) }.to_str() {
                    Ok(value) => Attribute::from(value),
                    Err(_) => continue,
                }
            }
            ATTR_TYPE_NUMBER => Attribute::from(attribute.number_value),
            ATTR_TYPE_BOOL => Attribute::from(attribute.bool_value),
            _ => continue,
        };

        parsed.insert(Str::from(key), value);
    }

    parsed
}

fn result_from_assignment(assignment: Result<ffe::Assignment, EvaluationError>) -> FfeResult {
    match assignment {
        Ok(assignment) => FfeResult {
            value_json: string_to_cstring(assignment_value_to_json(&assignment.value)),
            variant: Some(string_to_cstring(
                assignment.variation_key.as_str().to_string(),
            )),
            allocation_key: Some(string_to_cstring(
                assignment.allocation_key.as_str().to_string(),
            )),
            reason: match assignment.reason {
                AssignmentReason::Static => REASON_STATIC,
                AssignmentReason::TargetingMatch => REASON_TARGETING_MATCH,
                AssignmentReason::Split => REASON_SPLIT,
            },
            error_code: ERROR_NONE,
            do_log: assignment.do_log,
        },
        Err(error) => {
            let (error_code, reason) = match &error {
                EvaluationError::TypeMismatch { .. } => (ERROR_TYPE_MISMATCH, REASON_ERROR),
                EvaluationError::ConfigurationParseError => (ERROR_CONFIG_PARSE, REASON_ERROR),
                EvaluationError::ConfigurationMissing => (ERROR_CONFIG_MISSING, REASON_ERROR),
                EvaluationError::FlagUnrecognizedOrDisabled => {
                    (ERROR_FLAG_UNRECOGNIZED, REASON_DEFAULT)
                }
                EvaluationError::FlagDisabled => (ERROR_NONE, REASON_DISABLED),
                EvaluationError::DefaultAllocationNull => (ERROR_NONE, REASON_DEFAULT),
                _ => (ERROR_GENERAL, REASON_ERROR),
            };

            FfeResult {
                value_json: string_to_cstring("null".to_string()),
                variant: None,
                allocation_key: None,
                reason,
                error_code,
                do_log: false,
            }
        }
    }
}

fn assignment_value_to_json(value: &AssignmentValue) -> String {
    match value {
        AssignmentValue::String(value) => serde_json::to_string(value.as_str()).unwrap_or_default(),
        AssignmentValue::Integer(value) => value.to_string(),
        AssignmentValue::Float(value) => serde_json::Number::from_f64(*value)
            .map(|value| value.to_string())
            .unwrap_or_else(|| value.to_string()),
        AssignmentValue::Boolean(value) => value.to_string(),
        AssignmentValue::Json { raw, .. } => raw.get().to_string(),
    }
}

fn string_to_cstring(value: String) -> CString {
    CString::new(value).unwrap_or_default()
}

#[cfg(test)]
mod tests {
    use super::*;

    const EMPTY_CONFIG: &str = r#"{
        "createdAt": "2026-05-22T00:00:00.000Z",
        "format": "SERVER",
        "environment": {
            "name": "Test"
        },
        "flags": {}
    }"#;

    fn load_empty_config() -> bool {
        let json = CString::new(EMPTY_CONFIG).expect("test fixture is valid cstring");
        ddog_ffe_load_config(json.as_ptr())
    }

    #[test]
    fn configuration_state_is_thread_local() {
        clear_config();
        let empty_version = ddog_ffe_config_version();
        assert!(!ddog_ffe_has_config());

        assert!(load_empty_config());
        assert!(ddog_ffe_has_config());
        let loaded_version = ddog_ffe_config_version();
        assert_eq!(loaded_version, empty_version.wrapping_add(1));

        let child = std::thread::spawn(|| {
            assert!(!ddog_ffe_has_config());
            assert_eq!(ddog_ffe_config_version(), 0);

            assert!(load_empty_config());
            assert!(ddog_ffe_has_config());
            assert_eq!(ddog_ffe_config_version(), 1);
        });

        child.join().expect("child thread should not panic");

        assert!(ddog_ffe_has_config());
        assert_eq!(ddog_ffe_config_version(), loaded_version);
        clear_config();
    }
}
