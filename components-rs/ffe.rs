use crate::bytes::MaybeOwnedZendString;
use datadog_ffe::rules_based::{
    self as ffe, AssignmentReason, AssignmentValue, Attribute, Configuration, EvaluationContext,
    EvaluationError, ExpectedFlagType, Str, UniversalFlagConfig,
};
use libdd_common_ffi::slice::{AsBytes, CharSlice};
use std::cell::RefCell;
use std::collections::HashMap;
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
pub extern "C" fn ddog_ffe_load_config(json: CharSlice<'_>) -> bool {
    if json.as_raw_parts().0.is_null() {
        return false;
    }

    let json = match json.try_to_utf8() {
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

#[repr(C)]
pub struct FfeResult {
    pub value_json: MaybeOwnedZendString,
    pub variant: MaybeOwnedZendString,
    pub allocation_key: MaybeOwnedZendString,
    pub reason: i32,
    pub error_code: i32,
    pub do_log: bool,
    pub valid: bool,
}

#[repr(C)]
pub struct FfeAttribute<'a> {
    pub key: CharSlice<'a>,
    pub value_type: i32,
    pub string_value: CharSlice<'a>,
    pub number_value: f64,
    pub bool_value: bool,
}

#[no_mangle]
pub extern "C" fn ddog_ffe_evaluate(
    flag_key: CharSlice<'_>,
    expected_type: i32,
    targeting_key: CharSlice<'_>,
    attributes: *const FfeAttribute<'_>,
    attributes_count: usize,
) -> FfeResult {
    if flag_key.as_raw_parts().0.is_null() {
        return invalid_result();
    }

    let flag_key = match flag_key.try_to_utf8() {
        Ok(flag_key) => flag_key,
        Err(_) => return invalid_result(),
    };

    let expected_type = match expected_type {
        TYPE_STRING => ExpectedFlagType::String,
        TYPE_INTEGER => ExpectedFlagType::Integer,
        TYPE_FLOAT => ExpectedFlagType::Float,
        TYPE_BOOLEAN => ExpectedFlagType::Boolean,
        TYPE_OBJECT => ExpectedFlagType::Object,
        _ => return invalid_result(),
    };

    let targeting_key = if targeting_key.as_raw_parts().0.is_null() {
        None
    } else {
        match targeting_key.try_to_utf8() {
            Ok(targeting_key) => Some(Str::from(targeting_key)),
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

        result_from_assignment(assignment)
    })
}

fn parse_attributes(
    attributes: *const FfeAttribute<'_>,
    attributes_count: usize,
) -> HashMap<Str, Attribute> {
    let mut parsed = HashMap::new();

    if attributes.is_null() || attributes_count == 0 {
        return parsed;
    }

    let attributes = unsafe { std::slice::from_raw_parts(attributes, attributes_count) };
    for attribute in attributes {
        if attribute.key.as_raw_parts().0.is_null() {
            continue;
        }

        let key = match attribute.key.try_to_utf8() {
            Ok(key) => key,
            Err(_) => continue,
        };

        let value = match attribute.value_type {
            ATTR_TYPE_STRING => {
                if attribute.string_value.as_raw_parts().0.is_null() {
                    continue;
                }

                match attribute.string_value.try_to_utf8() {
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
        Ok(assignment) => {
            let value_json = assignment_value_to_json(&assignment.value);
            FfeResult {
                value_json: Some(value_json.as_str().into()),
                variant: Some(assignment.variation_key.as_str().into()),
                allocation_key: Some(assignment.allocation_key.as_str().into()),
                reason: match assignment.reason {
                    AssignmentReason::Static => REASON_STATIC,
                    AssignmentReason::TargetingMatch => REASON_TARGETING_MATCH,
                    AssignmentReason::Split => REASON_SPLIT,
                    AssignmentReason::Default => REASON_DEFAULT,
                },
                error_code: ERROR_NONE,
                do_log: assignment.do_log,
                valid: true,
            }
        }
        Err(error) => {
            let (error_code, reason) = match &error {
                EvaluationError::TypeMismatch { .. } => (ERROR_TYPE_MISMATCH, REASON_ERROR),
                EvaluationError::ConfigurationParseError => (ERROR_CONFIG_PARSE, REASON_ERROR),
                EvaluationError::FlagConfigurationInvalid => (ERROR_CONFIG_PARSE, REASON_ERROR),
                EvaluationError::ConfigurationMissing => (ERROR_CONFIG_MISSING, REASON_ERROR),
                EvaluationError::FlagUnrecognizedOrDisabled => {
                    (ERROR_FLAG_UNRECOGNIZED, REASON_DEFAULT)
                }
                EvaluationError::FlagDisabled => (ERROR_NONE, REASON_DISABLED),
                EvaluationError::DefaultAllocationNull => (ERROR_NONE, REASON_DEFAULT),
                _ => (ERROR_GENERAL, REASON_ERROR),
            };

            FfeResult {
                value_json: Some("null".into()),
                variant: None,
                allocation_key: None,
                reason,
                error_code,
                do_log: false,
                valid: true,
            }
        }
    }
}

fn invalid_result() -> FfeResult {
    FfeResult {
        value_json: None,
        variant: None,
        allocation_key: None,
        reason: REASON_ERROR,
        error_code: ERROR_GENERAL,
        do_log: false,
        valid: false,
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

#[cfg(test)]
mod tests {
    use super::*;
    use crate::bytes::{OwnedZendString, ZendString};
    use std::alloc::{alloc_zeroed, dealloc, Layout};
    use std::ffi::CString;
    use std::mem;
    use std::ptr;
    use std::ptr::NonNull;
    use std::sync::Once;

    static INIT_ZEND_STRING_FUNCTIONS: Once = Once::new();

    fn setup_zend_string_functions() {
        INIT_ZEND_STRING_FUNCTIONS.call_once(|| unsafe {
            crate::bytes::ddog_init_span_func(
                test_free_zend_string,
                test_addref_zend_string,
                test_init_zend_string,
            );
        });
    }

    extern "C" fn test_addref_zend_string(value: &mut ZendString) {
        value.refcount = value.refcount.saturating_add(1);
    }

    extern "C" fn test_init_zend_string(value: CharSlice<'_>) -> OwnedZendString {
        let bytes = value.as_bytes();
        let layout = zend_string_layout(bytes.len());
        let raw = unsafe { alloc_zeroed(layout) as *mut ZendString };
        let raw = NonNull::new(raw).expect("test allocation should succeed");

        unsafe {
            let zend_string = raw.as_ptr();
            (*zend_string).refcount = 1;
            (*zend_string).type_info = 0;
            (*zend_string).h = 0;
            (*zend_string).len = bytes.len();
            ptr::copy_nonoverlapping(bytes.as_ptr(), (*zend_string).val.as_mut_ptr(), bytes.len());
            *(*zend_string).val.as_mut_ptr().add(bytes.len()) = 0;
        }

        OwnedZendString(raw)
    }

    extern "C" fn test_free_zend_string(value: OwnedZendString) {
        unsafe {
            let raw = value.0.as_ptr();
            let layout = zend_string_layout((*raw).len);
            dealloc(raw as *mut u8, layout);
        }
        mem::forget(value);
    }

    fn zend_string_layout(len: usize) -> Layout {
        Layout::from_size_align(
            mem::size_of::<ZendString>() + len,
            mem::align_of::<ZendString>(),
        )
        .expect("test zend_string layout should be valid")
    }

    fn char_slice(value: &CString) -> CharSlice<'_> {
        unsafe { CharSlice::from_raw_parts(value.as_ptr(), value.as_bytes().len()) }
    }

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
        ddog_ffe_load_config(char_slice(&json))
    }

    const EMPTY_TARGETING_KEY_CONFIG: &str = r#"{
        "createdAt": "2026-05-22T00:00:00.000Z",
        "format": "SERVER",
        "environment": {
            "name": "Test"
        },
        "flags": {
            "empty.targeting.shard.flag": {
                "key": "empty.targeting.shard.flag",
                "enabled": true,
                "variationType": "STRING",
                "variations": {
                    "empty-target": {
                        "key": "empty-target",
                        "value": "empty-targeting-key"
                    }
                },
                "allocations": [{
                    "key": "alloc-empty-targeting-key",
                    "rules": [],
                    "splits": [{
                        "variationKey": "empty-target",
                        "shards": [{
                            "salt": "empty-targeting-key-regression",
                            "totalShards": 10000,
                            "ranges": [{"start": 8022, "end": 8023}]
                        }]
                    }],
                    "doLog": true
                }]
            }
        }
    }"#;

    #[test]
    fn empty_targeting_key_is_not_dropped() {
        setup_zend_string_functions();
        clear_config();
        let config =
            CString::new(EMPTY_TARGETING_KEY_CONFIG).expect("test fixture is valid cstring");
        assert!(ddog_ffe_load_config(char_slice(&config)));

        let flag_key =
            CString::new("empty.targeting.shard.flag").expect("test flag key is valid cstring");
        let result = ddog_ffe_evaluate(
            char_slice(&flag_key),
            TYPE_STRING,
            CharSlice::from(""),
            std::ptr::null(),
            0,
        );

        assert!(result.valid);
        assert_eq!(result.reason, REASON_SPLIT);
        assert_eq!(result.error_code, ERROR_NONE);
        assert_eq!(result.do_log, true);
        assert_eq!(
            std::str::from_utf8(result.value_json.as_ref().unwrap().as_ref()).unwrap(),
            r#""empty-targeting-key""#
        );
        clear_config();
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
