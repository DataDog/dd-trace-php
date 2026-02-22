use crate::client::log;
use std::{
    fs::File,
    io::{BufRead, BufReader, Read, Seek},
    path::{Path, PathBuf},
};

use anyhow::{anyhow, Context};

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

    pub fn from_default_file() -> anyhow::Result<WafRuleset> {
        let file = get_default_rules_file()?;
        WafRuleset::from_file(&file)
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

fn get_default_rules_file() -> anyhow::Result<PathBuf> {
    let helper_path = get_helper_path();

    let base_path: PathBuf = if let Ok(helper_path) = helper_path {
        helper_path
            .parent()
            .ok_or(anyhow!("No parent for {:?}", helper_path))?
            .to_path_buf()
    } else {
        get_self_path().with_context(|| "Could find neither lib path nor self exe path")?
    };

    let file = base_path.join("../etc/recommended.json");
    if file.exists() {
        return Ok(file);
    }

    let file_legacy = base_path.join("../etc/dd-appsec/recommended.json");
    if file_legacy.exists() {
        return Ok(file_legacy);
    }

    Err(anyhow!(
        "Could not find recommended.json in either ../etc/ or ../etc/dd-appsec/"
    ))
}

fn get_helper_path() -> anyhow::Result<PathBuf> {
    const LIBNAME: &str = "/libddappsec-helper.so";
    const MAPS_PATH: &str = "/proc/self/maps";

    let file = File::open(MAPS_PATH)?;
    let reader = BufReader::new(file);

    for line in reader.lines() {
        let line = line?;
        if line.contains(LIBNAME) {
            if let Some(pos) = line.find('/') {
                return Ok(PathBuf::from(&line[pos..]));
            } else {
                return Err(anyhow!("Should not happen"));
            }
        }
    }

    Err(anyhow!(
        "Could not find libddappsec-helper.so in /proc/self/maps"
    ))
}

pub fn get_self_path() -> anyhow::Result<PathBuf> {
    const SELF_EXE: &str = "/proc/self/exe";

    let path = std::fs::read_link(SELF_EXE)?;
    Ok(path)
}
