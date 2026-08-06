// Copyright 2026-Present Datadog, Inc. https://www.datadoghq.com/
// SPDX-License-Identifier: Apache-2.0

use super::{ProcessIdentity, ProcessIdentityRef, ThreadContext, ThreadContextRead};
use crate::bindings::datadog_php_profiling_get_otel_thread_context;
use libdd_library_config::otel_process_ctx::ProcessContextSelfReader;
use libdd_trace_protobuf::opentelemetry::proto::common::v1::{any_value, KeyValue, ProcessContext};

const THREAD_CONTEXT_HEADER_SIZE: usize = 28;
const MAX_THREAD_ATTRIBUTES_SIZE: usize = 612;
const THREADLOCAL_ATTRIBUTE_KEY_MAP: &str = "threadlocal.attribute_key_map";

#[derive(Default)]
struct ResourceOffsets {
    service_name: Option<usize>,
    service_version: Option<usize>,
    deployment_environment_name: Option<usize>,
    service_instance_id: Option<usize>,
}

#[derive(Default)]
struct ExtraAttributeOffsets {
    process_tags: Option<usize>,
}

#[derive(Default)]
struct ThreadAttributeOffsets {
    key_count: usize,
    local_root_span_id: Option<u8>,
    service_name: Option<u8>,
    service_version: Option<u8>,
    deployment_environment_name: Option<u8>,
    thread_id: Option<u8>,
}

#[derive(Default)]
struct ProcessContextOffsets {
    resource: ResourceOffsets,
    extra: ExtraAttributeOffsets,
    thread: ThreadAttributeOffsets,
}

struct CachedProcessContext {
    context: ProcessContext,
    offsets: ProcessContextOffsets,
}

impl Default for CachedProcessContext {
    fn default() -> Self {
        Self::new(ProcessContext::default())
    }
}

impl CachedProcessContext {
    fn new(context: ProcessContext) -> Self {
        let offsets = ProcessContextOffsets::from_context(&context);
        Self { context, offsets }
    }

    fn resource_string(&self, offset: Option<usize>) -> Option<&str> {
        offset
            .and_then(|offset| self.context.resource.as_ref()?.attributes.get(offset))
            .and_then(string_value)
    }

    fn extra_string(&self, offset: Option<usize>) -> Option<&str> {
        offset
            .and_then(|offset| self.context.extra_attributes.get(offset))
            .and_then(string_value)
    }

    fn identity(&self) -> ProcessIdentityRef<'_> {
        ProcessIdentityRef {
            service: self.resource_string(self.offsets.resource.service_name),
            environment: self.resource_string(self.offsets.resource.deployment_environment_name),
            version: self.resource_string(self.offsets.resource.service_version),
        }
    }
}

fn string_value(attribute: &KeyValue) -> Option<&str> {
    let any_value::Value::StringValue(value) = attribute.value.as_ref()?.value.as_ref()? else {
        return None;
    };
    (!value.is_empty()).then_some(value.as_str())
}

impl ProcessContextOffsets {
    fn from_context(context: &ProcessContext) -> Self {
        let mut offsets = Self::default();

        if let Some(resource) = context.resource.as_ref() {
            for (index, attribute) in resource.attributes.iter().enumerate() {
                match attribute.key.as_str() {
                    "service.name" => offsets.resource.service_name = Some(index),
                    "service.version" => offsets.resource.service_version = Some(index),
                    "deployment.environment.name" => {
                        offsets.resource.deployment_environment_name = Some(index);
                    }
                    "service.instance.id" => offsets.resource.service_instance_id = Some(index),
                    _ => {}
                }
            }
        }

        for (index, attribute) in context.extra_attributes.iter().enumerate() {
            match attribute.key.as_str() {
                "datadog.process_tags" => offsets.extra.process_tags = Some(index),
                THREADLOCAL_ATTRIBUTE_KEY_MAP => {
                    offsets.thread = ThreadAttributeOffsets::from_attribute(attribute);
                }
                _ => {}
            }
        }

        offsets
    }
}

impl ThreadAttributeOffsets {
    fn from_attribute(attribute: &KeyValue) -> Self {
        let Some(any_value::Value::ArrayValue(key_map)) = attribute
            .value
            .as_ref()
            .and_then(|value| value.value.as_ref())
        else {
            return Self::default();
        };

        let mut offsets = Self {
            // Thread Context key indices are u8, so entries beyond this cannot
            // be referenced by the v1 record.
            key_count: key_map.values.len().min(u8::MAX as usize + 1),
            ..Self::default()
        };
        for (index, key) in key_map.values.iter().enumerate() {
            let Ok(index) = u8::try_from(index) else {
                break;
            };
            let Some(any_value::Value::StringValue(key)) = key.value.as_ref() else {
                continue;
            };
            match key.as_str() {
                "datadog.local_root_span_id" => offsets.local_root_span_id = Some(index),
                "service.name" => offsets.service_name = Some(index),
                "service.version" => offsets.service_version = Some(index),
                "deployment.environment.name" => {
                    offsets.deployment_environment_name = Some(index);
                }
                "thread.id" => offsets.thread_id = Some(index),
                _ => {}
            }
        }
        offsets
    }
}

/// Per-PHP-thread cache of the decoded OTel Process Context.
///
/// The reader is deliberately short-lived. A refresh discovers the current
/// mapping, decodes it, and immediately closes the reader's copy pipe.
pub(crate) struct ProcessContextCache {
    context: Option<CachedProcessContext>,
    /// Consecutive samples that observed an unknown key index. Recovery is
    /// attempted at powers of two and suppressed once this saturates.
    unknown_index_observations: u8,
}

impl ProcessContextCache {
    pub(crate) const fn new() -> Self {
        Self {
            context: None,
            unknown_index_observations: 0,
        }
    }

    pub(crate) fn reset(&mut self) {
        *self = Self::new();
    }

    /// Reads Process Context on this PHP thread's first request. A failed read
    /// installs an empty context so later requests do not repeat discovery.
    pub(crate) fn initialize(&mut self) {
        if self.context.is_some() {
            return;
        }
        if self.read_process_context().is_err() {
            self.context = Some(CachedProcessContext::default());
        }
    }

    fn read_process_context(&mut self) -> std::io::Result<()> {
        let result = ProcessContextSelfReader::new().and_then(|reader| reader.read());
        match result {
            Ok(context) => {
                self.context = Some(CachedProcessContext::new(context));
                Ok(())
            }
            Err(error) => Err(error),
        }
    }

    fn unknown_index_refresh_due(&mut self) -> bool {
        self.unknown_index_observations = self.unknown_index_observations.saturating_add(1);
        self.unknown_index_observations.is_power_of_two()
    }

    /// Cold relative to sampling. With the current fixed key map, this is only
    /// expected while restoring a cache invalidated before fork.
    #[cold]
    #[inline(never)]
    fn refresh(&mut self) -> bool {
        if !self.unknown_index_refresh_due() {
            return false;
        }
        if self.read_process_context().is_err() {
            return false;
        }

        self.unknown_index_observations = 0;
        true
    }

    fn decode_thread_attributes(
        &self,
        attributes: &[u8],
        defaults: ProcessIdentityRef<'_>,
    ) -> (ThreadContext, bool) {
        let offsets = self.context.as_ref().map(|cached| &cached.offsets.thread);
        let mut context = ThreadContext::default();
        let mut unknown_index = false;
        let mut offset = 0;

        while offset + 2 <= attributes.len() {
            let key_index = attributes[offset] as usize;
            let value_size = attributes[offset + 1] as usize;
            offset += 2;

            let Some(value_end) = offset.checked_add(value_size) else {
                break;
            };
            if value_end > attributes.len() {
                break;
            }

            let Some(offsets) = offsets else {
                unknown_index = true;
                offset = value_end;
                continue;
            };
            if key_index >= offsets.key_count {
                unknown_index = true;
                offset = value_end;
                continue;
            }

            let key_index = key_index as u8;
            let interesting = offsets.local_root_span_id == Some(key_index)
                || offsets.service_name == Some(key_index)
                || offsets.service_version == Some(key_index)
                || offsets.deployment_environment_name == Some(key_index)
                || offsets.thread_id == Some(key_index);
            if !interesting {
                offset = value_end;
                continue;
            }

            let Ok(value) = std::str::from_utf8(&attributes[offset..value_end]) else {
                offset = value_end;
                continue;
            };
            if !value.is_empty() {
                if offsets.local_root_span_id == Some(key_index) {
                    context.local_root_span_id = u64::from_str_radix(value, 16).unwrap_or_default();
                } else if offsets.service_name == Some(key_index) {
                    if Some(value) != defaults.service {
                        context.service = Some(value.to_owned());
                    }
                } else if offsets.deployment_environment_name == Some(key_index) {
                    if Some(value) != defaults.environment {
                        context.environment = Some(value.to_owned());
                    }
                } else if offsets.service_version == Some(key_index) {
                    if Some(value) != defaults.version {
                        context.version = Some(value.to_owned());
                    }
                } else if offsets.thread_id == Some(key_index) {
                    context.thread_id = value.parse().ok().filter(|id| *id >= 0);
                }
            }

            offset = value_end;
        }

        (context, unknown_index)
    }
}

// Standalone Rust tests do not run inside PHP and therefore have no TSRM
// module globals. Use Rust TLS to preserve the per-thread cache semantics
// without entering the PHP module-globals path.
#[cfg(test)]
std::thread_local! {
    static TEST_CACHE: std::cell::RefCell<ProcessContextCache> =
        const { std::cell::RefCell::new(ProcessContextCache::new()) };
}

#[cfg(test)]
fn with_cache<R>(f: impl FnOnce(&std::cell::RefCell<ProcessContextCache>) -> R) -> R {
    TEST_CACHE.with(f)
}

#[cfg(not(test))]
fn with_cache<R>(f: impl FnOnce(&std::cell::RefCell<ProcessContextCache>) -> R) -> R {
    // SAFETY: PHP module globals are initialized by GINIT and are local to the
    // current PHP thread in ZTS builds. NTS executes PHP on one thread.
    let globals = unsafe { &*crate::module_globals::get_profiler_globals() };
    f(&globals.process_context)
}

pub(crate) fn initialize() {
    with_cache(|cache| {
        if let Ok(mut cache) = cache.try_borrow_mut() {
            cache.initialize();
        }
    });
}

pub(crate) fn invalidate_before_fork() {
    with_cache(|cache| {
        if let Ok(mut cache) = cache.try_borrow_mut() {
            cache.reset();
        }
    });
}

pub(crate) fn identity() -> ProcessIdentity {
    with_cache(|cache| {
        cache
            .try_borrow()
            .ok()
            .and_then(|cache| {
                cache
                    .context
                    .as_ref()
                    .map(|cached| cached.identity().into())
            })
            .unwrap_or_default()
    })
}

pub(crate) fn process_tags() -> Option<String> {
    with_cache(|cache| {
        let cache = cache.try_borrow().ok()?;
        let cached = cache.context.as_ref()?;
        cached
            .extra_string(cached.offsets.extra.process_tags)
            .map(str::to_owned)
    })
}

pub(crate) fn runtime_id() -> Option<String> {
    with_cache(|cache| {
        let cache = cache.try_borrow().ok()?;
        let cached = cache.context.as_ref()?;
        cached
            .resource_string(cached.offsets.resource.service_instance_id)
            .map(str::to_owned)
    })
}

pub(crate) fn thread_context(defaults: ProcessIdentityRef<'_>) -> ThreadContextRead {
    let record = unsafe { datadog_php_profiling_get_otel_thread_context() }.cast::<u8>();
    if record.is_null() {
        return ThreadContextRead::Inactive;
    }

    // The record belongs to the calling PHP thread. The tracer cannot mutate
    // it while the profiler is executing on that same thread.
    let header = unsafe { std::slice::from_raw_parts(record, THREAD_CONTEXT_HEADER_SIZE) };
    if header[24] != 1 {
        return ThreadContextRead::Inactive;
    }

    let attributes_size = u16::from_ne_bytes([header[26], header[27]]) as usize;
    if attributes_size > MAX_THREAD_ATTRIBUTES_SIZE {
        return ThreadContextRead::Inactive;
    }

    let attributes = unsafe {
        std::slice::from_raw_parts(record.add(THREAD_CONTEXT_HEADER_SIZE), attributes_size)
    };
    let mut context = with_cache(|cell| {
        let Ok(cache) = cell.try_borrow() else {
            return ThreadContext::default();
        };
        let (decoded, unknown_index) = cache.decode_thread_attributes(attributes, defaults);
        if !unknown_index {
            return decoded;
        }
        drop(cache);

        let Ok(mut cache) = cell.try_borrow_mut() else {
            return decoded;
        };
        if !cache.refresh() {
            return decoded;
        }
        cache.decode_thread_attributes(attributes, defaults).0
    });
    context.span_id = u64::from_be_bytes(
        header[16..24]
            .try_into()
            .expect("the span-id field has a fixed eight-byte size"),
    );

    ThreadContextRead::Active(context)
}

#[cfg(test)]
mod tests {
    use super::*;
    use libdd_trace_protobuf::opentelemetry::proto::common::v1::{AnyValue, ArrayValue};
    use libdd_trace_protobuf::opentelemetry::proto::resource::v1::Resource;

    fn string_attribute(key: &str, value: &str) -> KeyValue {
        KeyValue {
            key: key.to_owned(),
            value: Some(AnyValue {
                value: Some(any_value::Value::StringValue(value.to_owned())),
            }),
            key_ref: 0,
        }
    }

    fn key_map(keys: &[&str]) -> KeyValue {
        KeyValue {
            key: THREADLOCAL_ATTRIBUTE_KEY_MAP.to_owned(),
            value: Some(AnyValue {
                value: Some(any_value::Value::ArrayValue(ArrayValue {
                    values: keys
                        .iter()
                        .map(|key| AnyValue {
                            value: Some(any_value::Value::StringValue((*key).to_owned())),
                        })
                        .collect(),
                })),
            }),
            key_ref: 0,
        }
    }

    fn context(resource: Vec<KeyValue>, extra_attributes: Vec<KeyValue>) -> ProcessContext {
        ProcessContext {
            resource: Some(Resource {
                attributes: resource,
                dropped_attributes_count: 0,
                entity_refs: vec![],
            }),
            extra_attributes,
        }
    }

    fn cache(context: ProcessContext) -> ProcessContextCache {
        ProcessContextCache {
            context: Some(CachedProcessContext::new(context)),
            unknown_index_observations: 0,
        }
    }

    fn encoded_attributes(attributes: &[(u8, &[u8])]) -> Vec<u8> {
        let mut encoded = Vec::new();
        for (key, value) in attributes {
            encoded.push(*key);
            encoded.push(value.len().try_into().expect("test value fits in u8"));
            encoded.extend_from_slice(value);
        }
        encoded
    }

    #[test]
    fn backs_off_unknown_index_refreshes() {
        let mut cache = ProcessContextCache::new();
        let refresh_observations: Vec<_> = (1..=300)
            .filter(|_| cache.unknown_index_refresh_due())
            .collect();

        assert_eq!(refresh_observations, [1, 2, 4, 8, 16, 32, 64, 128]);
        assert_eq!(cache.unknown_index_observations, u8::MAX);

        cache.unknown_index_observations = 0;
        assert!(cache.unknown_index_refresh_due());
        assert_eq!(cache.unknown_index_observations, 1);
    }

    #[test]
    fn caches_process_identity_runtime_id_and_tags_by_discovered_offset() {
        let cached = CachedProcessContext::new(context(
            vec![
                string_attribute("unrelated", "ignored"),
                string_attribute("service.version", "1.2.3"),
                string_attribute("service.instance.id", "runtime-id-from-publisher"),
                string_attribute("service.name", "checkout"),
                string_attribute("deployment.environment.name", "production"),
            ],
            vec![
                key_map(&[
                    "datadog.local_root_span_id",
                    "service.name",
                    "deployment.environment.name",
                    "service.version",
                    "thread.id",
                ]),
                string_attribute("datadog.process_tags", "region:us-east-1"),
            ],
        ));

        let identity = cached.identity();
        assert_eq!(identity.service, Some("checkout"));
        assert_eq!(identity.environment, Some("production"));
        assert_eq!(identity.version, Some("1.2.3"));
        assert_eq!(
            cached.resource_string(cached.offsets.resource.service_instance_id),
            Some("runtime-id-from-publisher")
        );
        assert_eq!(
            cached.extra_string(cached.offsets.extra.process_tags),
            Some("region:us-east-1")
        );
    }

    #[test]
    fn decodes_semantic_thread_attributes_from_the_process_key_map() {
        let cache = cache(context(
            vec![],
            vec![key_map(&[
                "ignored",
                "thread.id",
                "service.version",
                "datadog.local_root_span_id",
                "service.name",
                "deployment.environment.name",
            ])],
        ));
        let attributes = encoded_attributes(&[
            (0, b"not interesting"),
            (1, b"42"),
            (2, b"2.0.0"),
            (3, b"fedcba9876543210"),
            (4, b"root-service"),
            (5, b"configured-env"),
        ]);

        let (decoded, unknown_index) = cache.decode_thread_attributes(
            &attributes,
            ProcessIdentityRef {
                service: Some("configured-service"),
                environment: Some("configured-env"),
                version: Some("configured-version"),
            },
        );

        assert!(!unknown_index);
        assert_eq!(decoded.thread_id, Some(42));
        assert_eq!(decoded.local_root_span_id, 0xfedc_ba98_7654_3210);
        assert_eq!(decoded.service.as_deref(), Some("root-service"));
        assert_eq!(decoded.environment, None);
        assert_eq!(decoded.version.as_deref(), Some("2.0.0"));
    }

    #[test]
    fn malformed_empty_and_unknown_attributes_do_not_erase_defaults() {
        let cache = cache(context(
            vec![],
            vec![key_map(&[
                "datadog.local_root_span_id",
                "service.name",
                "deployment.environment.name",
                "service.version",
                "thread.id",
            ])],
        ));

        let attributes = encoded_attributes(&[
            (1, b""),
            (2, &[0xff]),
            (3, b"configured-version"),
            (4, b"-1"),
            (6, b"new-key"),
        ]);
        let (decoded, unknown_index) = cache.decode_thread_attributes(
            &attributes,
            ProcessIdentityRef {
                service: Some("configured-service"),
                environment: Some("configured-env"),
                version: Some("configured-version"),
            },
        );

        assert!(unknown_index);
        assert_eq!(decoded.service, None);
        assert_eq!(decoded.environment, None);
        assert_eq!(decoded.version, None);
        assert_eq!(decoded.thread_id, None);

        let (truncated, unknown_index) =
            cache.decode_thread_attributes(&[1, 10, b'a'], ProcessIdentityRef::default());
        assert!(!unknown_index);
        assert_eq!(truncated.service, None);
    }
}
