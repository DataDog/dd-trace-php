// Copyright 2026-Present Datadog, Inc. https://www.datadoghq.com/
// SPDX-License-Identifier: Apache-2.0

use std::fmt;
use std::time::Duration;
use url::Url;

pub const DEFAULT_POLL_INTERVAL_SECONDS: i64 = 30;
pub const MAX_POLL_INTERVAL_SECONDS: i64 = 3_600;
pub const DEFAULT_REQUEST_TIMEOUT_SECONDS: i64 = 5;
pub const MAX_REQUEST_TIMEOUT_SECONDS: i64 = 300;
pub const DEFAULT_INITIALIZATION_TIMEOUT_MS: i64 = 10_000;
pub const MAX_INITIALIZATION_TIMEOUT_MS: i64 = i32::MAX as i64;

const DEFAULT_SITE: &str = "datadoghq.com";
const AGENTLESS_HOST_PREFIX: &str = "ufc-server.ff-cdn.";
const AGENTLESS_CONFIGURATION_PATH: &str = "/api/v2/feature-flagging/config/rules-based/server";

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
    agentless_base_url: String,
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
            agentless_base_url: input.agentless_base_url.to_owned(),
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
            &self.agentless_base_url,
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

/// A validated agentless endpoint. Only managed endpoints can own an API key,
/// ensuring a custom endpoint cannot accidentally receive the Datadog credential.
#[derive(Clone, Eq, PartialEq)]
pub struct AgentlessEndpoint {
    url: String,
    api_key: Option<String>,
}

impl AgentlessEndpoint {
    pub fn build(
        base_url: &str,
        site: &str,
        environment: &str,
        api_key: &str,
    ) -> Result<Self, EndpointError> {
        if base_url.trim().is_empty() {
            Self::managed(site, environment, api_key)
        } else {
            Self::custom(base_url)
        }
    }

    pub fn as_str(&self) -> &str {
        &self.url
    }

    pub fn is_managed(&self) -> bool {
        self.api_key.is_some()
    }

    pub fn api_key(&self) -> Option<&str> {
        self.api_key.as_deref()
    }

    fn managed(site: &str, environment: &str, api_key: &str) -> Result<Self, EndpointError> {
        if api_key.is_empty() {
            return Err(EndpointError::MissingApiKey);
        }

        let normalized_site = normalize_site(site)?;
        let expected_host = format!("{AGENTLESS_HOST_PREFIX}{normalized_site}");
        let mut url = Url::parse(&format!(
            "https://{expected_host}{AGENTLESS_CONFIGURATION_PATH}"
        ))
        .map_err(|_| EndpointError::InvalidSite)?;

        // Compare the parsed host before this endpoint is allowed to carry
        // managed authentication. This is defense in depth after rejecting
        // URL-significant site characters in `normalize_site`.
        if url.host_str() != Some(expected_host.as_str()) {
            return Err(EndpointError::InvalidSite);
        }

        if !environment.is_empty() {
            url.query_pairs_mut().append_pair("dd_env", environment);
        }

        Ok(Self {
            url: url.to_string(),
            api_key: Some(api_key.to_owned()),
        })
    }

    fn custom(base_url: &str) -> Result<Self, EndpointError> {
        if base_url.chars().any(char::is_whitespace) {
            return Err(EndpointError::UrlContainsWhitespace);
        }

        let mut url = Url::parse(base_url).map_err(|_| EndpointError::InvalidUrl)?;
        if !matches!(url.scheme(), "http" | "https") {
            return Err(EndpointError::UnsupportedScheme);
        }
        if url.host_str().is_none() || url.fragment().is_some() {
            return Err(EndpointError::InvalidUrl);
        }

        let url = if matches!(url.path(), "" | "/") {
            url.set_path(AGENTLESS_CONFIGURATION_PATH);
            url.to_string()
        } else {
            // A custom non-root URL is opaque and must be requested exactly as
            // the operator supplied it, including its query string.
            base_url.to_owned()
        };

        Ok(Self { url, api_key: None })
    }
}

impl fmt::Debug for AgentlessEndpoint {
    fn fmt(&self, formatter: &mut fmt::Formatter<'_>) -> fmt::Result {
        formatter
            .debug_struct("AgentlessEndpoint")
            .field("url", &"[REDACTED]")
            .field("managed", &self.is_managed())
            .finish()
    }
}

#[derive(Clone, Copy, Debug, Eq, PartialEq)]
pub enum EndpointError {
    MissingApiKey,
    InvalidUrl,
    UnsupportedScheme,
    UrlContainsWhitespace,
    InvalidSite,
}

impl fmt::Display for EndpointError {
    fn fmt(&self, formatter: &mut fmt::Formatter<'_>) -> fmt::Result {
        let message = match self {
            Self::MissingApiKey => "no API key is configured for the managed endpoint",
            Self::InvalidUrl => "the configured URL is not a valid absolute URL",
            Self::UnsupportedScheme => "the configured URL must use HTTP or HTTPS",
            Self::UrlContainsWhitespace => "the configured URL contains whitespace",
            Self::InvalidSite => "the configured site is not a valid host",
        };
        formatter.write_str(message)
    }
}

impl std::error::Error for EndpointError {}

fn normalize_site(site: &str) -> Result<String, EndpointError> {
    let site = if site.trim().is_empty() {
        DEFAULT_SITE
    } else {
        site
    };
    if !site.is_ascii()
        || site.chars().any(|character| {
            character.is_whitespace() || matches!(character, '/' | '\\' | '?' | '#' | '@' | ':')
        })
    {
        return Err(EndpointError::InvalidSite);
    }
    Ok(site.to_ascii_lowercase())
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

    #[test]
    fn managed_endpoint_uses_normalized_site_and_encoded_environment() {
        let endpoint = AgentlessEndpoint::build("", "DATADOGHQ.EU", "prod / blue", "key")
            .expect("managed endpoint");

        assert!(endpoint.is_managed());
        assert_eq!(endpoint.api_key(), Some("key"));
        assert_eq!(
            endpoint.as_str(),
            "https://ufc-server.ff-cdn.datadoghq.eu/api/v2/feature-flagging/config/rules-based/server?dd_env=prod+%2F+blue"
        );
    }

    #[test]
    fn managed_endpoint_omits_an_absent_environment_and_defaults_blank_site() {
        let endpoint = AgentlessEndpoint::build("", "  ", "", "key").expect("managed endpoint");
        assert_eq!(
            endpoint.as_str(),
            "https://ufc-server.ff-cdn.datadoghq.com/api/v2/feature-flagging/config/rules-based/server"
        );
    }

    #[test]
    fn managed_endpoint_requires_an_api_key() {
        assert_eq!(
            AgentlessEndpoint::build("", "datadoghq.com", "", "").unwrap_err(),
            EndpointError::MissingApiKey
        );
    }

    #[test]
    fn managed_endpoint_rejects_sites_that_can_change_the_url_meaning() {
        for site in [
            "https://datadoghq.com",
            "datadoghq.com@attacker.example",
            "datadoghq.com/path",
            "datadoghq.com?query",
            "datadoghq.com#fragment",
            "datadoghq.com:443",
            " datadoghq.com",
            "data doghq.com",
            "dâtadoghq.com",
        ] {
            assert_eq!(
                AgentlessEndpoint::build("", site, "", "key").unwrap_err(),
                EndpointError::InvalidSite,
                "site should be rejected"
            );
        }
    }

    #[test]
    fn custom_root_endpoint_gets_the_canonical_path_and_keeps_its_query() {
        for base_url in ["http://localhost:8080", "http://localhost:8080/"] {
            let endpoint = AgentlessEndpoint::build(base_url, "ignored", "ignored", "secret")
                .expect("custom endpoint");
            assert!(!endpoint.is_managed());
            assert_eq!(
                endpoint.as_str(),
                "http://localhost:8080/api/v2/feature-flagging/config/rules-based/server"
            );
        }

        let endpoint = AgentlessEndpoint::build(
            "https://user:pass@localhost/?tenant=a%2Fb",
            "ignored",
            "ignored",
            "secret",
        )
        .expect("custom endpoint");
        assert_eq!(
            endpoint.as_str(),
            "https://user:pass@localhost/api/v2/feature-flagging/config/rules-based/server?tenant=a%2Fb"
        );
    }

    #[test]
    fn custom_non_root_endpoint_is_preserved_without_environment() {
        let endpoint = AgentlessEndpoint::build(
            "HTTPS://LOCALHOST/custom/path?tenant=a%2Fb",
            "ignored",
            "must-not-be-added",
            "must-not-be-sent",
        )
        .expect("custom endpoint");
        assert_eq!(
            endpoint.as_str(),
            "HTTPS://LOCALHOST/custom/path?tenant=a%2Fb"
        );
        assert!(!endpoint.is_managed());
        assert_eq!(endpoint.api_key(), None);
    }

    #[test]
    fn custom_endpoint_validation_errors_never_contain_the_url() {
        let credential = "credential-that-must-not-leak";
        for (url, expected) in [
            (
                format!("ftp://{credential}@localhost/path"),
                EndpointError::UnsupportedScheme,
            ),
            (
                format!("https://{credential}@local host/path"),
                EndpointError::UrlContainsWhitespace,
            ),
            (format!("not-a-url-{credential}"), EndpointError::InvalidUrl),
        ] {
            let error = AgentlessEndpoint::build(&url, "", "", "key").unwrap_err();
            assert_eq!(error, expected);
            assert!(!error.to_string().contains(credential));
        }
    }

    #[test]
    fn endpoint_debug_output_is_redacted() {
        let endpoint =
            AgentlessEndpoint::build("https://user:password@localhost/custom", "", "", "key")
                .expect("custom endpoint");
        let debug = format!("{endpoint:?}");
        assert!(!debug.contains("user"));
        assert!(!debug.contains("password"));
        assert!(debug.contains("[REDACTED]"));
    }
}
