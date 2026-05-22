use datadog_ffe::rules_based::{
    self as ffe, AssignmentReason, AssignmentValue, Attribute, Configuration, EvaluationContext,
    EvaluationError, ExpectedFlagType, Str, UniversalFlagConfig,
};
use libdd_common_ffi::CharSlice;
use lru::LruCache;
use std::collections::HashMap;
use std::ffi::{c_char, CStr, CString};
use std::num::NonZeroUsize;
use std::sync::{Arc, Mutex};

struct FfeState {
    config: Option<Configuration>,
    version: u64,
}

lazy_static::lazy_static! {
    static ref FFE_STATE: Mutex<FfeState> = Mutex::new(FfeState {
        config: None,
        version: 0,
    });
}

pub fn store_config(config: Configuration) {
    if let Ok(mut state) = FFE_STATE.lock() {
        state.config = Some(config);
        state.version = state.version.wrapping_add(1);
    }
}

pub fn clear_config() {
    if let Ok(mut state) = FFE_STATE.lock() {
        state.config = None;
        state.version = state.version.wrapping_add(1);
    }
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
    FFE_STATE
        .lock()
        .map(|state| state.config.is_some())
        .unwrap_or(false)
}

#[no_mangle]
pub extern "C" fn ddog_ffe_config_version() -> u64 {
    FFE_STATE.lock().map(|state| state.version).unwrap_or(0)
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

    let state = match FFE_STATE.lock() {
        Ok(state) => state,
        Err(_) => return std::ptr::null_mut(),
    };

    let assignment = ffe::get_assignment(
        state.config.as_ref(),
        flag_key,
        &context,
        expected_type,
        ffe::now(),
    );

    Box::into_raw(Box::new(result_from_assignment(assignment)))
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

struct ServiceContext {
    service: String,
    env: String,
    version: String,
}

struct ExposureState {
    dedup_cache: LruCache<(String, String), (String, String)>,
    batch_buffer: Vec<String>,
    service_context: Option<ServiceContext>,
}

const EXPOSURE_DEDUP_LIMIT: usize = 65_536;
const EXPOSURE_BATCH_LIMIT: usize = 1_000;

lazy_static::lazy_static! {
    static ref EXPOSURE_STATE: Mutex<ExposureState> = Mutex::new(ExposureState {
        dedup_cache: LruCache::new(NonZeroUsize::new(EXPOSURE_DEDUP_LIMIT).unwrap()),
        batch_buffer: Vec::new(),
        service_context: None,
    });
}

#[no_mangle]
pub unsafe extern "C" fn ddog_ffe_set_service_context(
    service: *const c_char,
    env: *const c_char,
    version: *const c_char,
) {
    if let Ok(mut state) = EXPOSURE_STATE.lock() {
        state.service_context = Some(ServiceContext {
            service: optional_cstr_to_string(service),
            env: optional_cstr_to_string(env),
            version: optional_cstr_to_string(version),
        });
    }
}

#[no_mangle]
pub unsafe extern "C" fn ddog_ffe_enqueue_exposure(
    event_json: *const c_char,
    flag_key: *const c_char,
    allocation_key: *const c_char,
    targeting_key: *const c_char,
    variant_key: *const c_char,
) -> bool {
    if event_json.is_null() || flag_key.is_null() || variant_key.is_null() {
        return false;
    }

    let event = match required_cstr_to_string(event_json) {
        Some(event) => event,
        None => return false,
    };
    let flag = match required_cstr_to_string(flag_key) {
        Some(flag) => flag,
        None => return false,
    };
    let variant = match required_cstr_to_string(variant_key) {
        Some(variant) => variant,
        None => return false,
    };
    let allocation = optional_cstr_to_string(allocation_key);
    let targeting = optional_cstr_to_string(targeting_key);

    let dedup_key = (flag, targeting);
    let dedup_value = (allocation, variant);

    if let Ok(mut state) = EXPOSURE_STATE.lock() {
        if let Some(cached) = state.dedup_cache.get(&dedup_key) {
            if *cached == dedup_value {
                return false;
            }
        }

        state.dedup_cache.put(dedup_key, dedup_value);
        if state.batch_buffer.len() >= EXPOSURE_BATCH_LIMIT {
            return false;
        }

        state.batch_buffer.push(event);
        return true;
    }

    false
}

#[no_mangle]
pub extern "C" fn ddog_ffe_flush_exposures() -> CharSlice<'static> {
    if let Ok(mut state) = EXPOSURE_STATE.lock() {
        if state.batch_buffer.is_empty() {
            return CharSlice::default();
        }

        let events = state.batch_buffer.drain(..).collect::<Vec<_>>();
        let context = match &state.service_context {
            Some(context) => serde_json::json!({
                "service": context.service.as_str(),
                "env": context.env.as_str(),
                "version": context.version.as_str(),
            }),
            None => serde_json::json!({
                "service": "",
                "env": "",
                "version": "",
            }),
        };

        let payload = format!(
            r#"{{"context":{},"exposures":[{}]}}"#,
            context,
            events.join(",")
        );
        let mut bytes = payload.into_bytes().into_boxed_slice();
        let ptr = bytes.as_mut_ptr();
        let len = bytes.len();
        std::mem::forget(bytes);

        return unsafe { CharSlice::from_raw_parts(ptr as *const c_char, len) };
    }

    CharSlice::default()
}

#[no_mangle]
pub unsafe extern "C" fn ddog_ffe_free_flush_result(slice: CharSlice<'static>) {
    use libdd_common_ffi::slice::AsBytes;

    let bytes = slice.as_bytes();
    let len = bytes.len();
    let ptr = bytes.as_ptr() as *mut u8;
    if !ptr.is_null() && len > 0 {
        let _ = Box::from_raw(std::slice::from_raw_parts_mut(ptr, len) as *mut [u8]);
    }
}

#[no_mangle]
pub extern "C" fn ddog_ffe_reset_exposure_state() {
    if let Ok(mut state) = EXPOSURE_STATE.lock() {
        state.dedup_cache.clear();
        state.batch_buffer.clear();
        state.service_context = None;
    }
}

unsafe fn required_cstr_to_string(value: *const c_char) -> Option<String> {
    CStr::from_ptr(value)
        .to_str()
        .ok()
        .map(|value| value.to_string())
}

unsafe fn optional_cstr_to_string(value: *const c_char) -> String {
    if value.is_null() {
        return String::new();
    }

    required_cstr_to_string(value).unwrap_or_default()
}

#[cfg(test)]
mod tests {
    use super::*;
    use libdd_common_ffi::slice::AsBytes;

    #[test]
    fn exposure_flush_drains_buffer_and_keeps_context() {
        ddog_ffe_reset_exposure_state();

        let service = CString::new("svc-flush").unwrap();
        let env = CString::new("test").unwrap();
        let version = CString::new("1.2.3").unwrap();
        let event = CString::new(
            r#"{"timestamp":1,"flag":{"key":"demo"},"allocation":{"key":"alloc-a"},"variant":{"key":"on"},"subject":{"id":"user-1","attributes":{}}}"#,
        )
        .unwrap();
        let flag = CString::new("demo").unwrap();
        let allocation = CString::new("alloc-a").unwrap();
        let targeting = CString::new("user-1").unwrap();
        let on = CString::new("on").unwrap();
        let off = CString::new("off").unwrap();

        unsafe {
            ddog_ffe_set_service_context(service.as_ptr(), env.as_ptr(), version.as_ptr());
            assert!(ddog_ffe_enqueue_exposure(
                event.as_ptr(),
                flag.as_ptr(),
                allocation.as_ptr(),
                targeting.as_ptr(),
                on.as_ptr(),
            ));
            assert!(!ddog_ffe_enqueue_exposure(
                event.as_ptr(),
                flag.as_ptr(),
                allocation.as_ptr(),
                targeting.as_ptr(),
                on.as_ptr(),
            ));
            assert!(ddog_ffe_enqueue_exposure(
                event.as_ptr(),
                flag.as_ptr(),
                allocation.as_ptr(),
                targeting.as_ptr(),
                off.as_ptr(),
            ));
        }

        let payload = ddog_ffe_flush_exposures();
        assert!(!payload.as_bytes().is_empty());
        let decoded: serde_json::Value = serde_json::from_slice(payload.as_bytes()).unwrap();
        assert_eq!(decoded["context"]["service"], "svc-flush");
        assert_eq!(decoded["context"]["env"], "test");
        assert_eq!(decoded["context"]["version"], "1.2.3");
        assert_eq!(decoded["exposures"].as_array().unwrap().len(), 2);
        unsafe { ddog_ffe_free_flush_result(payload) };

        let empty = ddog_ffe_flush_exposures();
        assert!(empty.as_bytes().is_empty());
    }
}
