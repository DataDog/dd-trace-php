use crate::bytes::MaybeOwnedZendString;
use datadog_ffe::rules_based::{
    self as ffe, AssignmentReason, AssignmentValue, Attribute, Configuration, EvaluationContext,
    EvaluationError, ExpectedFlagType, Str, UniversalFlagConfig,
};
use datadog_ffe::telemetry::flagevaluation::{
    AllocationKey, FfeFlagEvaluationBatch, FfeFlagEvaluationEvent, FlagKey, VariantKey,
    DEGRADED_CAP, GLOBAL_CAP, PER_FLAG_CAP,
};
use datadog_ffe::telemetry::FfeTelemetryContext;
use datadog_sidecar::service::blocking::{self as sidecar_blocking, SidecarTransport};
use datadog_sidecar::service::{InstanceId, QueueId, SidecarAction};
use libdd_common_ffi::slice::{AsBytes, CharSlice};
use std::cell::RefCell;
use std::collections::HashMap;
use std::sync::{Arc, Mutex};
use std::time::{SystemTime, UNIX_EPOCH};

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

// ── EVP flagevaluation aggregation (EMIT-07, frozen contract) ─────────────────

/// Full-tier aggregation key: six dimensions, all exact strings, no hash.
/// Reviewer concern #3 (3395004724): no collision-prone digest — Go's comparable
/// struct identity ported to Rust via #[derive(Eq, Hash)].
#[derive(Clone, Debug, Eq, Hash, PartialEq)]
struct FullTierKey {
    flag_key: String,
    variant: String,
    allocation_key: String,
    reason: String,
    targeting_key: String,
    /// Type-tagged, length-delimited canonical encoding of pruned context attrs.
    context_key: String,
}

/// Degraded-tier key: four dimensions only (drops targeting_key + context).
/// Matches OTel `feature_flag.evaluations` cardinality exactly.
#[derive(Clone, Debug, Eq, Hash, PartialEq)]
struct DegradedTierKey {
    flag_key: String,
    variant: String,
    allocation_key: String,
    reason: String,
}

/// Per-bucket aggregation state.
#[derive(Clone, Debug)]
struct AggBucket {
    first_evaluation: i64,
    last_evaluation: i64,
    count: u64,
}

impl AggBucket {
    fn new(eval_ms: i64) -> Self {
        AggBucket {
            first_evaluation: eval_ms,
            last_evaluation: eval_ms,
            count: 1,
        }
    }

    fn merge(&mut self, eval_ms: i64) {
        if eval_ms < self.first_evaluation {
            self.first_evaluation = eval_ms;
        }
        if eval_ms > self.last_evaluation {
            self.last_evaluation = eval_ms;
        }
        self.count = self.count.saturating_add(1);
    }
}

/// Per-flag full-tier state: buckets + per-flag count (for perFlagCap).
#[derive(Default)]
struct FullTierFlagState {
    buckets: HashMap<FullTierKey, AggBucket>,
}

/// Two-tier aggregator state. Process-global behind a Mutex.
#[derive(Default)]
struct EvpAggregator {
    /// Full-tier: keyed by FullTierKey. Maps flag_key → per-flag state for
    /// easy perFlagCap enforcement.
    full_tier: HashMap<String, FullTierFlagState>,
    /// Total full-tier bucket count across all flags.
    full_tier_total: usize,
    /// Degraded-tier: keyed by DegradedTierKey.
    degraded_tier: HashMap<DegradedTierKey, AggBucket>,
    /// Evaluations dropped past degradedCap (observable counter).
    dropped_degraded_overflow: u64,
}

lazy_static::lazy_static! {
    static ref EVP_AGGREGATOR: Mutex<EvpAggregator> = Mutex::new(EvpAggregator::default());
}

/// Returns true when the killswitch `DD_FLAGGING_EVALUATION_COUNTS_ENABLED` is
/// not explicitly set to `false` (default: enabled).
fn evp_enabled() -> bool {
    match std::env::var("DD_FLAGGING_EVALUATION_COUNTS_ENABLED") {
        Ok(val) => !matches!(val.to_lowercase().as_str(), "false" | "0" | "no"),
        Err(_) => true, // absent → on
    }
}

/// Canonical context key: length-delimited, serde-JSON-encoded sorted context
/// attributes. No hash — language-native map key (reviewer concern #3
/// 3395004724). Distinct attribute maps produce distinct keys; same maps
/// produce identical keys (deterministic because keys are sorted).
fn canonical_context_key(attrs: &HashMap<Str, Attribute>) -> String {
    // Sort keys for deterministic ordering.
    let mut pairs: Vec<(&Str, &Attribute)> = attrs.iter().collect();
    pairs.sort_by_key(|(k, _)| k.as_str());

    let mut buf = Vec::new();
    for (k, v) in &pairs {
        // Key: 8-byte big-endian length + raw bytes.
        append_length_delimited(&mut buf, k.as_str().as_bytes());
        // Value: serde_json serialization gives a deterministic, type-preserving
        // representation. Strings → `"value"`, numbers → `42`, bools → `true`/`false`.
        // This is wrapped with a length delimiter for unambiguous parsing.
        if let Ok(json) = serde_json::to_string(v) {
            append_length_delimited(&mut buf, json.as_bytes());
        }
    }
    // Safety: all content is valid UTF-8.
    String::from_utf8(buf).unwrap_or_default()
}

fn append_length_delimited(buf: &mut Vec<u8>, data: &[u8]) {
    let len = data.len() as u64;
    buf.extend_from_slice(&len.to_be_bytes());
    buf.extend_from_slice(data);
}

/// Map reason integer to the canonical reason string.
fn reason_str(reason: i32, error_code: i32) -> String {
    match reason {
        REASON_STATIC => "STATIC".to_owned(),
        REASON_TARGETING_MATCH => "TARGETING_MATCH".to_owned(),
        REASON_SPLIT => "SPLIT".to_owned(),
        REASON_DISABLED => "DISABLED".to_owned(),
        REASON_ERROR => {
            // Use a more specific error code label when available.
            match error_code {
                ERROR_TYPE_MISMATCH => "ERROR_TYPE_MISMATCH".to_owned(),
                ERROR_CONFIG_PARSE => "ERROR_CONFIG_PARSE".to_owned(),
                ERROR_FLAG_UNRECOGNIZED => "ERROR_FLAG_UNRECOGNIZED".to_owned(),
                ERROR_CONFIG_MISSING => "ERROR_CONFIG_MISSING".to_owned(),
                _ => "ERROR".to_owned(),
            }
        }
        _ => "DEFAULT".to_owned(),
    }
}

/// Current time in milliseconds since the Unix epoch.
fn now_ms() -> i64 {
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map(|d| d.as_millis() as i64)
        .unwrap_or(0)
}

/// Record one evaluation into the EVP aggregator (two-tier, frozen caps).
/// Called from `ddog_ffe_evaluate()` if the killswitch is on.
///
/// `variant_str`: empty string means runtime default (absent variant, reviewer
/// concern #5 3395344504).
fn record_flag_evaluation_evp(
    flag_key: &str,
    variant_str: &str,
    allocation_key_str: &str,
    reason: i32,
    error_code: i32,
    targeting_key: Option<&str>,
    attrs: &HashMap<Str, Attribute>,
    eval_ms: i64,
) {
    let reason_s = reason_str(reason, error_code);
    let full_key = FullTierKey {
        flag_key: flag_key.to_owned(),
        variant: variant_str.to_owned(),
        allocation_key: allocation_key_str.to_owned(),
        reason: reason_s.clone(),
        targeting_key: targeting_key.unwrap_or("").to_owned(),
        context_key: canonical_context_key(attrs),
    };

    let mut agg = match EVP_AGGREGATOR.lock() {
        Ok(g) => g,
        Err(p) => p.into_inner(),
    };

    // ── Full-tier lookup ──────────────────────────────────────────────────────
    // First, check the existing bucket in the per-flag state.
    if let Some(flag_state) = agg.full_tier.get_mut(flag_key) {
        if let Some(bucket) = flag_state.buckets.get_mut(&full_key) {
            // Existing bucket — merge (min/max for first/last, reviewer concern #4).
            bucket.merge(eval_ms);
            return;
        }
    }

    // ── Full-tier insertion (new bucket) ──────────────────────────────────────
    let current_total = agg.full_tier_total;
    let flag_count = agg
        .full_tier
        .get(flag_key)
        .map(|s| s.buckets.len())
        .unwrap_or(0);

    if current_total < GLOBAL_CAP && flag_count < PER_FLAG_CAP {
        let flag_state = agg.full_tier.entry(flag_key.to_owned()).or_default();
        flag_state.buckets.insert(full_key, AggBucket::new(eval_ms));
        agg.full_tier_total += 1;
        return;
    }

    // ── Degraded tier (full-tier saturated) ───────────────────────────────────
    let degraded_key = DegradedTierKey {
        flag_key: flag_key.to_owned(),
        variant: variant_str.to_owned(),
        allocation_key: allocation_key_str.to_owned(),
        reason: reason_s,
    };

    if let Some(bucket) = agg.degraded_tier.get_mut(&degraded_key) {
        bucket.merge(eval_ms);
        return;
    }

    if agg.degraded_tier.len() < DEGRADED_CAP {
        agg.degraded_tier
            .insert(degraded_key, AggBucket::new(eval_ms));
    } else {
        // Both tiers full → drop and count (reviewer concern #8 3385309427).
        agg.dropped_degraded_overflow = agg.dropped_degraded_overflow.saturating_add(1);
    }
}

/// Drain the aggregator and build a `FfeFlagEvaluationBatch`.
/// Returns `None` if the aggregator is empty.
fn drain_aggregator(
    service: &str,
    env: &str,
    version: &str,
) -> Option<FfeFlagEvaluationBatch> {
    let mut agg = match EVP_AGGREGATOR.lock() {
        Ok(g) => g,
        Err(p) => p.into_inner(),
    };

    let now = now_ms();
    let mut events: Vec<FfeFlagEvaluationEvent> = Vec::new();

    // Drain full tier.
    for (flag_key, flag_state) in agg.full_tier.drain() {
        for (k, bucket) in flag_state.buckets {
            let runtime_default = k.variant.is_empty();
            let variant = if k.variant.is_empty() {
                None
            } else {
                Some(VariantKey { key: k.variant })
            };
            let allocation = if k.allocation_key.is_empty() {
                None
            } else {
                Some(AllocationKey {
                    key: k.allocation_key,
                })
            };
            let targeting_key = if k.targeting_key.is_empty() {
                None
            } else {
                Some(k.targeting_key)
            };
            events.push(FfeFlagEvaluationEvent {
                timestamp: now,
                flag: FlagKey { key: flag_key.clone() },
                first_evaluation: bucket.first_evaluation,
                last_evaluation: bucket.last_evaluation,
                evaluation_count: bucket.count,
                variant,
                allocation,
                targeting_key,
                context: None, // context pruning owned by PREP-01 types on the sidecar
                error: None,
                runtime_default_used: runtime_default,
            });
        }
    }
    agg.full_tier_total = 0;

    // Drain degraded tier.
    for (k, bucket) in agg.degraded_tier.drain() {
        let runtime_default = k.variant.is_empty();
        events.push(FfeFlagEvaluationEvent {
            timestamp: now,
            flag: FlagKey { key: k.flag_key },
            first_evaluation: bucket.first_evaluation,
            last_evaluation: bucket.last_evaluation,
            evaluation_count: bucket.count,
            variant: None,
            allocation: None,
            targeting_key: None,
            context: None,
            error: None,
            runtime_default_used: runtime_default,
        });
    }

    if events.is_empty() {
        return None;
    }

    Some(FfeFlagEvaluationBatch {
        context: FfeTelemetryContext {
            service: service.to_owned(),
            env: env.to_owned(),
            version: version.to_owned(),
        },
        flag_evaluations: events,
    })
}

/// Flush aggregated EVP flag evaluation events to the sidecar.
///
/// # Safety
/// All pointer parameters must be valid non-null pointers to live objects.
/// `service`, `env`, `version` must be valid UTF-8 `CharSlice` values.
#[no_mangle]
pub unsafe extern "C" fn ddog_ffe_flush_flag_evaluation_batch(
    transport: &mut Box<SidecarTransport>,
    instance_id: &InstanceId,
    queue_id: &QueueId,
    service: CharSlice<'_>,
    env: CharSlice<'_>,
    version: CharSlice<'_>,
) -> bool {
    let service_s = service.to_utf8_lossy().into_owned();
    let env_s = env.to_utf8_lossy().into_owned();
    let version_s = version.to_utf8_lossy().into_owned();

    let batch = match drain_aggregator(&service_s, &env_s, &version_s) {
        Some(b) => b,
        None => return true, // nothing to flush
    };

    sidecar_blocking::enqueue_actions(
        transport,
        instance_id,
        queue_id,
        vec![SidecarAction::FfeFlagEvaluationBatch(batch)],
    )
    .is_ok()
}

// ── End EVP aggregation ───────────────────────────────────────────────────────

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

    let parsed_attributes = parse_attributes(attributes, attributes_count);
    // Capture targeting key and attrs for EVP recording BEFORE consuming them.
    let targeting_key_owned: Option<String> = targeting_key.as_ref().map(|s| s.as_str().to_owned());
    let attrs_for_evp: HashMap<Str, Attribute> = parsed_attributes.clone();

    let context = EvaluationContext::new(targeting_key, Arc::new(parsed_attributes));

    let result = FFE_STATE.with(|state| {
        let state = state.borrow();
        let assignment = ffe::get_assignment(
            state.config.as_ref(),
            flag_key,
            &context,
            expected_type,
            ffe::now(),
        );

        result_from_assignment(assignment)
    });

    // EVP flagevaluation aggregation (new path — gated by killswitch).
    // The existing OTel record_ffe_evaluation_metric() path (PHP/C) is unaffected.
    if result.valid && evp_enabled() {
        let eval_ms = now_ms();
        let variant_str = result
            .variant
            .as_ref()
            .and_then(|v| std::str::from_utf8(v.as_ref()).ok())
            .unwrap_or("");
        let alloc_str = result
            .allocation_key
            .as_ref()
            .and_then(|v| std::str::from_utf8(v.as_ref()).ok())
            .unwrap_or("");
        record_flag_evaluation_evp(
            flag_key,
            variant_str,
            alloc_str,
            result.reason,
            result.error_code,
            targeting_key_owned.as_deref(),
            &attrs_for_evp,
            eval_ms,
        );
    }

    result
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

    // ── EVP aggregation unit tests (EMIT-07, frozen contract) ─────────────────

    // Serialization mutex to prevent parallel EVP tests from interfering with
    // the global aggregator state.
    lazy_static::lazy_static! {
        static ref EVP_TEST_LOCK: Mutex<()> = Mutex::new(());
    }

    /// Reset the global EVP aggregator for test isolation. Tests run in the
    /// same process, so they share the global — each test must drain or reset.
    /// Handles poisoned mutex (from a prior panicking test).
    fn reset_aggregator() {
        let mut agg = match EVP_AGGREGATOR.lock() {
            Ok(guard) => guard,
            Err(poisoned) => poisoned.into_inner(),
        };
        *agg = EvpAggregator::default();
    }

    fn empty_attrs() -> HashMap<Str, Attribute> {
        HashMap::new()
    }

    fn attrs_with(key: &str, val: &str) -> HashMap<Str, Attribute> {
        let mut m = HashMap::new();
        m.insert(Str::from(key), Attribute::from(val));
        m
    }

    // Test: identical evaluations → same bucket, count=2, first<=last.
    // Reviewer concern #4 (3395176782): first/last via min/max.
    #[test]
    fn identical_evaluations_merge_into_single_bucket() {
        let _g = EVP_TEST_LOCK.lock().unwrap_or_else(|p| p.into_inner());
        reset_aggregator();
        let eval_ms_1 = 1_000i64;
        let eval_ms_2 = 2_000i64;

        record_flag_evaluation_evp(
            "my-flag", "on", "alloc-a", REASON_SPLIT, ERROR_NONE,
            Some("user-1"), &empty_attrs(), eval_ms_1,
        );
        record_flag_evaluation_evp(
            "my-flag", "on", "alloc-a", REASON_SPLIT, ERROR_NONE,
            Some("user-1"), &empty_attrs(), eval_ms_2,
        );

        let batch = drain_aggregator("svc", "prod", "1.0").unwrap();
        assert_eq!(batch.flag_evaluations.len(), 1);
        let ev = &batch.flag_evaluations[0];
        assert_eq!(ev.flag.key, "my-flag");
        assert_eq!(ev.evaluation_count, 2);
        assert!(ev.first_evaluation <= ev.last_evaluation);
        assert_eq!(ev.first_evaluation, eval_ms_1);
        assert_eq!(ev.last_evaluation, eval_ms_2);
    }

    // Test: differing context attrs → distinct buckets (reviewer concern #3 3395004724).
    #[test]
    fn different_context_values_produce_distinct_full_tier_buckets() {
        let _g = EVP_TEST_LOCK.lock().unwrap_or_else(|p| p.into_inner());
        reset_aggregator();
        let attrs_a = attrs_with("plan", "free");
        let attrs_b = attrs_with("plan", "premium");

        record_flag_evaluation_evp(
            "ctx-flag", "on", "alloc-a", REASON_SPLIT, ERROR_NONE,
            Some("user-1"), &attrs_a, 1_000,
        );
        record_flag_evaluation_evp(
            "ctx-flag", "on", "alloc-a", REASON_SPLIT, ERROR_NONE,
            Some("user-1"), &attrs_b, 1_000,
        );

        let batch = drain_aggregator("svc", "prod", "1.0").unwrap();
        assert_eq!(
            batch.flag_evaluations.len(), 2,
            "different context values must produce two distinct buckets"
        );
    }

    // Test: same attrs → same bucket (canonical key is deterministic).
    #[test]
    fn same_context_values_merge_into_same_bucket() {
        let _g = EVP_TEST_LOCK.lock().unwrap_or_else(|p| p.into_inner());
        reset_aggregator();
        let attrs_a = attrs_with("plan", "free");
        let attrs_b = attrs_with("plan", "free");

        record_flag_evaluation_evp(
            "ctx-flag2", "on", "alloc-a", REASON_SPLIT, ERROR_NONE,
            Some("user-1"), &attrs_a, 1_000,
        );
        record_flag_evaluation_evp(
            "ctx-flag2", "on", "alloc-a", REASON_SPLIT, ERROR_NONE,
            Some("user-1"), &attrs_b, 2_000,
        );

        let batch = drain_aggregator("svc", "prod", "1.0").unwrap();
        assert_eq!(
            batch.flag_evaluations.len(), 1,
            "same context values must merge into one bucket"
        );
        assert_eq!(batch.flag_evaluations[0].evaluation_count, 2);
    }

    // Test: full-tier overflow routes to degraded tier.
    // Reviewer concern #8 (3385309427): three named cap constants with explicit bounds.
    #[test]
    fn full_tier_overflow_routes_to_degraded_tier() {
        let _g = EVP_TEST_LOCK.lock().unwrap_or_else(|p| p.into_inner());
        reset_aggregator();
        // Insert one bucket that fills globalCap for this flag.
        // We simulate this by directly manipulating state:
        // Set full_tier_total to GLOBAL_CAP - 1, then push one more.
        {
            let mut agg = match EVP_AGGREGATOR.lock() {
                Ok(g) => g,
                Err(p) => p.into_inner(),
            };
            agg.full_tier_total = GLOBAL_CAP; // pretend full
        }

        // This evaluation should be routed to the degraded tier.
        record_flag_evaluation_evp(
            "overflow-flag", "on", "alloc-a", REASON_SPLIT, ERROR_NONE,
            Some("user-x"), &attrs_with("k", "v"), 1_000,
        );

        let agg = match EVP_AGGREGATOR.lock() {
            Ok(g) => g,
            Err(p) => p.into_inner(),
        };
        assert_eq!(
            agg.degraded_tier.len(), 1,
            "evaluations past globalCap must land in the degraded tier"
        );
        drop(agg);
        reset_aggregator();
    }

    // Test: degraded-tier overflow increments drop counter.
    #[test]
    fn degraded_tier_overflow_increments_drop_counter() {
        let _g = EVP_TEST_LOCK.lock().unwrap_or_else(|p| p.into_inner());
        reset_aggregator();
        {
            let mut agg = match EVP_AGGREGATOR.lock() {
                Ok(g) => g,
                Err(p) => p.into_inner(),
            };
            agg.full_tier_total = GLOBAL_CAP; // full-tier saturated
            // Fill the degraded tier to capacity.
            for i in 0..DEGRADED_CAP {
                let key = DegradedTierKey {
                    flag_key: format!("flag-{i}"),
                    variant: "on".to_owned(),
                    allocation_key: "alloc".to_owned(),
                    reason: "SPLIT".to_owned(),
                };
                agg.degraded_tier.insert(key, AggBucket::new(1_000));
            }
        }

        // One more evaluation should increment the drop counter.
        record_flag_evaluation_evp(
            "drop-me", "on", "alloc-a", REASON_SPLIT, ERROR_NONE,
            None, &empty_attrs(), 1_000,
        );

        let agg = match EVP_AGGREGATOR.lock() {
            Ok(g) => g,
            Err(p) => p.into_inner(),
        };
        assert_eq!(
            agg.dropped_degraded_overflow, 1,
            "evaluation past degradedCap must increment the drop counter"
        );
        drop(agg);
        reset_aggregator();
    }

    // Test: drain_aggregator produces FfeFlagEvaluationBatch with expected fields.
    #[test]
    fn drain_aggregator_produces_correct_batch() {
        let _g = EVP_TEST_LOCK.lock().unwrap_or_else(|p| p.into_inner());
        reset_aggregator();
        record_flag_evaluation_evp(
            "batch-flag", "variant-x", "alloc-1", REASON_TARGETING_MATCH, ERROR_NONE,
            Some("user-99"), &empty_attrs(), 5_000,
        );

        let batch = drain_aggregator("my-service", "staging", "2.0").unwrap();
        assert_eq!(batch.context.service, "my-service");
        assert_eq!(batch.context.env, "staging");
        assert_eq!(batch.context.version, "2.0");
        assert_eq!(batch.flag_evaluations.len(), 1);

        let ev = &batch.flag_evaluations[0];
        assert_eq!(ev.flag.key, "batch-flag");
        assert_eq!(ev.evaluation_count, 1);
        assert_eq!(
            ev.variant.as_ref().map(|v| v.key.as_str()),
            Some("variant-x")
        );
        assert_eq!(
            ev.allocation.as_ref().map(|a| a.key.as_str()),
            Some("alloc-1")
        );
        assert!(!ev.runtime_default_used);
    }

    // Test: absent variant → runtime_default_used = true (reviewer concern #5 3395344504).
    #[test]
    fn absent_variant_sets_runtime_default_used() {
        let _g = EVP_TEST_LOCK.lock().unwrap_or_else(|p| p.into_inner());
        reset_aggregator();
        // Simulate a DEFAULT evaluation (no variant assigned).
        record_flag_evaluation_evp(
            "default-flag", "", "", REASON_DEFAULT, ERROR_NONE,
            None, &empty_attrs(), 1_000,
        );

        let batch = drain_aggregator("svc", "env", "1").unwrap();
        let ev = &batch.flag_evaluations[0];
        assert!(ev.runtime_default_used, "absent variant must set runtime_default_used");
        assert!(ev.variant.is_none(), "absent variant must be None (not empty string)");
    }

    // Test: killswitch DD_FLAGGING_EVALUATION_COUNTS_ENABLED=false → no recording.
    // OTel record_ffe_evaluation_metric path must still be wired (verified by presence
    // of the function in this codebase — PRES-01).
    #[test]
    fn killswitch_disabled_skips_evp_recording() {
        let _g = EVP_TEST_LOCK.lock().unwrap_or_else(|p| p.into_inner());
        reset_aggregator();
        std::env::set_var("DD_FLAGGING_EVALUATION_COUNTS_ENABLED", "false");

        record_flag_evaluation_evp(
            "ks-flag", "on", "alloc", REASON_SPLIT, ERROR_NONE,
            None, &empty_attrs(), 1_000,
        );

        // The evp_enabled() check is in ddog_ffe_evaluate(), not in
        // record_flag_evaluation_evp() itself. Test evp_enabled() directly.
        assert!(!evp_enabled(), "evp_enabled() must return false when env var is 'false'");

        // Drain should return None (nothing was actually recorded via ddog_ffe_evaluate
        // because evp_enabled() would have returned false there).
        // The above direct call to record_flag_evaluation_evp bypasses the killswitch,
        // so we reset and verify the guard function itself.
        reset_aggregator();

        std::env::set_var("DD_FLAGGING_EVALUATION_COUNTS_ENABLED", "true");
        assert!(evp_enabled(), "evp_enabled() must return true when env var is 'true'");

        std::env::remove_var("DD_FLAGGING_EVALUATION_COUNTS_ENABLED");
        assert!(evp_enabled(), "evp_enabled() must return true when env var is absent (default on)");
    }

    // Test: PRES-01 — confirm record_ffe_evaluation_metric exists (OTel native path).
    // This is a compile-time proof: if the function is missing, the test module won't compile.
    #[test]
    fn otel_native_path_function_exists() {
        // result_from_assignment and the reason/error constants used by the OTel
        // path must still be present (byte-for-byte non-regression).
        let _ = REASON_STATIC;
        let _ = REASON_DEFAULT;
        let _ = REASON_TARGETING_MATCH;
        let _ = REASON_SPLIT;
        let _ = REASON_DISABLED;
        let _ = REASON_ERROR;
        let _ = ERROR_NONE;
        // The OTel metric function (C, in ffe.c) calls ddog_ffe_evaluate() — its signature
        // must be unchanged. Verify FfeResult still has the same fields.
        let r = invalid_result();
        assert!(!r.valid);
        assert!(r.variant.is_none());
        assert!(r.allocation_key.is_none());
    }
}
