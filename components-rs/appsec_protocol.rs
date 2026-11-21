// Copyright 2021-Present Datadog, Inc. https://www.datadoghq.com/
// SPDX-License-Identifier: Apache-2.0

// The AppSec protocol between the PHP extension and the AppSec helper, which
// runs inside the sidecar. libdatadog's datadog-sidecar includes this file as
// `datadog_sidecar::appsec::protocol` (see DATADOG_SIDECAR_APPSEC_PROTOCOL);
// it is not a module of components-rs. Only serde and std are available.
//
// The sidecar IPC encodes with bincode, which is not self-describing: no
// untagged enums, flattening or skipped fields. Both ends are always built
// from the same sources, so there is no versioning.

use serde::de::{self, SeqAccess, Visitor};
use serde::{Deserialize, Deserializer, Serialize, Serializer};
use std::fmt;

/// The connection-wide AppSec settings, sent with the session config. A
/// connection without them has no AppSec helper client.
#[derive(Clone, Debug, PartialEq, Serialize, Deserialize)]
pub struct AppSecSessionConfig {
    /// Empty: the helper's default rules, updated by remote config.
    pub rules_file: String,
    /// Zero: the helper's default.
    pub waf_timeout_us: u64,
    pub trace_rate_limit: u32,
    pub obfuscator_key_regex: String,
    pub obfuscator_value_regex: String,
    pub schema_extraction_enabled: bool,
    pub schema_extraction_sampling_period: f64,
    /// The helper's own log, configured by the first session that starts it.
    pub helper_log_file: Vec<u8>,
    pub helper_log_level: String,
}

/// A PHP string, which is not necessarily UTF-8.
#[derive(Clone, Default, PartialEq, Eq, Hash)]
pub struct AppSecBytes(pub Vec<u8>);

impl AppSecBytes {
    pub fn as_slice(&self) -> &[u8] {
        &self.0
    }
}

impl From<&str> for AppSecBytes {
    fn from(value: &str) -> Self {
        AppSecBytes(value.as_bytes().to_vec())
    }
}

impl Serialize for AppSecBytes {
    fn serialize<S: Serializer>(&self, serializer: S) -> Result<S::Ok, S::Error> {
        serializer.serialize_bytes(&self.0)
    }
}

impl<'de> Deserialize<'de> for AppSecBytes {
    fn deserialize<D: Deserializer<'de>>(deserializer: D) -> Result<Self, D::Error> {
        struct BytesVisitor;

        impl<'de> Visitor<'de> for BytesVisitor {
            type Value = AppSecBytes;

            fn expecting(&self, formatter: &mut fmt::Formatter) -> fmt::Result {
                formatter.write_str("a byte string")
            }

            fn visit_bytes<E: de::Error>(self, v: &[u8]) -> Result<Self::Value, E> {
                Ok(AppSecBytes(v.to_vec()))
            }

            fn visit_byte_buf<E: de::Error>(self, v: Vec<u8>) -> Result<Self::Value, E> {
                Ok(AppSecBytes(v))
            }

            fn visit_str<E: de::Error>(self, v: &str) -> Result<Self::Value, E> {
                Ok(AppSecBytes(v.as_bytes().to_vec()))
            }

            fn visit_seq<A: SeqAccess<'de>>(self, mut seq: A) -> Result<Self::Value, A::Error> {
                let mut bytes = Vec::with_capacity(seq.size_hint().unwrap_or(0));
                while let Some(byte) = seq.next_element()? {
                    bytes.push(byte);
                }
                Ok(AppSecBytes(bytes))
            }
        }

        deserializer.deserialize_byte_buf(BytesVisitor)
    }
}

impl fmt::Debug for AppSecBytes {
    // Request data is not logged: it carries headers, cookies and bodies.
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        write!(f, "<{} bytes>", self.0.len())
    }
}

/// The addresses of one WAF run: a MessagePack map of address names to values,
/// which the helper decodes straight into WAF objects. Request data is not
/// logged, only its size.
#[derive(Clone, Default, PartialEq, Serialize, Deserialize)]
pub struct AppSecAddresses(pub AppSecBytes);

impl fmt::Debug for AppSecAddresses {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        write!(f, "<{} bytes of MessagePack>", self.0 .0.len())
    }
}

/// The RASP rule a request_exec runs for.
#[derive(Clone, Debug, PartialEq, Serialize, Deserialize)]
pub struct AppSecRaspRule {
    pub rule: String,
    pub variant: Option<String>,
}

/// A request of the extension to the helper client of its connection.
#[derive(Clone, Debug, PartialEq, Serialize, Deserialize)]
pub enum AppSecRequest {
    /// Starts a request. The client is bound to the connection's current
    /// application first, unless `keep_bound_application` is set and some
    /// application is bound already: without DD_SERVICE/DD_ENV, the
    /// application at request start is not the one the request ends with.
    Init {
        addresses: AppSecAddresses,
        keep_bound_application: bool,
    },
    /// Runs the WAF during the request, as a RASP check if `rasp` is set.
    Exec {
        addresses: AppSecAddresses,
        rasp: Option<AppSecRaspRule>,
    },
    /// Finishes the request.
    Shutdown {
        addresses: AppSecAddresses,
        /// Zero: no API security sampling.
        api_sec_samp_key: u64,
        input_truncated: bool,
    },
}

/// An action of the WAF, with its parameters as the WAF reports them
/// (e.g. `block` with `status_code` and `type`). The extension validates them.
#[derive(Clone, Debug, PartialEq, Serialize, Deserialize)]
pub struct AppSecAction {
    pub action: String,
    pub parameters: Vec<(String, String)>,
}

#[derive(Clone, Debug, Default, PartialEq, Serialize, Deserialize)]
pub struct AppSecOutcome {
    pub actions: Vec<AppSecAction>,
    /// JSON fragments of the triggered events.
    pub triggers: Vec<String>,
    pub force_keep: bool,
    /// For the root span.
    pub meta: Vec<(String, String)>,
    pub metrics: Vec<(String, f64)>,
}

/// The helper's answer to an [`AppSecRequest`].
#[derive(Clone, Debug, Default, PartialEq, Serialize, Deserialize)]
pub enum AppSecVerdict {
    /// No helper client could handle the request (none configured, failed to
    /// start, or gone). The extension skips AppSec for the rest of the request.
    #[default]
    Unavailable,
    /// The client failed the request, e.g. a request_exec without request_init.
    Error(String),
    Ok(AppSecOutcome),
}
