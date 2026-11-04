use crate::bytes::MaybeOwnedZendString;
use datadog_ffe::rules_based::{
    self as ffe, AssignmentReason, AssignmentValue, Attribute, Configuration, EvaluationContext,
    EvaluationError, ExpectedFlagType, Feature, FeatureValue, Str, UniversalFlagConfig,
};
use datadog_ffe::telemetry::exposures::{ExposureDeduplicator, FfeExposure};
use datadog_ffe::telemetry::FfeTelemetryContext;
use libdd_common_ffi::slice::{AsBytes, CharSlice};
use std::cell::RefCell;
use std::collections::HashMap;
use std::sync::{Arc, LazyLock};
use tracing::debug;

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

/// `requested_env` is the environment the SDK asked Remote Configuration for,
/// labeled separately from the environment the configuration was built for.
pub fn store_config(config: Configuration, requested_env: Option<&str>) {
    // Summarized before installing and logged after releasing the state, so a
    // logging failure can neither prevent nor follow a partial install.
    let summary = tracing::enabled!(tracing::Level::DEBUG).then(|| ConfigSummary::new(&config));
    let (version, replaced_version) = FFE_STATE.with(|state| {
        let mut state = state.borrow_mut();
        let replaced_version = state.config.as_ref().map(|_| state.version);
        state.config = Some(config);
        state.version = state.version.wrapping_add(1);
        (state.version, replaced_version)
    });
    if let Some(summary) = summary {
        debug!(
            "{}",
            summary.applied_message(requested_env, version, replaced_version)
        );
        debug!(
            "Feature Flags: readiness={}; configuration applied (version={version}, initial_source={})",
            if version > 1 && replaced_version.is_none() { "recovered" } else { "ready" },
            if requested_env.is_some() { "remote_config" } else { "local" }
        );
        if !summary.rejected.is_empty() {
            debug!(
                "Feature Flags: {} flag(s) rejected as invalid or unsupported; evaluating them returns the default value with PARSE_ERROR: {:?}",
                summary.rejected.len(),
                summary.rejected,
            );
        }
    }
}

pub fn clear_config() {
    FFE_STATE.with(|state| {
        let mut state = state.borrow_mut();
        state.config = None;
        state.version = state.version.wrapping_add(1);
    });
    debug!("Feature Flags: readiness=unavailable; configuration removed, evaluations return defaults until recovery");
}

/// Describes what stays active after a configuration is rejected.
pub fn retained_config_description() -> String {
    FFE_STATE.with(|state| {
        let state = state.borrow();
        match state.config {
            Some(_) => format!(
                "retaining previous configuration (version={})",
                state.version
            ),
            None => "no configuration is loaded, so evaluations return default values".to_string(),
        }
    })
}

struct ConfigSummary {
    flags: usize,
    disabled: usize,
    rejected: Vec<String>,
    environment: String,
    created_at: String,
}

impl ConfigSummary {
    fn new(config: &Configuration) -> ConfigSummary {
        // Disabled and rejected flags fail at lookup, before any targeting
        // runs, so an empty context classifies every flag.
        let context = EvaluationContext::new(None, Arc::new(HashMap::new()));
        let now = ffe::now();
        let mut summary = ConfigSummary {
            flags: 0,
            disabled: 0,
            rejected: Vec::new(),
            environment: config.environment().to_string(),
            created_at: config.created_at().to_string(),
        };
        for key in config.flag_keys() {
            summary.flags += 1;
            match ffe::get_assignment(Some(config), key, &context, ExpectedFlagType::Any, now) {
                Err(EvaluationError::FlagDisabled) => summary.disabled += 1,
                Err(EvaluationError::FlagConfigurationInvalid) => {
                    summary.rejected.push(key.to_string())
                }
                _ => {}
            }
        }
        summary.rejected.sort();
        summary
    }

    fn applied_message(
        &self,
        requested_env: Option<&str>,
        version: u64,
        replaced_version: Option<u64>,
    ) -> String {
        let requested_env = requested_env
            .map(|env| format!(", requested env={env:?}"))
            .unwrap_or_default();
        let replaced = replaced_version
            .map(|version| format!("replaced version {version}"))
            .unwrap_or_else(|| "no previous configuration".to_string());
        format!(
            "Feature Flags: configuration applied successfully ({} flag(s): {} usable, {} disabled, {} rejected; delivered env={:?}{}, created_at={}, version={}, {})",
            self.flags,
            self.flags - self.disabled - self.rejected.len(),
            self.disabled,
            self.rejected.len(),
            self.environment,
            requested_env,
            self.created_at,
            version,
            replaced,
        )
    }
}

#[no_mangle]
pub extern "C" fn ddog_ffe_load_config(json: CharSlice<'_>) -> bool {
    if json.as_raw_parts().0.is_null() {
        return false;
    }

    let json = match json.try_to_utf8() {
        Ok(json) => json,
        Err(_) => {
            debug!(
                "Feature Flags: configuration rejected: not valid UTF-8; {}",
                retained_config_description()
            );
            return false;
        }
    };

    match UniversalFlagConfig::from_json(json.as_bytes().to_vec()) {
        Ok(ufc) => {
            store_config(Configuration::from_server_response(ufc), None);
            true
        }
        Err(error) => {
            debug!(
                "Feature Flags: configuration rejected: {error}; {}",
                retained_config_description()
            );
            false
        }
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
    // serial_id is the selected split's serial id, carried for APM span
    // enrichment (ffe_flags_enc). The source field is Option<i32>; since the
    // C ABI cannot represent Option<i32> as a plain field, we surface the
    // presence separately via has_serial_id. Consumers MUST gate on
    // has_serial_id (the Pattern B "missing variant => default" semantic) and
    // never treat serial_id == 0 as "absent".
    pub serial_id: i32,
    pub has_serial_id: bool,
    pub do_log: bool,
    // Consent belongs to the configuration snapshot used for this evaluation.
    pub observe_full_evaluation_data: bool,
    pub valid: bool,
    /// NULL when the selected split has no features. Release with ddog_ffe_features_drop.
    pub features: Option<Box<FfeFeatures>>,
}

pub struct FfeFeatures(Arc<[Feature]>);

#[repr(C)]
pub struct FfeFeature<'a> {
    pub key: CharSlice<'a>,
    /// One of the ATTR_TYPE_* values: 0 string, 1 number, 2 boolean.
    pub value_type: i32,
    pub string_value: CharSlice<'a>,
    pub number_value: f64,
    pub bool_value: bool,
    pub destinations_count: usize,
}

#[no_mangle]
pub extern "C" fn ddog_ffe_features_count(features: &FfeFeatures) -> usize {
    features.0.len()
}

/// An out-of-range index returns a feature with an empty key and no destinations.
#[no_mangle]
pub extern "C" fn ddog_ffe_features_get(features: &FfeFeatures, index: usize) -> FfeFeature<'_> {
    let mut view = FfeFeature {
        key: CharSlice::from(""),
        value_type: ATTR_TYPE_STRING,
        string_value: CharSlice::from(""),
        number_value: 0.0,
        bool_value: false,
        destinations_count: 0,
    };
    let Some(feature) = features.0.get(index) else {
        return view;
    };
    view.key = CharSlice::from(feature.key.as_str());
    view.destinations_count = feature.destinations.len();
    match &feature.value {
        FeatureValue::String(value) => view.string_value = CharSlice::from(value.as_str()),
        FeatureValue::Number(value) => {
            view.value_type = ATTR_TYPE_NUMBER;
            view.number_value = *value;
        }
        FeatureValue::Boolean(value) => {
            view.value_type = ATTR_TYPE_BOOL;
            view.bool_value = *value;
        }
    }
    view
}

/// An out-of-range index returns an empty destination.
#[no_mangle]
pub extern "C" fn ddog_ffe_features_destination(
    features: &FfeFeatures,
    index: usize,
    destination: usize,
) -> CharSlice<'_> {
    features
        .0
        .get(index)
        .and_then(|feature| feature.destinations.get(destination))
        .map_or(CharSlice::from(""), |destination| {
            CharSlice::from(destination.as_str())
        })
}

#[no_mangle]
pub extern "C" fn ddog_ffe_features_drop(features: Option<Box<FfeFeatures>>) {
    drop(features);
}

// Shared by every thread of the process. The sidecar deduplicates again
// across processes before sending.
static EXPOSURE_CACHE: LazyLock<ExposureDeduplicator> =
    LazyLock::new(ExposureDeduplicator::default);

#[repr(C)]
pub struct FfeExposureKey<'a> {
    /// Destinations, such as test agent sessions, that must not suppress each other's exposures.
    pub scope: CharSlice<'a>,
    pub service: CharSlice<'a>,
    pub env: CharSlice<'a>,
    pub version: CharSlice<'a>,
    pub flag_key: CharSlice<'a>,
    pub subject_id: CharSlice<'a>,
    pub allocation_key: CharSlice<'a>,
    pub variant: CharSlice<'a>,
    pub serial_id: i32,
    pub has_serial_id: bool,
}

/// Records the exposure in the process exposure cache. Returns false when the
/// subject was already exposed to the same allocation, variant and serial id.
#[no_mangle]
pub extern "C" fn ddog_ffe_exposure_cache_record(key: &FfeExposureKey<'_>) -> bool {
    let context = FfeTelemetryContext {
        service: key.service.to_utf8_lossy().into_owned(),
        env: key.env.to_utf8_lossy().into_owned(),
        version: key.version.to_utf8_lossy().into_owned(),
    };
    let exposure = FfeExposure {
        timestamp_ms: 0,
        flag_key: key.flag_key.to_utf8_lossy().into_owned(),
        subject_id: key.subject_id.to_utf8_lossy().into_owned(),
        subject_attributes_json: String::new(),
        allocation_key: key.allocation_key.to_utf8_lossy().into_owned(),
        variant: key.variant.to_utf8_lossy().into_owned(),
        serial_id: key.has_serial_id.then_some(key.serial_id),
        features_json: String::new(),
    };
    EXPOSURE_CACHE.should_send_in_scope(&key.scope.to_utf8_lossy(), &context, &exposure)
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
    let expects_object = matches!(expected_type, ExpectedFlagType::Object);

    FFE_STATE.with(|state| {
        let state = state.borrow();
        let assignment = ffe::get_assignment(
            state.config.as_ref(),
            flag_key,
            &context,
            expected_type,
            ffe::now(),
        );
        if let Err(error) = &assignment {
            debug!("Feature Flags: native resolution failed (flagKey={flag_key:?}, configVersion={}): {error}", state.version);
        }

        // The PHP SDK returns object flags as arrays, so reject scalar JSON
        // before recording logs, metrics and exposures. Error observations still
        // capture the consent setting from the same configuration.
        let mut result = if expects_object
            && assignment
                .as_ref()
                .is_ok_and(|assignment| !is_json_container(&assignment.value))
        {
            if let Ok(assignment) = &assignment {
                debug!("Feature Flags: native resolution failed (flagKey={flag_key:?}, configVersion={}): expected object/array, resolved raw JSON={}", state.version, assignment_value_to_json(&assignment.value));
            }
            error_result(ERROR_TYPE_MISMATCH, REASON_ERROR)
        } else {
            result_from_assignment(assignment)
        };
        result.observe_full_evaluation_data = state
            .config
            .as_ref()
            .is_some_and(Configuration::observe_full_evaluation_data);
        result
    })
}

fn is_json_container(value: &AssignmentValue) -> bool {
    match value {
        AssignmentValue::Json { value, .. } => value.is_object() || value.is_array(),
        _ => false,
    }
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
                serial_id: assignment.serial_id.unwrap_or(0),
                has_serial_id: assignment.serial_id.is_some(),
                do_log: assignment.do_log,
                observe_full_evaluation_data: false,
                valid: true,
                features: assignment
                    .features
                    .map(|features| Box::new(FfeFeatures(features))),
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

            error_result(error_code, reason)
        }
    }
}

fn error_result(error_code: i32, reason: i32) -> FfeResult {
    FfeResult {
        value_json: Some("null".into()),
        variant: None,
        allocation_key: None,
        reason,
        error_code,
        serial_id: 0,
        has_serial_id: false,
        do_log: false,
        observe_full_evaluation_data: false,
        valid: true,
        features: None,
    }
}

fn invalid_result() -> FfeResult {
    FfeResult {
        value_json: None,
        variant: None,
        allocation_key: None,
        reason: REASON_ERROR,
        error_code: ERROR_GENERAL,
        serial_id: 0,
        has_serial_id: false,
        do_log: false,
        observe_full_evaluation_data: false,
        valid: false,
        features: None,
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

    fn json_flag_config(value: &str) -> CString {
        CString::new(format!(
            r#"{{
                "createdAt": "2026-05-22T00:00:00.000Z",
                "format": "SERVER",
                "environment": {{"name": "Test"}},
                "flags": {{
                    "json.flag": {{
                        "key": "json.flag",
                        "enabled": true,
                        "variationType": "JSON",
                        "variations": {{"on": {{"key": "on", "value": {value}}}}},
                        "allocations": [{{
                            "key": "alloc",
                            "rules": [],
                            "splits": [{{"variationKey": "on", "shards": []}}],
                            "doLog": true
                        }}]
                    }}
                }}
            }}"#
        ))
        .expect("test fixture is valid cstring")
    }

    fn evaluate_json_flag(value: &str) -> FfeResult {
        assert!(ddog_ffe_load_config(char_slice(&json_flag_config(value))));
        let flag_key = CString::new("json.flag").expect("test flag key is valid cstring");
        ddog_ffe_evaluate(
            char_slice(&flag_key),
            TYPE_OBJECT,
            CharSlice::from("user"),
            std::ptr::null(),
            0,
        )
    }

    #[test]
    fn scalar_json_object_flags_are_type_mismatches() {
        setup_zend_string_functions();
        clear_config();
        for value in ["42", r#""text""#, "null"] {
            let result = evaluate_json_flag(value);
            assert!(result.valid, "{value}");
            assert_eq!(result.reason, REASON_ERROR, "{value}");
            assert_eq!(result.error_code, ERROR_TYPE_MISMATCH, "{value}");
            assert!(result.variant.is_none(), "{value}");
            assert!(!result.do_log, "{value}: no exposure for a rejected value");
        }
        for value in [r#"{"a": 1}"#, "[1, 2]"] {
            let result = evaluate_json_flag(value);
            assert_eq!(result.error_code, ERROR_NONE, "{value}");
            assert_eq!(result.reason, REASON_STATIC, "{value}");
            assert!(result.do_log, "{value}");
        }
        clear_config();
    }

    const FEATURES_CONFIG: &str = r#"{
        "createdAt": "2026-05-22T00:00:00.000Z",
        "environment": {"name": "Test"},
        "flags": {
            "with.features": {
                "key": "with.features",
                "enabled": true,
                "variationType": "STRING",
                "variations": {"on": {"key": "on", "value": "on"}},
                "allocations": [{
                    "key": "alloc",
                    "rules": [],
                    "splits": [{
                        "variationKey": "on",
                        "shards": [],
                        "features": [
                            {"key": "holdout.key", "value": "abc", "destinations": ["HOOK", "EXPOSURE"]},
                            {"key": "holdout.fraction", "value": 0.5, "destinations": ["EVALUATION"]},
                            {"key": "holdout.enabled", "value": false, "destinations": ["HOOK"]}
                        ]
                    }],
                    "doLog": true
                }]
            },
            "without.features": {
                "key": "without.features",
                "enabled": true,
                "variationType": "STRING",
                "variations": {"on": {"key": "on", "value": "on"}},
                "allocations": [{
                    "key": "alloc",
                    "rules": [],
                    "splits": [{"variationKey": "on", "shards": []}],
                    "doLog": true
                }]
            }
        }
    }"#;

    fn evaluate_string_flag(flag_key: &str, expected_type: i32) -> FfeResult {
        let flag_key = CString::new(flag_key).unwrap();
        ddog_ffe_evaluate(
            char_slice(&flag_key),
            expected_type,
            CharSlice::from("user"),
            std::ptr::null(),
            0,
        )
    }

    fn destinations(features: &FfeFeatures, index: usize) -> Vec<String> {
        let count = ddog_ffe_features_get(features, index).destinations_count;
        (0..count)
            .map(|destination| {
                let slice = ddog_ffe_features_destination(features, index, destination);
                String::from_utf8(slice.as_bytes().to_vec()).unwrap()
            })
            .collect()
    }

    #[test]
    fn evaluation_exposes_the_selected_split_features() {
        setup_zend_string_functions();
        clear_config();
        let config = CString::new(FEATURES_CONFIG).unwrap();
        assert!(ddog_ffe_load_config(char_slice(&config)));

        let result = evaluate_string_flag("with.features", TYPE_STRING);
        let features = result.features.as_deref().expect("split has features");
        assert_eq!(ddog_ffe_features_count(features), 3);

        let string = ddog_ffe_features_get(features, 0);
        assert_eq!(string.key.as_bytes(), b"holdout.key");
        assert_eq!(string.value_type, ATTR_TYPE_STRING);
        assert_eq!(string.string_value.as_bytes(), b"abc");
        assert_eq!(destinations(features, 0), ["HOOK", "EXPOSURE"]);

        let number = ddog_ffe_features_get(features, 1);
        assert_eq!(number.key.as_bytes(), b"holdout.fraction");
        assert_eq!(number.value_type, ATTR_TYPE_NUMBER);
        assert_eq!(number.number_value, 0.5);
        assert_eq!(destinations(features, 1), ["EVALUATION"]);

        let boolean = ddog_ffe_features_get(features, 2);
        assert_eq!(boolean.value_type, ATTR_TYPE_BOOL);
        assert!(!boolean.bool_value);

        let missing = ddog_ffe_features_get(features, 3);
        assert_eq!(missing.key.as_bytes(), b"");
        assert_eq!(missing.destinations_count, 0);
        assert_eq!(
            ddog_ffe_features_destination(features, 0, 2).as_bytes(),
            b""
        );
        assert_eq!(
            ddog_ffe_features_destination(features, 3, 0).as_bytes(),
            b""
        );
        ddog_ffe_features_drop(result.features);

        assert!(evaluate_string_flag("without.features", TYPE_STRING)
            .features
            .is_none());
        assert!(evaluate_string_flag("with.features", TYPE_BOOLEAN)
            .features
            .is_none());
        assert!(evaluate_string_flag("with.features", TYPE_OBJECT)
            .features
            .is_none());
        clear_config();
    }

    #[test]
    fn exposure_cache_reports_new_and_changed_exposures() {
        let record_in = |scope: &str, subject: &str, variant: &str, serial_id: Option<i32>| {
            ddog_ffe_exposure_cache_record(&FfeExposureKey {
                scope: CharSlice::from(scope),
                service: CharSlice::from("service"),
                env: CharSlice::from("env"),
                version: CharSlice::from("1.0"),
                flag_key: CharSlice::from("exposure.cache.flag"),
                subject_id: CharSlice::from(subject),
                allocation_key: CharSlice::from("alloc"),
                variant: CharSlice::from(variant),
                serial_id: serial_id.unwrap_or(0),
                has_serial_id: serial_id.is_some(),
            })
        };
        let record = |subject: &str, variant: &str, serial_id: Option<i32>| {
            record_in("", subject, variant, serial_id)
        };
        assert!(record("user", "on", Some(1)));
        assert!(!record("user", "on", Some(1)));
        assert!(record_in("session", "user", "on", Some(1)));
        assert!(!record_in("session", "user", "on", Some(1)));
        assert!(record("other", "on", Some(1)));
        assert!(record("user", "off", Some(1)));
        assert!(record("user", "off", Some(2)));
        assert!(record("user", "off", None));
        assert!(record("user", "off", Some(0)));
        assert!(!record("user", "off", Some(0)));
    }

    #[test]
    fn evaluation_captures_consent_from_its_configuration() {
        setup_zend_string_functions();
        clear_config();
        let flag_key = CString::new("empty.targeting.shard.flag").unwrap();
        let evaluate = |value_type| {
            ddog_ffe_evaluate(
                char_slice(&flag_key),
                value_type,
                CharSlice::from(""),
                std::ptr::null(),
                0,
            )
        };
        assert!(!evaluate(TYPE_STRING).observe_full_evaluation_data);

        let mut config: serde_json::Value =
            serde_json::from_str(EMPTY_TARGETING_KEY_CONFIG).unwrap();
        config["observeFullEvaluationData"] = true.into();
        let json = CString::new(config.to_string()).unwrap();
        assert!(ddog_ffe_load_config(char_slice(&json)));
        let allowed = evaluate(TYPE_STRING);
        assert!(allowed.valid);
        assert!(allowed.observe_full_evaluation_data);
        // Error observations use the same consent as successful assignments.
        let mismatch = evaluate(TYPE_BOOLEAN);
        assert_ne!(mismatch.error_code, ERROR_NONE);
        assert!(mismatch.observe_full_evaluation_data);

        config["observeFullEvaluationData"] = false.into();
        let json = CString::new(config.to_string()).unwrap();
        assert!(ddog_ffe_load_config(char_slice(&json)));
        assert!(!evaluate(TYPE_STRING).observe_full_evaluation_data);
        // Reloading config must not retroactively change the prior outcome.
        assert!(allowed.observe_full_evaluation_data);
        clear_config();
        assert!(!evaluate(TYPE_STRING).observe_full_evaluation_data);
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

    #[test]
    fn config_summary_separates_usable_disabled_and_rejected_flags() {
        let flag = |key: &str, enabled: bool, value: &str| {
            format!(
                r#""{key}": {{
                    "key": "{key}", "enabled": {enabled}, "variationType": "STRING",
                    "variations": {{"on": {{"key": "on", "value": {value}}}}},
                    "allocations": [{{"key": "alloc", "rules": [],
                        "splits": [{{"variationKey": "on", "shards": []}}], "doLog": true}}]
                }}"#
            )
        };
        let json = format!(
            r#"{{"createdAt": "2026-05-22T00:00:00.000Z", "environment": {{"name": "staging"}}, "flags": {{{}, {}, {}, {}}}}}"#,
            flag("usable", true, r#""on""#),
            flag("disabled", false, r#""on""#),
            // A number is not a valid STRING variation.
            flag("wrong.type", true, "1"),
            r#""malformed": {"key": "malformed"}"#,
        );
        let config = Configuration::from_server_response(
            UniversalFlagConfig::from_json(json.into_bytes()).expect("fixture is a valid envelope"),
        );

        let summary = ConfigSummary::new(&config);
        assert_eq!(summary.flags, 4);
        assert_eq!(summary.disabled, 1);
        assert_eq!(summary.rejected, vec!["malformed", "wrong.type"]);
        assert_eq!(
            summary.applied_message(Some("production"), 3, Some(2)),
            "Feature Flags: configuration applied successfully (4 flag(s): 1 usable, 1 disabled, 2 rejected; delivered env=\"staging\", requested env=\"production\", created_at=2026-05-22 00:00:00 UTC, version=3, replaced version 2)"
        );
        assert_eq!(
            summary.applied_message(None, 1, None),
            "Feature Flags: configuration applied successfully (4 flag(s): 1 usable, 1 disabled, 2 rejected; delivered env=\"staging\", created_at=2026-05-22 00:00:00 UTC, version=1, no previous configuration)"
        );
    }

    #[test]
    fn retained_config_description_reports_the_active_configuration() {
        clear_config();
        assert_eq!(
            retained_config_description(),
            "no configuration is loaded, so evaluations return default values"
        );
        assert!(load_empty_config());
        let invalid = CString::new(r#"{"flags":"#).expect("test fixture is valid cstring");
        assert!(!ddog_ffe_load_config(char_slice(&invalid)));
        assert!(ddog_ffe_has_config());
        assert_eq!(
            retained_config_description(),
            format!(
                "retaining previous configuration (version={})",
                ddog_ffe_config_version()
            )
        );
        clear_config();
    }
}
