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

/// Holds both the FFE configuration and a monotonic version counter atomically
/// behind a single Mutex. This avoids the race where another thread could
/// observe `config` updated but `version` still stale (or vice-versa).
///
/// A `RwLock` would be more appropriate here (many readers via `ddog_ffe_evaluate`,
/// rare writer via `store_config`), but on NTS PHP builds — the v1 target — each
/// PHP process is single-threaded, so contention is not a practical concern.
///
/// The `version` field is a monotonically-increasing counter bumped on every
/// `store_config` / `clear_config` call. Consumers compare against their last
/// observed value instead of consuming a `changed` flag, so multiple independent
/// subscribers can detect transitions without racing each other.
///
/// NOTE: On ZTS builds (out of scope for FFE v1 — see PROJECT.md) per-thread
/// Remote Config receivers carry their own (service, env, version) target tuples
/// and would each expect their own FFE state. ZTS support requires moving this
/// state into `DDTRACE_G()` thread-local globals (tracked as a follow-up phase).
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

/// Called by remote_config when a new FFE configuration arrives via RC.
pub fn store_config(config: Configuration) {
    if let Ok(mut state) = FFE_STATE.lock() {
        state.config = Some(config);
        state.version = state.version.wrapping_add(1);
    }
}

/// Called by remote_config when an FFE configuration is removed.
pub fn clear_config() {
    if let Ok(mut state) = FFE_STATE.lock() {
        state.config = None;
        state.version = state.version.wrapping_add(1);
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

/// Return the current FFE config version counter.
///
/// Bumped on every `store_config` / `clear_config`. Consumers track their last
/// observed value and detect changes by comparing. Multiple independent
/// subscribers can detect transitions without racing (unlike a drain-on-read
/// `changed` flag where only the first reader sees the transition).
///
/// Wraps on overflow; in practice the counter is a `u64` and will not wrap
/// within a reasonable process lifetime.
#[no_mangle]
pub extern "C" fn ddog_ffe_config_version() -> u64 {
    FFE_STATE.lock().map(|s| s.version).unwrap_or(0)
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

// ---------------------------------------------------------------------------
// ExposureState -- exposure dedup cache and batch buffer (persists across PHP requests)
// ---------------------------------------------------------------------------

struct ServiceContext {
    service: String,
    env: String,
    version: String,
}

struct ExposureState {
    /// LRU dedup cache: key = "flag_key\0allocation_key\0targeting_key", value = variant_key.
    /// Capacity: 65536 entries (EXPO-02). Uses null byte separator to avoid collision
    /// with key content that might contain ':' characters.
    dedup_cache: LruCache<String, String>,
    /// Buffered exposure event JSON strings, capped at 1000 (matches Ruby/Python).
    batch_buffer: Vec<String>,
    /// Service context set once at init (DD_SERVICE, DD_ENV, DD_VERSION).
    service_context: Option<ServiceContext>,
}

lazy_static::lazy_static! {
    static ref EXPOSURE_STATE: Mutex<ExposureState> = Mutex::new(ExposureState {
        dedup_cache: LruCache::new(NonZeroUsize::new(65536).unwrap()),
        batch_buffer: Vec::new(),
        service_context: None,
    });
}

/// Maximum number of events in the batch buffer before new events are dropped.
const EXPOSURE_BATCH_LIMIT: usize = 1000;

// ---------------------------------------------------------------------------
// Exposure pipeline -- extern "C" functions
// ---------------------------------------------------------------------------

/// Set the service context (DD_SERVICE, DD_ENV, DD_VERSION) for exposure payloads.
/// Called once during provider initialization. Values are stored and reused for all
/// subsequent batch payloads.
///
/// # Safety
/// All parameters must be valid null-terminated C strings or null.
#[no_mangle]
pub unsafe extern "C" fn ddog_ffe_set_service_context(
    service: *const c_char,
    env: *const c_char,
    version: *const c_char,
) {
    let svc = if service.is_null() {
        String::new()
    } else {
        CStr::from_ptr(service).to_str().unwrap_or("").to_owned()
    };
    let env_str = if env.is_null() {
        String::new()
    } else {
        CStr::from_ptr(env).to_str().unwrap_or("").to_owned()
    };
    let ver = if version.is_null() {
        String::new()
    } else {
        CStr::from_ptr(version).to_str().unwrap_or("").to_owned()
    };

    if let Ok(mut state) = EXPOSURE_STATE.lock() {
        state.service_context = Some(ServiceContext {
            service: svc,
            env: env_str,
            version: ver,
        });
    }
}

/// Enqueue an exposure event for dedup and batched delivery.
///
/// Dedup key: "flag_key\0allocation_key\0targeting_key" (per D-04).
/// If the same composite key with the same variant is already in the cache, the event
/// is deduplicated (returns false). If the variant changed for the same key, the new
/// exposure is sent (cache updated, returns true).
///
/// Batch buffer is capped at EXPOSURE_BATCH_LIMIT (1000). If full, new events are
/// silently dropped (per D-11) and the function returns false.
///
/// # Safety
/// - `event_json` must be a valid null-terminated C string.
/// - `flag_key`, `allocation_key`, `variant_key` must be valid null-terminated C strings.
/// - `targeting_key` may be null.
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

    let event_str = match CStr::from_ptr(event_json).to_str() {
        Ok(s) => s.to_owned(),
        Err(_) => return false,
    };
    let flag_str = match CStr::from_ptr(flag_key).to_str() {
        Ok(s) => s,
        Err(_) => return false,
    };
    let alloc_str = if allocation_key.is_null() {
        ""
    } else {
        match CStr::from_ptr(allocation_key).to_str() {
            Ok(s) => s,
            Err(_) => return false,
        }
    };
    let target_str = if targeting_key.is_null() {
        ""
    } else {
        match CStr::from_ptr(targeting_key).to_str() {
            Ok(s) => s,
            Err(_) => return false,
        }
    };
    let variant_str = match CStr::from_ptr(variant_key).to_str() {
        Ok(s) => s.to_owned(),
        Err(_) => return false,
    };

    // Build dedup key using null byte separator (avoids collision with ':' in key content)
    let dedup_key = format!("{}\0{}\0{}", flag_str, alloc_str, target_str);

    if let Ok(mut state) = EXPOSURE_STATE.lock() {
        // Dedup check: same key + same variant = duplicate, skip
        if let Some(cached_variant) = state.dedup_cache.get(&dedup_key) {
            if *cached_variant == variant_str {
                return false; // duplicate, not enqueued
            }
        }

        // Cache this exposure (updates LRU position if key exists with different variant)
        state.dedup_cache.put(dedup_key, variant_str);

        // Buffer the event JSON (drop if buffer full per D-11)
        if state.batch_buffer.len() < EXPOSURE_BATCH_LIMIT {
            state.batch_buffer.push(event_str);
            true
        } else {
            false // buffer full, event dropped
        }
    } else {
        false
    }
}

/// Flush all buffered exposure events as a batched JSON payload.
///
/// Returns a JSON string containing the batch payload with service context and all
/// buffered events. The batch buffer is cleared after flushing.
///
/// In production, the sidecar's periodic flush loop calls this function and sends the
/// result to the agent EVP proxy.
///
/// Returns `CharSlice::default()` (null ptr, zero len) if:
/// - The batch buffer is empty (nothing to flush)
/// - The mutex is poisoned
///
/// # Memory
/// The returned CharSlice points to a heap-allocated string that must be freed
/// with `ddog_ffe_free_flush_result()`.
#[no_mangle]
pub extern "C" fn ddog_ffe_flush_exposures() -> CharSlice<'static> {
    if let Ok(mut state) = EXPOSURE_STATE.lock() {
        if state.batch_buffer.is_empty() {
            return CharSlice::default();
        }

        let events: Vec<String> = state.batch_buffer.drain(..).collect();

        // Build batched payload matching Ruby/Python format
        let context_json = match &state.service_context {
            Some(ctx) => format!(
                r#"{{"service":"{}","env":"{}","version":"{}"}}"#,
                escape_json_string(&ctx.service),
                escape_json_string(&ctx.env),
                escape_json_string(&ctx.version),
            ),
            None => r#"{"service":"","env":"","version":""}"#.to_owned(),
        };

        // Events are already JSON strings from PHP side, join as array elements
        let events_json = events.join(",");
        let payload = format!(
            r#"{{"context":{},"exposures":[{}]}}"#,
            context_json, events_json
        );

        // Leak the string so the CharSlice is valid after return.
        // Caller must free via ddog_ffe_free_flush_result().
        let leaked = payload.into_boxed_str();
        let ptr = leaked.as_ptr();
        let len = leaked.len();
        std::mem::forget(leaked);

        // Safety: `ptr` points to a leaked Box<str> of `len` bytes. The allocation
        // outlives this return. Caller must free via ddog_ffe_free_flush_result().
        unsafe { CharSlice::from_raw_parts(ptr as *const c_char, len) }
    } else {
        CharSlice::default()
    }
}

/// Free a flush result previously returned by `ddog_ffe_flush_exposures`.
///
/// # Safety
/// `slice` must be a CharSlice previously returned by `ddog_ffe_flush_exposures`,
/// or a default (null) CharSlice.
#[no_mangle]
pub unsafe extern "C" fn ddog_ffe_free_flush_result(slice: CharSlice<'static>) {
    use libdd_common_ffi::slice::AsBytes;
    let bytes = slice.as_bytes();
    let len = bytes.len();
    let ptr = bytes.as_ptr() as *mut u8;
    if !ptr.is_null() && len > 0 {
        // Reconstruct the boxed str from the leaked pointer
        let _ = Box::from_raw(std::slice::from_raw_parts_mut(ptr, len) as *mut [u8]);
    }
}

/// Simple JSON string escaping for service context values.
/// Escapes backslash, double quote, and control characters.
fn escape_json_string(s: &str) -> String {
    let mut result = String::with_capacity(s.len());
    for ch in s.chars() {
        match ch {
            '\\' => result.push_str("\\\\"),
            '"' => result.push_str("\\\""),
            '\n' => result.push_str("\\n"),
            '\r' => result.push_str("\\r"),
            '\t' => result.push_str("\\t"),
            c if c.is_control() => {
                result.push_str(&format!("\\u{:04x}", c as u32));
            }
            c => result.push(c),
        }
    }
    result
}

/// Reset exposure state for testing. Clears the dedup cache, batch buffer,
/// and service context.
#[no_mangle]
pub extern "C" fn ddog_ffe_reset_exposure_state() {
    if let Ok(mut state) = EXPOSURE_STATE.lock() {
        state.dedup_cache.clear();
        state.batch_buffer.clear();
        state.service_context = None;
    }
}
