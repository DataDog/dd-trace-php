use rmp_serde::Deserializer;
use serde::ser::SerializeSeq;
use serde::{Deserialize, Serialize};
use serde_tuple::{Deserialize_tuple, Serialize_tuple};
use std::borrow::Cow;
use std::collections::HashMap;
use std::hash::{Hash, Hasher};
use std::io;
use std::path::PathBuf;
use tokio_util::bytes::{Buf, BytesMut};
use tokio_util::codec::{Decoder, Encoder};

use crate::client::log::{fmt_bin, trace};

pub const VERSION_FOR_PROTO: &str = "1.15.0";
const MAX_MESSAGE_SIZE: u32 = 4 * 1024 * 1024;

#[derive(Debug)]
pub enum Command {
    ClientInit(Box<ClientInitArgs>),
    ConfigSync(Box<ConfigSyncArgs>),
    RequestInit(Box<RequestInitArgs>),
    RequestExec(Box<RequestExecArgs>),
    RequestShutdown(Box<RequestShutdownArgs>),
}

#[derive(Debug)]
pub enum CommandResponse<'a> {
    ProtocolError,
    ClientInit(ClientInitResp),
    ConfigSync,
    ConfigFeatures(ConfigFeaturesResp),
    RequestInit(RequestInitResp<'a>),
    RequestExec(RequestExecResp),
    RequestShutdown(RequestShutdownResp),
}

#[derive(Debug, Deserialize_tuple)]
pub struct ClientInitArgs {
    #[allow(dead_code)]
    pub pid: u32,
    #[allow(dead_code)]
    pub ddappsec_version: String,
    #[allow(dead_code)]
    pub php_version: String,
    pub appsec_enabled: Option<bool>,
    pub waf_config: WafSettings,
    pub remote_config: RemoteConfigSettings,
    pub telemetry_settings: TelemetrySettings,
    pub sidecar_settings: SidecarSettings,
}

#[derive(PartialEq, Eq, Hash, Clone, Debug, Deserialize)]
pub struct TelemetrySettings {
    pub service_name: String,
    pub env_name: String,
}

#[derive(PartialEq, Eq, Hash, Clone, Debug, Deserialize)]
pub struct SidecarSettings {
    pub session_id: String,
    pub runtime_id: String,
}

#[derive(PartialEq, Eq, Hash, Clone, Debug, Deserialize)]
pub struct WafSettings {
    #[serde(deserialize_with = "empty_string_as_none")]
    pub rules_file: Option<String>,
    #[serde(deserialize_with = "zero_as_none")]
    pub waf_timeout_us: Option<u64>,
    pub trace_rate_limit: u32,
    #[serde(deserialize_with = "empty_string_as_none")]
    pub obfuscator_key_regex: Option<String>,
    #[serde(deserialize_with = "empty_string_as_none")]
    pub obfuscator_value_regex: Option<String>,
    pub schema_extraction: SchemaExtraction,
}

#[derive(Debug, Clone, Deserialize)]
pub struct SchemaExtraction {
    pub enabled: bool,
    pub sampling_period: f64,
}
impl PartialEq for SchemaExtraction {
    fn eq(&self, other: &Self) -> bool {
        self.enabled == other.enabled
            && self.sampling_period.to_bits() == other.sampling_period.to_bits()
    }
}
impl Eq for SchemaExtraction {}
impl Hash for SchemaExtraction {
    fn hash<H: Hasher>(&self, state: &mut H) {
        self.enabled.hash(state);
        self.sampling_period.to_bits().hash(state);
    }
}

#[derive(PartialEq, Eq, Hash, Clone, Debug, Deserialize)]
pub struct RemoteConfigSettings {
    pub enabled: bool,
    pub shmem_path: PathBuf,
}

#[derive(Debug, Default, Serialize_tuple)]
pub struct ClientInitResp {
    pub status: String,
    pub version: &'static str,
    pub errors: Vec<String>,
    pub meta: HashMap<String, String>,
    pub metrics: HashMap<String, f64>,
    pub tel_metrics: HashMap<String, Vec<TelemetryMetric>>,
}

#[derive(Debug, Serialize_tuple)]
pub struct TelemetryMetric {
    pub value: f64,
    pub tags: String,
}

#[derive(Debug, Deserialize_tuple)]
pub struct ConfigSyncArgs {
    pub rem_cfg_path: String,
    pub telemetry_settings: TelemetrySettings,
}

#[derive(Debug, Serialize_tuple)]
pub struct ConfigFeaturesResp {
    pub enabled: bool,
}

#[derive(Debug, Deserialize_tuple)]
pub struct RequestInitArgs {
    pub data: libddwaf::object::WafMap,
}

#[derive(Debug, Serialize_tuple)]
pub struct RequestInitResp<'a> {
    pub actions: &'a Vec<ActionInstance>,
    pub triggers: &'a Vec<String>,
    pub force_keep: bool,
    pub settings: HashMap<&'static str, String>,
}
#[derive(Debug, Serialize_tuple)]
pub struct ActionInstance {
    pub action: &'static str,
    pub parameters: HashMap<String, String>,
}

#[derive(Debug, Deserialize_tuple)]
pub struct RequestExecArgs {
    pub data: libddwaf::object::WafMap,
    pub options: RequestExecOptions,
}
#[derive(Debug, Deserialize)]
pub struct RequestExecOptions {
    #[serde(rename = "rasp_rule")]
    pub run_type: WafRunType,
    pub subctx_id: Option<String>,
    #[serde(default)]
    pub subctx_last_call: bool,
}
impl RequestExecOptions {
    pub fn regular() -> Self {
        Self {
            run_type: WafRunType::NonRasp,
            subctx_id: None,
            subctx_last_call: false,
        }
    }
}
#[derive(Debug, PartialEq)]
pub enum WafRunType {
    NonRasp,
    RaspRule(String),
}
impl<'de> Deserialize<'de> for WafRunType {
    fn deserialize<D>(deserializer: D) -> Result<Self, D::Error>
    where
        D: serde::Deserializer<'de>,
    {
        let opt = Option::<String>::deserialize(deserializer)?;

        match opt.as_deref() {
            None | Some("") => Ok(WafRunType::NonRasp),
            Some(s) => Ok(WafRunType::RaspRule(s.to_string())),
        }
    }
}

#[derive(Debug, Serialize_tuple)]
pub struct RequestExecResp {
    pub actions: Vec<ActionInstance>,
    pub triggers: Vec<String>,
    pub force_keep: bool,
    pub settings: HashMap<String, String>,
}

#[derive(Debug, Deserialize_tuple)]
pub struct RequestShutdownArgs {
    pub data: libddwaf::object::WafMap,
    pub api_sec_samp_key: u64,
    #[allow(dead_code)]
    pub queue_id: u64, // TODO: unused, update protocol
}

#[derive(Debug, Serialize_tuple)]
pub struct RequestShutdownResp {
    pub actions: Vec<ActionInstance>,
    pub triggers: Vec<String>,
    pub force_keep: bool,
    pub settings: HashMap<String, String>,
    pub meta: HashMap<Cow<'static, str>, String>,
    pub metrics: HashMap<Cow<'static, str>, f64>,
    pub tel_metrics: HashMap<String, Vec<TelemetryMetric>>, // XXX: to be removed in https://github.com/DataDog/dd-trace-php/pull/3530
}

impl<'de> Deserialize<'de> for Command {
    fn deserialize<D>(deserializer: D) -> Result<Self, D::Error>
    where
        D: serde::Deserializer<'de>,
    {
        struct CommandVisitor;

        impl<'de> serde::de::Visitor<'de> for CommandVisitor {
            type Value = Command;
            fn expecting(&self, formatter: &mut std::fmt::Formatter) -> std::fmt::Result {
                formatter.write_str("an array with form [command_name, [command_args...]]")
            }

            fn visit_seq<A>(self, mut seq: A) -> Result<Self::Value, A::Error>
            where
                A: serde::de::SeqAccess<'de>,
            {
                let command_name: String = seq
                    .next_element()?
                    .ok_or_else(|| serde::de::Error::invalid_length(0, &self))?;

                match command_name.as_str() {
                    "client_init" => {
                        let args: ClientInitArgs = seq.next_element()?.ok_or_else(|| {
                            serde::de::Error::custom("Missing arguments for ClientInit")
                        })?;
                        Ok(Command::ClientInit(Box::new(args)))
                    }
                    "config_sync" => {
                        let args: ConfigSyncArgs = seq.next_element()?.ok_or_else(|| {
                            serde::de::Error::custom("Missing arguments for ConfigSync")
                        })?;
                        Ok(Command::ConfigSync(Box::new(args)))
                    }
                    "request_init" => {
                        let args: RequestInitArgs = seq.next_element()?.ok_or_else(|| {
                            serde::de::Error::custom("Missing arguments for RequestInit")
                        })?;
                        Ok(Command::RequestInit(Box::new(args)))
                    }
                    "request_exec" => {
                        let args: RequestExecArgs = seq.next_element()?.ok_or_else(|| {
                            serde::de::Error::custom("Missing arguments for RequestExec")
                        })?;
                        Ok(Command::RequestExec(Box::new(args)))
                    }
                    "request_shutdown" => {
                        let args: RequestShutdownArgs = seq.next_element()?.ok_or_else(|| {
                            serde::de::Error::custom("Missing arguments for RequestShutdown")
                        })?;
                        Ok(Command::RequestShutdown(Box::new(args)))
                    }
                    v => Err(serde::de::Error::custom(format!(
                        "Got unknown command name {}",
                        v
                    ))),
                }
            }
        }

        deserializer.deserialize_seq(CommandVisitor)
    }
}

fn empty_string_as_none<'de, D>(deserializer: D) -> Result<Option<String>, D::Error>
where
    D: serde::Deserializer<'de>,
{
    let opt: Option<String> = Option::deserialize(deserializer)?;
    Ok(opt.filter(|s| !s.is_empty()))
}

fn zero_as_none<'de, D>(deserializer: D) -> Result<Option<u64>, D::Error>
where
    D: serde::Deserializer<'de>,
{
    let value: u64 = u64::deserialize(deserializer)?;
    Ok(if value == 0 { None } else { Some(value) })
}

#[repr(C)]
struct Header {
    marker: [u8; 4],
    size: u32,
}
impl Header {
    const VALID_MARKER: [u8; 4] = *b"dds\0";
    fn is_valid_marker(&self) -> bool {
        self.marker == Self::VALID_MARKER
    }
    fn as_slice(&self) -> &[u8] {
        unsafe {
            std::slice::from_raw_parts(self as *const _ as *const u8, std::mem::size_of::<Self>())
        }
    }
}

pub struct CommandCodec;
impl Decoder for CommandCodec {
    type Item = Command;

    type Error = io::Error;

    fn decode(
        &mut self,
        src: &mut tokio_util::bytes::BytesMut,
    ) -> Result<Option<Self::Item>, Self::Error> {
        if src.len() < std::mem::size_of::<Header>() {
            return Ok(None);
        }

        let header_bytes = &src[..std::mem::size_of::<Header>()];
        let header = unsafe { std::ptr::read(header_bytes.as_ptr() as *const Header) };
        if !header.is_valid_marker() {
            return Err(io::Error::new(
                io::ErrorKind::InvalidData,
                "Invalid header marker",
            ));
        }

        if header.size > MAX_MESSAGE_SIZE {
            return Err(io::Error::new(
                io::ErrorKind::InvalidData,
                format!(
                    "Message is too large: {} bytes (supported up to 4 MB)",
                    header.size
                ),
            ));
        }

        let total_size = std::mem::size_of::<Header>() + header.size as usize;

        if src.len() < total_size {
            if src.capacity() < total_size {
                src.reserve(total_size - src.capacity());
            }
            return Ok(None);
        }

        let data = &src[std::mem::size_of::<Header>()..total_size];

        trace!(
            "Decoding message with size {}: {:?}",
            header.size,
            fmt_bin(data)
        );

        let mut de = Deserializer::from_read_ref(data);
        let cmd = Command::deserialize(&mut de)
            .map_err(|e| io::Error::new(io::ErrorKind::InvalidData, e))?;

        src.advance(total_size);

        Ok(Some(cmd))
    }
}

impl Serialize for CommandResponse<'_> {
    fn serialize<S>(&self, serializer: S) -> Result<S::Ok, S::Error>
    where
        S: serde::Serializer,
    {
        let mut state = serializer.serialize_seq(Some(2))?;
        match self {
            CommandResponse::ProtocolError => {
                state.serialize_element("error")?;
                state.serialize_element(&())?;
                state.end()
            }
            CommandResponse::ConfigSync => {
                state.serialize_element("config_sync")?;
                state.serialize_element(&())?;
                state.end()
            }
            CommandResponse::ConfigFeatures(resp) => {
                state.serialize_element("config_features")?;
                state.serialize_element(resp)?;
                state.end()
            }
            CommandResponse::ClientInit(resp) => {
                state.serialize_element("client_init")?;
                state.serialize_element(resp)?;
                state.end()
            }
            CommandResponse::RequestInit(resp) => {
                state.serialize_element("request_init")?;
                state.serialize_element(resp)?;
                state.end()
            }
            CommandResponse::RequestExec(resp) => {
                state.serialize_element("request_exec")?;
                state.serialize_element(resp)?;
                state.end()
            }
            CommandResponse::RequestShutdown(resp) => {
                state.serialize_element("request_shutdown")?;
                state.serialize_element(resp)?;
                state.end()
            }
        }
    }
}

impl Encoder<CommandResponse<'_>> for CommandCodec {
    type Error = io::Error;

    fn encode(&mut self, item: CommandResponse<'_>, dst: &mut BytesMut) -> Result<(), Self::Error> {
        let mut buf = Vec::new();
        let mut serializer = rmp_serde::Serializer::new(&mut buf);

        // The protocol supports responding with several messages, but actually
        // only one message is ever sent (see command_helpers.c)
        [item]
            .serialize(&mut serializer)
            .map_err(|e| io::Error::new(io::ErrorKind::InvalidData, e))?;

        let size = buf.len();
        if size > MAX_MESSAGE_SIZE as usize {
            return Err(io::Error::new(
                io::ErrorKind::InvalidData,
                format!(
                    "Message is too large: {} bytes (supported up to 4 MB)",
                    size
                ),
            ));
        }

        let size = size as u32;
        let header = Header {
            marker: Header::VALID_MARKER,
            size,
        };

        trace!("Encoding message with size {}: {:?}", size, fmt_bin(&buf));

        dst.extend_from_slice(header.as_slice());
        dst.reserve(size as usize);
        dst.extend_from_slice(&buf);

        Ok(())
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use libddwaf::waf_map;
    use rmp_serde::Serializer;
    use tokio_util::bytes::BytesMut;

    #[tokio::test]
    async fn test_command_deserialization() {
        fn serialize_message<T: serde::Serialize>(command: &T) -> Vec<u8> {
            let mut buf = Vec::new();
            let mut serializer = Serializer::new(&mut buf);
            command.serialize(&mut serializer).unwrap();

            let size = buf.len() as u32;
            let mut full_message = Vec::new();

            full_message.extend_from_slice(b"dds\0");
            full_message.extend_from_slice(&size.to_le_bytes());

            full_message.extend_from_slice(&buf);

            full_message
        }

        let client_init_args = (
            12345,
            "1.0.0",
            "8.0",
            Some(true),
            (
                Some("/path/to/rules"),
                1000,
                10,
                Option::<&str>::None,
                Some(".*"),
                (true, 0.5),
            ),
            (true, PathBuf::from("/dev/shm/remote")),
            ("my-service", "production"),
            ("session-123", "runtime-456"),
        );

        let valid_command = ("client_init", client_init_args);
        let valid_data = serialize_message(&valid_command);

        let mut decoder = CommandCodec;
        let mut buf = BytesMut::new();

        buf.extend_from_slice(&valid_data);
        let decoded = decoder.decode(&mut buf).unwrap();
        println!("{:?}", decoded);
        assert!(matches!(decoded, Some(Command::ClientInit(_))));
    }

    #[tokio::test]
    async fn test_command_response_serialization() {
        let resp = CommandResponse::ClientInit(ClientInitResp {
            status: "ok".to_string(),
            version: "1.0.0",
            errors: vec![],
            meta: HashMap::new(),
            metrics: HashMap::new(),
            tel_metrics: HashMap::new(),
        });

        let mut buf = BytesMut::new();
        let mut encoder = CommandCodec;
        encoder.encode(resp, &mut buf).unwrap();
    }

    fn serialize_message<T: serde::Serialize>(command: &T) -> Vec<u8> {
        let mut buf = Vec::new();
        let mut serializer = Serializer::new(&mut buf);
        command.serialize(&mut serializer).unwrap();

        let size = buf.len() as u32;
        let mut full_message = Vec::new();

        full_message.extend_from_slice(b"dds\0");
        full_message.extend_from_slice(&size.to_le_bytes());
        full_message.extend_from_slice(&buf);

        full_message
    }

    #[tokio::test]
    async fn test_config_sync_command() {
        let config_sync_args = ("/path/to/config", ("service", "env"));
        let command = ("config_sync", config_sync_args);
        let data = serialize_message(&command);

        let mut decoder = CommandCodec;
        let mut buf = BytesMut::new();
        buf.extend_from_slice(&data);

        let decoded = decoder.decode(&mut buf).unwrap();
        assert!(matches!(decoded, Some(Command::ConfigSync(_))));
        if let Some(Command::ConfigSync(args)) = decoded {
            assert_eq!(args.rem_cfg_path, "/path/to/config");
            assert_eq!(args.telemetry_settings.service_name, "service");
            assert_eq!(args.telemetry_settings.env_name, "env");
        }
    }

    #[tokio::test]
    async fn test_config_sync_response() {
        let resp = CommandResponse::ConfigSync;
        let mut buf = BytesMut::new();
        let mut encoder = CommandCodec;
        encoder.encode(resp, &mut buf).unwrap();
        assert!(!buf.is_empty());
    }

    #[tokio::test]
    async fn test_config_features_response() {
        let resp = CommandResponse::ConfigFeatures(ConfigFeaturesResp { enabled: true });
        let mut buf = BytesMut::new();
        let mut encoder = CommandCodec;
        encoder.encode(resp, &mut buf).unwrap();
        assert!(!buf.is_empty());
    }

    #[tokio::test]
    async fn test_request_init_command() {
        let waf_map = waf_map!(("foo", "bar"),);
        let command = ("request_init", (&waf_map,));
        let data = serialize_message(&command);

        let mut decoder = CommandCodec;
        let mut buf = BytesMut::new();
        buf.extend_from_slice(&data);

        let decoded = decoder.decode(&mut buf).unwrap();
        assert!(matches!(decoded, Some(Command::RequestInit(_))));
        if let Some(Command::RequestInit(args)) = decoded {
            assert_eq!(args.data, waf_map);
        }
    }

    #[tokio::test]
    async fn test_request_init_response() {
        let actions = vec![ActionInstance {
            action: "block",
            parameters: HashMap::from([("type".to_string(), "auto".to_string())]),
        }];
        let triggers = vec!["trigger1".to_string()];
        let resp = CommandResponse::RequestInit(RequestInitResp {
            actions: &actions,
            triggers: &triggers,
            force_keep: true,
            settings: HashMap::from([("setting1", "value1".to_string())]),
        });

        let mut buf = BytesMut::new();
        let mut encoder = CommandCodec;
        encoder.encode(resp, &mut buf).unwrap();
        assert!(!buf.is_empty());
    }

    #[tokio::test]
    async fn test_request_exec_command() {
        let waf_map = waf_map!(("foo", "bar"),);
        let options = (Some("rasp_rule"), Some("subctx_id"), false);

        let command = ("request_exec", (&waf_map, options));
        let data = serialize_message(&command);

        let mut decoder = CommandCodec;
        let mut buf = BytesMut::new();
        buf.extend_from_slice(&data);

        let decoded = decoder.decode(&mut buf).unwrap();
        assert!(matches!(decoded, Some(Command::RequestExec(_))));
        if let Some(Command::RequestExec(args)) = decoded {
            assert_eq!(args.data, waf_map);
            assert_eq!(
                args.options.run_type,
                WafRunType::RaspRule("rasp_rule".to_string())
            );
            assert_eq!(args.options.subctx_id, Some("subctx_id".to_string()));
            assert!(!args.options.subctx_last_call);
        }
    }

    #[tokio::test]
    async fn test_request_exec_response() {
        let resp = CommandResponse::RequestExec(RequestExecResp {
            actions: vec![],
            triggers: vec![],
            force_keep: false,
            settings: HashMap::new(),
        });

        let mut buf = BytesMut::new();
        let mut encoder = CommandCodec;
        encoder.encode(resp, &mut buf).unwrap();
        assert!(!buf.is_empty());
    }

    #[tokio::test]
    async fn test_request_shutdown_command() {
        let waf_map = waf_map!(("foo", "bar"),);
        let command = ("request_shutdown", (&waf_map, 12345u64, 67890u64));
        let data = serialize_message(&command);

        let mut decoder = CommandCodec;
        let mut buf = BytesMut::new();
        buf.extend_from_slice(&data);

        let decoded = decoder.decode(&mut buf).unwrap();
        assert!(matches!(decoded, Some(Command::RequestShutdown(_))));
        if let Some(Command::RequestShutdown(args)) = decoded {
            assert_eq!(args.data, waf_map);
            assert_eq!(args.api_sec_samp_key, 12345);
            assert_eq!(args.queue_id, 67890);
        }
    }

    #[tokio::test]
    async fn test_request_shutdown_response() {
        let mut tel_metrics = HashMap::new();
        tel_metrics.insert(
            "waf.requests".to_string(),
            vec![
                TelemetryMetric {
                    value: 1.0,
                    tags: "rule_triggered:true".to_string(),
                },
                TelemetryMetric {
                    value: 1.0,
                    tags: "rule_triggered:false".to_string(),
                },
            ],
        );

        let resp = CommandResponse::RequestShutdown(RequestShutdownResp {
            actions: vec![],
            triggers: vec![],
            force_keep: false,
            settings: HashMap::new(),
            meta: HashMap::from([("meta_key".into(), "meta_value".to_string())]),
            metrics: HashMap::from([("metric_key".into(), 123.45)]),
            tel_metrics,
        });

        let mut buf = BytesMut::new();
        let mut encoder = CommandCodec;
        encoder.encode(resp, &mut buf).unwrap();
        assert!(!buf.is_empty());
    }

    #[tokio::test]
    async fn test_error_response() {
        let resp = CommandResponse::ProtocolError;
        let mut buf = BytesMut::new();
        let mut encoder = CommandCodec;
        encoder.encode(resp, &mut buf).unwrap();
        assert!(!buf.is_empty());
    }
}
