use crate::client::log;
use std::{
    fs::File,
    io::{BufReader, Read, Seek},
    path::Path,
};

use anyhow::Context;

const DEFAULT_RULESET: &[u8] =
    include_bytes!(concat!(env!("CARGO_MANIFEST_DIR"), "/../recommended.json"));

pub struct WafRuleset {
    doc: libddwaf::object::WafObject,
    rules_version: Option<String>,
}

impl WafRuleset {
    pub fn new(doc: libddwaf::object::WafObject, rules_version: Option<String>) -> Self {
        WafRuleset { doc, rules_version }
    }

    pub fn from_file<P: AsRef<Path>>(path: P) -> anyhow::Result<WafRuleset> {
        let mut reader = BufReader::new(File::open(&path)?);

        let rules_version = extract_rules_version(&mut reader);
        reader.rewind()?;

        let doc: libddwaf::object::WafObject =
            serde_json::from_reader(reader).with_context(|| {
                format!(
                    "Error deserializing ddwaf_object data from json file {:?}",
                    path.as_ref()
                )
            })?;

        log::info!("Loaded WAF ruleset from {:?}", path.as_ref());

        Ok(WafRuleset::new(doc, rules_version))
    }

    pub fn from_default() -> WafRuleset {
        let ruleset = WafRuleset::from_slice(DEFAULT_RULESET)
            .expect("embedded default ruleset is valid JSON");

        log::info!("Loaded embedded default WAF ruleset");

        ruleset
    }

    pub fn from_slice(slice: &[u8]) -> anyhow::Result<WafRuleset> {
        let rules_version = extract_rules_version(slice);
        let doc: libddwaf::object::WafObject = serde_json::from_slice(slice)
            .with_context(|| "Error deserializing ddwaf_object data from json slice")?;
        Ok(WafRuleset::new(doc, rules_version))
    }

    pub fn rules_version(&self) -> Option<&str> {
        self.rules_version.as_deref()
    }
}
impl From<WafRuleset> for libddwaf::object::WafObject {
    fn from(val: WafRuleset) -> Self {
        val.doc
    }
}

fn extract_rules_version<R: Read>(reader: R) -> Option<String> {
    #[derive(serde::Deserialize)]
    struct RulesetMetadata {
        metadata: Option<Metadata>,
    }
    #[derive(serde::Deserialize)]
    struct Metadata {
        rules_version: Option<String>,
    }

    let parsed: RulesetMetadata = serde_json::from_reader(reader).ok()?;
    parsed.metadata?.rules_version
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn default_ruleset_is_embedded() {
        let ruleset = WafRuleset::from_default();
        let rules_version = ruleset
            .rules_version()
            .expect("default ruleset should expose its version");

        assert!(!rules_version.is_empty());
    }
}
