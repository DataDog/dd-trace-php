// Copyright 2026-Present Datadog, Inc. https://www.datadoghq.com/
// SPDX-License-Identifier: Apache-2.0

pub use datadog_ffe::cdn::{CdnEndpoint as AgentlessEndpoint, EndpointError};
use std::time::Duration;

pub const DEFAULT_POLL_INTERVAL_SECONDS: i64 = 30;
pub const MAX_POLL_INTERVAL_SECONDS: i64 = 3_600;
pub const DEFAULT_REQUEST_TIMEOUT_SECONDS: i64 = 5;
pub const MAX_REQUEST_TIMEOUT_SECONDS: i64 = 300;
pub const DEFAULT_INITIALIZATION_TIMEOUT_MS: i64 = 10_000;
pub const MAX_INITIALIZATION_TIMEOUT_MS: i64 = i32::MAX as i64;

/// The configured source is retained separately from enablement. In particular,
/// `Offline` is a reserved source whose delivery is disabled today, rather than
/// an alias for the absence of a configured source.
#[derive(Clone, Copy, Debug, Eq, PartialEq)]
pub enum ConfigurationSource {
    Agentless,
    RemoteConfig,
    Offline,
    Invalid,
}

#[derive(Clone, Copy, Debug, Eq, PartialEq)]
pub enum DisableReason {
    KillSwitch,
    InvalidSource,
    LegacyDisabled,
    Offline,
}

#[derive(Clone, Copy, Debug, Eq, PartialEq)]
pub struct SourceResolution {
    pub enabled: bool,
    pub source: ConfigurationSource,
    pub disable_reason: Option<DisableReason>,
    pub legacy_key_decided: bool,
}

impl SourceResolution {
    pub fn delivery_source(self) -> Option<ConfigurationSource> {
        self.enabled.then_some(self.source)
    }
}

#[derive(Clone, Copy)]
pub struct SourceInput<'a> {
    pub enabled: bool,
    pub enabled_set: bool,
    pub source: &'a str,
    pub source_set: bool,
    pub legacy_enabled: bool,
    pub legacy_enabled_set: bool,
}

pub fn resolve_source(input: SourceInput<'_>) -> SourceResolution {
    let explicit_source = input
        .source_set
        .then(|| input.source.trim())
        .filter(|s| !s.is_empty());

    let (mut enabled, source, disable_reason, legacy_key_decided) =
        if let Some(source) = explicit_source {
            match source.to_ascii_lowercase().as_str() {
                "agentless" => (true, ConfigurationSource::Agentless, None, false),
                "remote_config" => (true, ConfigurationSource::RemoteConfig, None, false),
                "offline" => (
                    false,
                    ConfigurationSource::Offline,
                    Some(DisableReason::Offline),
                    false,
                ),
                _ => (
                    false,
                    ConfigurationSource::Invalid,
                    Some(DisableReason::InvalidSource),
                    false,
                ),
            }
        } else if input.enabled_set {
            (true, ConfigurationSource::Agentless, None, false)
        } else if input.legacy_enabled_set {
            if input.legacy_enabled {
                (true, ConfigurationSource::RemoteConfig, None, true)
            } else {
                (
                    false,
                    ConfigurationSource::RemoteConfig,
                    Some(DisableReason::LegacyDisabled),
                    true,
                )
            }
        } else {
            (true, ConfigurationSource::Agentless, None, false)
        };

    let disable_reason = if input.enabled_set && !input.enabled {
        enabled = false;
        Some(DisableReason::KillSwitch)
    } else {
        disable_reason
    };

    SourceResolution {
        enabled,
        source,
        disable_reason,
        legacy_key_decided,
    }
}

#[derive(Clone, Copy, Debug, Eq, PartialEq)]
pub enum SettingsIssue {
    PollInterval,
    RequestTimeout,
    InitializationTimeout,
}

pub struct SettingsInput<'a> {
    pub source: SourceInput<'a>,
    /// Sensitive: may contain credentials and must never be logged.
    pub agentless_base_url: &'a str,
    pub poll_interval_seconds: i64,
    pub request_timeout_seconds: i64,
    pub initialization_timeout_ms: i64,
    pub site: &'a str,
    /// Sensitive: must never be logged.
    pub api_key: &'a str,
    pub environment: &'a str,
}

/// A validated, process-stable settings snapshot. Deliberately does not derive
/// `Debug`, because it owns the API key and potentially credential-bearing URL.
pub struct FeatureFlagsSettings {
    pub resolution: SourceResolution,
    agentless_base_url: Option<String>,
    pub poll_interval: Duration,
    pub request_timeout: Duration,
    pub initialization_timeout: Duration,
    site: String,
    api_key: String,
    environment: String,
    pub issues: Vec<SettingsIssue>,
}

impl FeatureFlagsSettings {
    pub fn resolve(input: SettingsInput<'_>) -> Self {
        let mut issues = Vec::new();
        let poll_interval_seconds = validated_numeric(
            input.poll_interval_seconds,
            DEFAULT_POLL_INTERVAL_SECONDS,
            MAX_POLL_INTERVAL_SECONDS,
            SettingsIssue::PollInterval,
            &mut issues,
        );
        let request_timeout_seconds = validated_numeric(
            input.request_timeout_seconds,
            DEFAULT_REQUEST_TIMEOUT_SECONDS,
            MAX_REQUEST_TIMEOUT_SECONDS,
            SettingsIssue::RequestTimeout,
            &mut issues,
        );
        let initialization_timeout_ms = validated_numeric(
            input.initialization_timeout_ms,
            DEFAULT_INITIALIZATION_TIMEOUT_MS,
            MAX_INITIALIZATION_TIMEOUT_MS,
            SettingsIssue::InitializationTimeout,
            &mut issues,
        );

        Self {
            resolution: resolve_source(input.source),
            // PHP INI uses an empty string for the optional URL's unset value.
            agentless_base_url: (!input.agentless_base_url.trim().is_empty())
                .then(|| input.agentless_base_url.to_owned()),
            poll_interval: Duration::from_secs(poll_interval_seconds as u64),
            request_timeout: Duration::from_secs(request_timeout_seconds as u64),
            initialization_timeout: Duration::from_millis(initialization_timeout_ms as u64),
            site: input.site.to_owned(),
            api_key: input.api_key.to_owned(),
            environment: input.environment.to_owned(),
            issues,
        }
    }

    pub fn agentless_endpoint(&self) -> Result<AgentlessEndpoint, EndpointError> {
        AgentlessEndpoint::build(
            self.agentless_base_url.as_deref().unwrap_or_default(),
            &self.site,
            &self.environment,
            &self.api_key,
        )
    }
}

fn validated_numeric(
    value: i64,
    default: i64,
    maximum: i64,
    issue: SettingsIssue,
    issues: &mut Vec<SettingsIssue>,
) -> i64 {
    if value > 0 && value <= maximum {
        value
    } else {
        issues.push(issue);
        default
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn source_input() -> SourceInput<'static> {
        SourceInput {
            enabled: true,
            enabled_set: false,
            source: "",
            source_set: false,
            legacy_enabled: false,
            legacy_enabled_set: false,
        }
    }

    #[test]
    fn source_precedence_matches_the_cross_sdk_contract() {
        let cases = [
            (
                source_input(),
                true,
                ConfigurationSource::Agentless,
                None,
                false,
            ),
            (
                SourceInput {
                    enabled: false,
                    enabled_set: true,
                    source: "remote_config",
                    source_set: true,
                    legacy_enabled: true,
                    legacy_enabled_set: true,
                },
                false,
                ConfigurationSource::RemoteConfig,
                Some(DisableReason::KillSwitch),
                false,
            ),
            (
                SourceInput {
                    source: "  ReMoTe_CoNfIg  ",
                    source_set: true,
                    legacy_enabled: false,
                    legacy_enabled_set: true,
                    ..source_input()
                },
                true,
                ConfigurationSource::RemoteConfig,
                None,
                false,
            ),
            (
                SourceInput {
                    source: "offline",
                    source_set: true,
                    ..source_input()
                },
                false,
                ConfigurationSource::Offline,
                Some(DisableReason::Offline),
                false,
            ),
            (
                SourceInput {
                    source: "typo",
                    source_set: true,
                    ..source_input()
                },
                false,
                ConfigurationSource::Invalid,
                Some(DisableReason::InvalidSource),
                false,
            ),
            (
                SourceInput {
                    enabled: true,
                    enabled_set: true,
                    legacy_enabled: false,
                    legacy_enabled_set: true,
                    ..source_input()
                },
                true,
                ConfigurationSource::Agentless,
                None,
                false,
            ),
            (
                SourceInput {
                    legacy_enabled: true,
                    legacy_enabled_set: true,
                    ..source_input()
                },
                true,
                ConfigurationSource::RemoteConfig,
                None,
                true,
            ),
            (
                SourceInput {
                    legacy_enabled: false,
                    legacy_enabled_set: true,
                    ..source_input()
                },
                false,
                ConfigurationSource::RemoteConfig,
                Some(DisableReason::LegacyDisabled),
                true,
            ),
        ];

        for (input, enabled, source, disable_reason, legacy_key_decided) in cases {
            let resolution = resolve_source(input);
            assert_eq!(resolution.enabled, enabled);
            assert_eq!(resolution.source, source);
            assert_eq!(resolution.disable_reason, disable_reason);
            assert_eq!(resolution.legacy_key_decided, legacy_key_decided);
        }
    }

    #[test]
    fn blank_explicit_source_is_semantically_unset() {
        for source in ["", " ", "\t\r\n"] {
            let resolution = resolve_source(SourceInput {
                source,
                source_set: true,
                legacy_enabled: true,
                legacy_enabled_set: true,
                ..source_input()
            });
            assert_eq!(
                resolution.delivery_source(),
                Some(ConfigurationSource::RemoteConfig)
            );
            assert!(resolution.legacy_key_decided);
        }
    }

    #[test]
    fn invalid_numeric_values_use_defaults_and_report_issues() {
        let settings = FeatureFlagsSettings::resolve(SettingsInput {
            source: source_input(),
            agentless_base_url: "",
            poll_interval_seconds: 3_601,
            request_timeout_seconds: 0,
            initialization_timeout_ms: i64::MAX,
            site: "",
            api_key: "key",
            environment: "",
        });

        assert!(settings.agentless_base_url.is_none());
        assert!(settings.agentless_endpoint().unwrap().is_managed());
        assert_eq!(settings.poll_interval, Duration::from_secs(30));
        assert_eq!(settings.request_timeout, Duration::from_secs(5));
        assert_eq!(settings.initialization_timeout, Duration::from_secs(10));
        assert_eq!(
            settings.issues,
            vec![
                SettingsIssue::PollInterval,
                SettingsIssue::RequestTimeout,
                SettingsIssue::InitializationTimeout,
            ]
        );
    }
}
