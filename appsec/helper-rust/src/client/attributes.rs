use std::collections::HashMap;
use std::io::Write;

use anyhow::Context;
use base64::Engine;
use flate2::write::GzEncoder;
use flate2::Compression;
use libddwaf::object::{Keyed, WafObject, WafObjectType};

use crate::{client::log::warning, telemetry};

#[derive(Debug)]
pub struct CollectedWafAttributes {
    max_plain_schema_allowed: usize,
    max_schema_size: usize,
    meta: HashMap<String, String>,
    metrics: HashMap<String, f64>,
}

impl CollectedWafAttributes {
    pub fn new(max_plain_schema_allowed: usize, max_schema_size: usize) -> Self {
        Self {
            max_plain_schema_allowed,
            max_schema_size,
            meta: HashMap::new(),
            metrics: HashMap::new(),
        }
    }
    pub fn add_attribute(&mut self, attr_kv: &Keyed<WafObject>) {
        if let Err(e) = self.try_add_attribute(attr_kv) {
            warning!("Failed to add attribute: {}", e);
        }
    }

    fn try_add_attribute(&mut self, attr_kv: &Keyed<WafObject>) -> anyhow::Result<()> {
        let key = attr_kv
            .key_str()
            .map_err(|e| anyhow::anyhow!("Attribute key is not valid UTF-8: {}", e))?;
        let value = attr_kv.value();

        if key.starts_with("_dd.appsec.s.") {
            self.add_schema_attribute(key, value)
        } else {
            self.add_regular_attribute(key, value)
        }
    }

    fn add_schema_attribute(&mut self, key: &str, value: &WafObject) -> anyhow::Result<()> {
        let mut json_derivative =
            serde_json::to_string(value).with_context(|| "Failed to serialize schema value")?;

        if json_derivative.len() > self.max_plain_schema_allowed {
            let compressed = compress(&json_derivative)?;
            json_derivative = base64::engine::general_purpose::STANDARD.encode(&compressed);
        }

        if json_derivative.len() > self.max_schema_size {
            anyhow::bail!(
                "Schema for key {} is too large ({} bytes > {} max)",
                key,
                json_derivative.len(),
                self.max_schema_size
            );
        }

        self.meta.insert(key.to_owned(), json_derivative);
        Ok(())
    }

    fn add_regular_attribute(&mut self, key: &str, value: &WafObject) -> anyhow::Result<()> {
        match value.object_type() {
            WafObjectType::Signed => {
                let val = value
                    .as_type::<libddwaf::object::WafSigned>()
                    .unwrap()
                    .value() as f64;
                self.metrics.insert(key.to_owned(), val);
            }
            WafObjectType::Unsigned => {
                let val = value
                    .as_type::<libddwaf::object::WafUnsigned>()
                    .unwrap()
                    .value() as f64;
                self.metrics.insert(key.to_owned(), val);
            }
            WafObjectType::Float => {
                let val = value
                    .as_type::<libddwaf::object::WafFloat>()
                    .unwrap()
                    .value();
                self.metrics.insert(key.to_owned(), val);
            }
            WafObjectType::String => {
                let val = value.as_type::<libddwaf::object::WafString>().unwrap();
                let s = val
                    .as_str()
                    .with_context(|| "String value is not valid UTF-8")?;
                self.meta.insert(key.to_owned(), s.to_owned());
            }
            other => {
                anyhow::bail!("Unsupported attribute type: {:?}", other);
            }
        }
        Ok(())
    }
}

impl telemetry::SpanMetricsGenerator for CollectedWafAttributes {
    fn generate_span_metrics(&'_ self, submitter: &mut dyn telemetry::SpanMetricsSubmitter) {
        for (key, value) in &self.meta {
            submitter.submit_meta_dyn_key(key.clone(), value.clone());
        }

        for (key, value) in &self.metrics {
            submitter.submit_metric_dyn_key(key.clone(), *value);
        }
    }
}

fn compress(data: &str) -> anyhow::Result<Vec<u8>> {
    if data.is_empty() {
        anyhow::bail!("Cannot compress empty data");
    }

    let mut encoder = GzEncoder::new(Vec::new(), Compression::default());
    encoder
        .write_all(data.as_bytes())
        .with_context(|| "Failed to write data to gzip encoder")?;
    encoder
        .finish()
        .with_context(|| "Failed to finish gzip compression")
}

#[cfg(test)]
mod tests {
    use super::*;
    use base64::Engine;
    use flate2::read::GzDecoder;
    use libddwaf::waf_map;
    use std::io::Read;

    #[test]
    fn test_add_numeric_signed_attribute() {
        let mut attrs = CollectedWafAttributes::new(260, 25000);
        let map = waf_map! {("test.metric", -42i64)};
        let attr = map.into_iter().next().unwrap();

        attrs.add_attribute(&attr);

        assert_eq!(attrs.metrics.len(), 1);
        assert_eq!(attrs.metrics.get("test.metric"), Some(&-42.0));
        assert_eq!(attrs.meta.len(), 0);
    }

    #[test]
    fn test_add_numeric_unsigned_attribute() {
        let mut attrs = CollectedWafAttributes::new(260, 25000);
        let map = waf_map! {("test.metric", 100u64)};
        let attr = map.into_iter().next().unwrap();

        attrs.add_attribute(&attr);

        assert_eq!(attrs.metrics.len(), 1);
        assert_eq!(attrs.metrics.get("test.metric"), Some(&100.0));
        assert_eq!(attrs.meta.len(), 0);
    }

    #[test]
    fn test_add_numeric_float_attribute() {
        let mut attrs = CollectedWafAttributes::new(260, 25000);
        let map = waf_map! {("test.metric", 3.14)};
        let attr = map.into_iter().next().unwrap();

        attrs.add_attribute(&attr);

        assert_eq!(attrs.metrics.len(), 1);
        assert_eq!(attrs.metrics.get("test.metric"), Some(&3.14));
        assert_eq!(attrs.meta.len(), 0);
    }

    #[test]
    fn test_add_string_attribute() {
        let mut attrs = CollectedWafAttributes::new(260, 25000);
        let map = waf_map! {("test.meta", "value")};
        let attr = map.into_iter().next().unwrap();

        attrs.add_attribute(&attr);

        assert_eq!(attrs.meta.len(), 1);
        assert_eq!(attrs.meta.get("test.meta"), Some(&"value".to_string()));
        assert_eq!(attrs.metrics.len(), 0);
    }

    #[test]
    fn test_add_small_schema_attribute() {
        let mut attrs = CollectedWafAttributes::new(260, 25000);
        let map = waf_map! {("_dd.appsec.s.req.body", "small")};
        let attr = map.into_iter().next().unwrap();

        attrs.add_attribute(&attr);

        assert_eq!(attrs.meta.len(), 1);
        let stored = attrs.meta.get("_dd.appsec.s.req.body").unwrap();
        assert_eq!(stored, "\"small\"");
    }

    #[test]
    fn test_add_large_schema_attribute_gets_compressed() {
        let mut attrs = CollectedWafAttributes::new(260, 25000);
        let large_string = "x".repeat(300);
        let large_str = large_string.as_str();
        let map = waf_map! {("_dd.appsec.s.res.body", large_str)};
        let attr = map.into_iter().next().unwrap();

        attrs.add_attribute(&attr);

        assert_eq!(attrs.meta.len(), 1);
        let stored = attrs.meta.get("_dd.appsec.s.res.body").unwrap();

        let decoded = base64::engine::general_purpose::STANDARD
            .decode(stored)
            .expect("Should be base64 encoded");
        let mut decoder = GzDecoder::new(&decoded[..]);
        let mut decompressed = String::new();
        decoder
            .read_to_string(&mut decompressed)
            .expect("Should be gzip compressed");

        let expected_json = format!("\"{}\"", large_string);
        assert_eq!(decompressed, expected_json);
    }

    #[test]
    fn test_schema_attribute_too_large_gets_rejected() {
        let mut attrs = CollectedWafAttributes::new(260, 10);
        let large_string = "x".repeat(300);
        let large_str = large_string.as_str();
        let map = waf_map! {("_dd.appsec.s.huge", large_str)};
        let attr = map.into_iter().next().unwrap();

        attrs.add_attribute(&attr);

        assert_eq!(attrs.meta.len(), 0);
    }

    #[test]
    fn test_generate_metrics() {
        let mut attrs = CollectedWafAttributes::new(260, 25000);

        let map = waf_map! {("metric1", 10i64)};
        attrs.add_attribute(&map.into_iter().next().unwrap());

        let map = waf_map! {("metric2", 20.5)};
        attrs.add_attribute(&map.into_iter().next().unwrap());

        let map = waf_map! {("meta1", "value1")};
        attrs.add_attribute(&map.into_iter().next().unwrap());

        let map = waf_map! {("_dd.appsec.s.test", "schema")};
        attrs.add_attribute(&map.into_iter().next().unwrap());

        use crate::telemetry::{SpanMetricsGenerator, SpanMetricsSubmitter};

        struct TestSubmitter {
            meta: HashMap<String, String>,
            metrics: HashMap<String, f64>,
        }
        impl SpanMetricsSubmitter for TestSubmitter {
            fn submit_metric(&mut self, _key: crate::telemetry::SpanMetricName, _value: f64) {}
            fn submit_meta(&mut self, _key: crate::telemetry::SpanMetaName, _value: String) {}
            fn submit_meta_dyn_key(&mut self, key: String, value: String) {
                self.meta.insert(key, value);
            }
            fn submit_metric_dyn_key(&mut self, key: String, value: f64) {
                self.metrics.insert(key, value);
            }
        }

        let mut submitter = TestSubmitter {
            meta: HashMap::new(),
            metrics: HashMap::new(),
        };

        attrs.generate_span_metrics(&mut submitter);

        assert_eq!(submitter.metrics.len(), 2);
        assert_eq!(submitter.metrics.get("metric1"), Some(&10.0));
        assert_eq!(submitter.metrics.get("metric2"), Some(&20.5));

        assert_eq!(submitter.meta.len(), 2);
        assert_eq!(submitter.meta.get("meta1"), Some(&"value1".to_string()));
        assert_eq!(
            submitter.meta.get("_dd.appsec.s.test"),
            Some(&"\"schema\"".to_string())
        );
    }
}
