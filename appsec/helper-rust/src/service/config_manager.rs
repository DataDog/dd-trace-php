use std::{collections::BTreeMap, marker::PhantomData};

use serde::{de, Deserialize, Deserializer};

use crate::service::AutoUserInstrumMode;

pub(super) type AsmFeatureConfigManager = ConfigManager<AsmFeaturesConfigFinal, AsmFeaturesConfig>;

pub(super) struct ConfigManager<Final, Partial: Mergeable<Final>> {
    configs: BTreeMap<String, Partial>,
    _phantom: PhantomData<Final>,
}

impl<Final, Partial: Mergeable<Final>> ConfigManager<Final, Partial> {
    pub fn new() -> Self {
        Self {
            configs: BTreeMap::new(),
            _phantom: PhantomData,
        }
    }

    pub fn add(&mut self, key: String, value_json: &[u8]) -> anyhow::Result<()> {
        let value = serde_json::from_slice::<Partial>(value_json).map_err(|e| {
            anyhow::anyhow!(
                "failed to deserialize asm features config for {key} (value: {:?}): {e}",
                String::from_utf8_lossy(value_json)
            )
        })?;
        self.configs.insert(key, value);
        Ok(())
    }

    pub fn remove(&mut self, key: impl AsRef<str>) -> bool {
        self.configs.remove(key.as_ref()).is_some()
    }

    pub fn build_final(&self) -> Final {
        self.configs
            .values()
            .fold(Default::default(), |acc: Partial, x| acc.merge(x))
            .to_final()
    }
}

pub(super) trait Mergeable<Final>: serde::de::DeserializeOwned + Default {
    fn merge(self, other: &Self) -> Self;
    fn to_final(self) -> Final;
}

#[derive(Default, Debug, Deserialize)]
pub(super) struct AsmFeaturesConfig {
    #[serde(default)]
    asm: Option<AsmEnabled>,

    #[serde(default)]
    auto_user_instrum: AutoUserInstrumInner,
}

#[derive(Default, Debug, Deserialize)]
struct AsmEnabled {
    #[serde(default, deserialize_with = "deserialize_bool_or_string")]
    enabled: bool,
}

#[derive(Default, Debug, Deserialize)]
struct AutoUserInstrumInner {
    #[serde(default, deserialize_with = "deserialize_auto_user_instrum_mode")]
    mode: AutoUserInstrumMode,
}
pub(super) struct AsmFeaturesConfigFinal {
    pub asm: bool,
    pub auto_user_instrum: AutoUserInstrumMode,
}

impl Mergeable<AsmFeaturesConfigFinal> for AsmFeaturesConfig {
    fn merge(self, other: &Self) -> Self {
        let mode = match (other.auto_user_instrum.mode, self.auto_user_instrum.mode) {
            (new, AutoUserInstrumMode::Undefined) => new,
            (AutoUserInstrumMode::Undefined, old) => old,
            (new, _) => new,
        };
        let asm = other
            .asm
            .as_ref()
            .map(|a| a.enabled)
            .unwrap_or_else(|| self.asm.map(|a| a.enabled).unwrap_or(false));
        Self {
            asm: Some(AsmEnabled { enabled: asm }),
            auto_user_instrum: AutoUserInstrumInner { mode },
        }
    }

    fn to_final(self) -> AsmFeaturesConfigFinal {
        AsmFeaturesConfigFinal {
            asm: self.asm.map(|a| a.enabled).unwrap_or(false),
            auto_user_instrum: self.auto_user_instrum.mode,
        }
    }
}

/// Deserializes a boolean from either a JSON boolean or a string ("true"/"false").
fn deserialize_bool_or_string<'de, D>(deserializer: D) -> Result<bool, D::Error>
where
    D: Deserializer<'de>,
{
    #[derive(Deserialize)]
    #[serde(untagged)]
    enum BoolOrString {
        Bool(bool),
        String(String),
    }

    match Option::<BoolOrString>::deserialize(deserializer)? {
        None => Ok(false),
        Some(BoolOrString::Bool(b)) => Ok(b),
        Some(BoolOrString::String(s)) => match s.to_ascii_lowercase().as_str() {
            "true" => Ok(true),
            "false" => Ok(false),
            _ => Err(de::Error::custom(format!(
                "expected 'true' or 'false', got '{s}'"
            ))),
        },
    }
}

/// Deserializes AutoUserInstrumMode from a case-insensitive string.
fn deserialize_auto_user_instrum_mode<'de, D>(
    deserializer: D,
) -> Result<AutoUserInstrumMode, D::Error>
where
    D: Deserializer<'de>,
{
    let s = Option::<String>::deserialize(deserializer)?;
    Ok(match s.as_deref() {
        Some(s) if s.eq_ignore_ascii_case("identification") => AutoUserInstrumMode::Identification,
        Some(s) if s.eq_ignore_ascii_case("anonymization") => AutoUserInstrumMode::Anonymization,
        Some(s) if s.eq_ignore_ascii_case("disabled") => AutoUserInstrumMode::Disabled,
        Some(_) => AutoUserInstrumMode::Unknown,
        None => AutoUserInstrumMode::default(),
    })
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn asm_features_deserialization() {
        let cases = [
            (r#"{}"#, false, AutoUserInstrumMode::Undefined),
            (
                r#"{"asm": {"enabled": true}}"#,
                true,
                AutoUserInstrumMode::Undefined,
            ),
            (
                r#"{"asm": {"enabled": false}}"#,
                false,
                AutoUserInstrumMode::Undefined,
            ),
            (
                r#"{"asm": {"enabled": "true"}}"#,
                true,
                AutoUserInstrumMode::Undefined,
            ),
            (
                r#"{"asm": {"enabled": "FALSE"}}"#,
                false,
                AutoUserInstrumMode::Undefined,
            ),
            (
                r#"{"auto_user_instrum": {"mode": "identification"}}"#,
                false,
                AutoUserInstrumMode::Identification,
            ),
            (
                r#"{"auto_user_instrum": {"mode": "ANONYMIZATION"}}"#,
                false,
                AutoUserInstrumMode::Anonymization,
            ),
            (
                r#"{"auto_user_instrum": {"mode": "disabled"}}"#,
                false,
                AutoUserInstrumMode::Disabled,
            ),
            (
                r#"{"auto_user_instrum": {"mode": "garbage"}}"#,
                false,
                AutoUserInstrumMode::Unknown,
            ),
        ];

        for (json, expected_asm, expected_auto) in cases {
            let config: AsmFeaturesConfig = serde_json::from_str(json).unwrap();
            let final_config = config.to_final();
            assert_eq!(final_config.asm, expected_asm, "asm mismatch for {json}");
            assert_eq!(
                final_config.auto_user_instrum, expected_auto,
                "auto_user_instrum mismatch for {json}"
            );
        }
    }

    #[test]
    fn asm_features_invalid_asm_type() {
        let result = serde_json::from_str::<AsmFeaturesConfig>(r#"{"asm": {"enabled": 1}}"#);
        assert!(result.is_err());
    }
}
