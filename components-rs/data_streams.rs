//! Data Streams Monitoring (DSM) checkpoints.
//!
//! A checkpoint hashes where a message is in its pathway, measures how long the pathway and its
//! last edge have taken, and records a stats point for the sidecar to aggregate. Stats points go
//! into the sidecar's shared-memory DSM table once it exists, and over IPC until then, the same
//! way span stats reach their concentrator (see `stats.rs`).
//!
//! ## Hashing
//! As in the Go tracer, and as system-tests expects of tracers other than Go and Node.js:
//! - node hash: FNV-1 64 over the service, the env, the edge tags sorted, each process tag, and
//!   the container tags hash, concatenated without separators;
//! - pathway hash: FNV-1 64 over the node hash and then the parent's pathway hash, both
//!   little-endian.
//!
//! ## Propagation
//! `dd-pathway-ctx-base64` carries the pathway hash (8 bytes, little-endian), then the pathway
//! and edge start times in milliseconds since the Unix epoch as zigzag varints, base64-encoded
//! with padding - the format of the Java and Go tracers.
//!
//! ## The current pathway
//! Each PHP request (thread) keeps the pathway of its last checkpoint. A checkpoint into the
//! service (`direction:in`) takes its parent only from the context it was handed; any other
//! checkpoint takes the request's current pathway as its parent. Consecutive outbound
//! checkpoints share the parent from before the first of them, as in the Java tracer, so that
//! producing several messages does not chain each to the previous one.

use base64::alphabet;
use base64::engine::{DecodePaddingMode, GeneralPurpose, GeneralPurposeConfig};
use base64::Engine;
use datadog_sidecar::service::blocking::{add_dsm_stats_point, SidecarTransport};
use datadog_sidecar::service::data_streams_flusher::{dsm_table_shm_path, DsmTableKey, ShmProduct};
use libdd_common_ffi::slice::{AsBytes, CharSlice};
use libdd_ipc::shm_dsm::{DsmStatsPointInput, OwnedDsmStatsPoint, ShmDsmTable, MAX_EDGE_TAGS};
use std::cell::RefCell;
use std::collections::HashMap;
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::{Arc, LazyLock, OnceLock, RwLock};
use std::time::{SystemTime, UNIX_EPOCH};
use tracing::trace;

/// Longest base64 pathway context: 8 hash bytes and two 9-byte varints.
pub const DSM_CONTEXT_MAX_LEN: usize = 36;

/// Agent endpoint whose presence in `/info` says the agent accepts DSM stats.
const PIPELINE_STATS_ENDPOINT: &str = "/v0.1/pipeline_stats";

const FNV1_64_OFFSET: u64 = 0xcbf2_9ce4_8422_2325;
const FNV1_64_PRIME: u64 = 0x0000_0100_0000_01b3;

/// Standard alphabet, written with padding; read with or without it, as the Java tracer does.
const BASE64: GeneralPurpose = GeneralPurpose::new(
    &alphabet::STANDARD,
    GeneralPurposeConfig::new().with_decode_padding_mode(DecodePaddingMode::Indifferent),
);

fn fnv1_64(mut hash: u64, bytes: &[u8]) -> u64 {
    for &b in bytes {
        hash = hash.wrapping_mul(FNV1_64_PRIME);
        hash ^= u64::from(b);
    }
    hash
}

/// Hash of a checkpoint's node: the service, and the edge it is on. `edge_tags` must be sorted.
fn node_hash(
    service: &str,
    env: &str,
    edge_tags: &[&str],
    process_tags: &[&str],
    container_tags_hash: &str,
) -> u64 {
    let mut hash = fnv1_64(FNV1_64_OFFSET, service.as_bytes());
    hash = fnv1_64(hash, env.as_bytes());
    for tag in edge_tags {
        hash = fnv1_64(hash, tag.as_bytes());
    }
    for tag in process_tags {
        hash = fnv1_64(hash, tag.as_bytes());
    }
    fnv1_64(hash, container_tags_hash.as_bytes())
}

fn pathway_hash(node_hash: u64, parent_hash: u64) -> u64 {
    let hash = fnv1_64(FNV1_64_OFFSET, &node_hash.to_le_bytes());
    fnv1_64(hash, &parent_hash.to_le_bytes())
}

/// Where a message is in its pathway, and when it got there.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
struct PathwayContext {
    hash: u64,
    /// Nanoseconds since the Unix epoch.
    pathway_start_ns: i64,
    /// Nanoseconds since the Unix epoch.
    edge_start_ns: i64,
}

/// Varint as the sketches libraries (and so the Java and Go tracers) write it: LEB128 for the
/// first eight bytes, and a ninth byte, if needed, that keeps all eight of its bits.
fn put_varint(out: &mut Vec<u8>, mut value: u64) {
    for _ in 0..8 {
        if value < 0x80 {
            out.push(value as u8);
            return;
        }
        out.push((value as u8 & 0x7f) | 0x80);
        value >>= 7;
    }
    out.push(value as u8);
}

fn get_varint(bytes: &mut &[u8]) -> Option<u64> {
    let mut value = 0u64;
    for i in 0..8 {
        let (&b, rest) = bytes.split_first()?;
        *bytes = rest;
        value |= u64::from(b & 0x7f) << (7 * i);
        if b < 0x80 {
            return Some(value);
        }
    }
    let (&b, rest) = bytes.split_first()?;
    *bytes = rest;
    Some(value | u64::from(b) << 56)
}

fn zigzag(v: i64) -> u64 {
    ((v << 1) ^ (v >> 63)) as u64
}

fn unzigzag(v: u64) -> i64 {
    ((v >> 1) as i64) ^ -((v & 1) as i64)
}

impl PathwayContext {
    fn encode(&self) -> String {
        let mut bytes = Vec::with_capacity(26);
        bytes.extend_from_slice(&self.hash.to_le_bytes());
        put_varint(&mut bytes, zigzag(self.pathway_start_ns / 1_000_000));
        put_varint(&mut bytes, zigzag(self.edge_start_ns / 1_000_000));
        BASE64.encode(bytes)
    }

    /// `None` for anything that does not decode; trailing bytes are ignored.
    fn decode(encoded: &[u8]) -> Option<Self> {
        let bytes = BASE64.decode(encoded).ok()?;
        let (hash, mut rest) = bytes.split_first_chunk::<8>()?;
        let pathway_start_ms = unzigzag(get_varint(&mut rest)?);
        let edge_start_ms = unzigzag(get_varint(&mut rest)?);
        Some(PathwayContext {
            hash: u64::from_le_bytes(*hash),
            pathway_start_ns: pathway_start_ms.checked_mul(1_000_000)?,
            edge_start_ns: edge_start_ms.checked_mul(1_000_000)?,
        })
    }
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
enum Direction {
    In,
    Out,
}

fn direction(edge_tags: &[&str]) -> Option<Direction> {
    edge_tags.iter().find_map(|tag| match *tag {
        "direction:in" => Some(Direction::In),
        "direction:out" => Some(Direction::Out),
        _ => None,
    })
}

/// The pathway a request is on.
#[derive(Default)]
struct RequestPathway {
    current: Option<PathwayContext>,
    /// Direction of the last checkpoint, while consecutive outbound checkpoints are tracked.
    previous_direction: Option<Direction>,
    /// The pathway from before a run of outbound checkpoints, which each of them takes as parent.
    before_run: Option<PathwayContext>,
}

impl RequestPathway {
    /// Pick the parent of a checkpoint in `direction`, given the context it was handed.
    fn parent(
        &mut self,
        direction: Option<Direction>,
        carried: Option<PathwayContext>,
    ) -> Option<PathwayContext> {
        match direction {
            Some(Direction::In) => {
                self.previous_direction = Some(Direction::In);
                carried
            }
            Some(Direction::Out) => {
                if self.previous_direction != Some(Direction::Out) {
                    self.previous_direction = Some(Direction::Out);
                    self.before_run = self.current;
                }
                self.before_run
            }
            None => carried.or(self.current),
        }
    }
}

thread_local! {
    static REQUEST_PATHWAY: RefCell<RequestPathway> = RefCell::default();
}

/// A checkpoint, computed: the point to record and the context to hand on.
struct Checkpoint {
    context: PathwayContext,
    parent_hash: u64,
    pathway_latency_ns: i64,
    edge_latency_ns: i64,
}

fn checkpoint(node_hash: u64, parent: Option<PathwayContext>, now_ns: i64) -> Checkpoint {
    let (parent_hash, pathway_start_ns, edge_start_ns) =
        parent.map_or((0, now_ns, now_ns), |p| {
            (p.hash, p.pathway_start_ns, p.edge_start_ns)
        });
    Checkpoint {
        context: PathwayContext {
            hash: pathway_hash(node_hash, parent_hash),
            pathway_start_ns,
            edge_start_ns: now_ns,
        },
        parent_hash,
        pathway_latency_ns: now_ns.saturating_sub(pathway_start_ns),
        edge_latency_ns: now_ns.saturating_sub(edge_start_ns),
    }
}

/// Set once the agent's `/info` says it accepts DSM stats; points are dropped until then.
static AGENT_SUPPORTS_DSM: AtomicBool = AtomicBool::new(false);

pub(crate) fn apply_agent_endpoints(endpoints: Option<&[String]>) {
    let supported = endpoints.is_some_and(|endpoints| {
        endpoints
            .iter()
            .any(|e| e.trim_start_matches('/') == &PIPELINE_STATS_ENDPOINT[1..])
    });
    AGENT_SUPPORTS_DSM.store(supported, Ordering::Release);
}

/// Opened after the first IPC point for a key. In-flight fallbacks can outlive a cache clear.
static DSM_TABLES: LazyLock<RwLock<HashMap<String, Arc<OnceLock<ShmDsmTable>>>>> =
    LazyLock::new(RwLock::default);

/// Drop cached tables so the next point for each key goes through IPC.
#[no_mangle]
pub extern "C" fn ddog_dsm_tables_clear() {
    DSM_TABLES.write().unwrap().clear();
}

/// Forget the request's pathway. Called at request shutdown.
#[no_mangle]
pub extern "C" fn ddog_dsm_reset_pathway() {
    REQUEST_PATHWAY.with(|p| *p.borrow_mut() = RequestPathway::default());
}

/// The service a process's checkpoints are reported under, and what goes into their hashes.
#[repr(C)]
pub struct DsmIdentity<'a> {
    pub env: CharSlice<'a>,
    pub version: CharSlice<'a>,
    pub service: CharSlice<'a>,
    /// Comma-separated, sorted `key:value` process tags; empty when disabled.
    pub process_tags: CharSlice<'a>,
    /// From the agent; empty when unknown or when process tags are disabled.
    pub container_tags_hash: CharSlice<'a>,
}

fn str_of(s: CharSlice<'_>) -> &str {
    s.try_to_utf8().unwrap_or("")
}

fn record(
    transport: Option<&mut SidecarTransport>,
    identity: &DsmIdentity<'_>,
    point: &DsmStatsPointInput<'_>,
) {
    let (env, version, service) = (
        str_of(identity.env),
        str_of(identity.version),
        str_of(identity.service),
    );
    let map_key = format!("{env}\0{version}\0{service}");
    let reader = {
        let cached = DSM_TABLES.read().unwrap().get(&map_key).cloned();
        match cached {
            Some(reader) => reader,
            None => DSM_TABLES
                .write()
                .unwrap()
                .entry(map_key)
                .or_default()
                .clone(),
        }
    };
    if reader.get().is_some_and(|table| table.add_point(point)) {
        return;
    }

    // Sending can reconnect and clear the cache; no cache lock is held here.
    let Some(transport) = transport else {
        return;
    };
    let owned = OwnedDsmStatsPoint {
        hash: point.hash,
        parent_hash: point.parent_hash,
        edge_tags: point.edge_tags.iter().map(|t| (*t).to_owned()).collect(),
        pathway_latency_ns: point.pathway_latency_ns,
        edge_latency_ns: point.edge_latency_ns,
        payload_size_bytes: point.payload_size_bytes,
    };
    let key = DsmTableKey {
        product: ShmProduct::DataStreams,
        env: env.to_owned(),
        version: version.to_owned(),
        service: service.to_owned(),
        primary_tag: String::new(),
    };
    match add_dsm_stats_point(
        transport,
        key.env.clone(),
        key.version.clone(),
        key.service.clone(),
        key.primary_tag.clone(),
        owned,
    ) {
        Ok(true) if reader.get().is_none() => {
            if let Ok(table) = ShmDsmTable::open(&dsm_table_shm_path(&key)) {
                let _ = reader.set(table);
            }
        }
        Ok(_) => {}
        Err(e) => trace!("Failed to send DSM stats point via IPC: {e}"),
    }
}

fn now_ns() -> i64 {
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map_or(0, |d| i64::try_from(d.as_nanos()).unwrap_or(i64::MAX))
}

/// Set a checkpoint on the request's pathway and record its stats point.
///
/// `edge_tags` are `key:value` strings, at most `MAX_EDGE_TAGS` of which are kept; their order
/// does not matter. `carrier` is the `dd-pathway-ctx-base64` value the message arrived with, or
/// empty. The context to hand on with the message is written to `out` as base64, and its length
/// returned; `out` must hold `DSM_CONTEXT_MAX_LEN` bytes.
///
/// The stats point is only recorded once the agent has said it accepts DSM stats; the pathway
/// is tracked, and the context returned, either way.
///
/// # Safety
/// `transport` must be null or point to an exclusively borrowed `SidecarTransport`.
/// `edge_tags` must point to `edge_tag_count` valid slices, and `out` to `out_cap` bytes.
#[no_mangle]
pub unsafe extern "C" fn ddog_dsm_set_checkpoint(
    transport: *mut SidecarTransport,
    identity: &DsmIdentity,
    edge_tags: *const CharSlice,
    edge_tag_count: usize,
    carrier: CharSlice,
    payload_size_bytes: i64,
    out: *mut u8,
    out_cap: usize,
) -> usize {
    let edge_tags: &[CharSlice] = if edge_tags.is_null() {
        &[]
    } else {
        std::slice::from_raw_parts(edge_tags, edge_tag_count)
    };
    let mut tags: Vec<&str> = edge_tags
        .iter()
        .map(|t| str_of(*t))
        .filter(|t| !t.is_empty())
        .take(MAX_EDGE_TAGS)
        .collect();
    tags.sort_unstable();
    let process_tags: Vec<&str> = str_of(identity.process_tags)
        .split(',')
        .filter(|t| !t.is_empty())
        .collect();

    let node = node_hash(
        str_of(identity.service),
        str_of(identity.env),
        &tags,
        &process_tags,
        str_of(identity.container_tags_hash),
    );
    let carried = PathwayContext::decode(carrier.as_bytes());
    let now = now_ns();
    let checkpoint = REQUEST_PATHWAY.with(|p| {
        let mut p = p.borrow_mut();
        let parent = p.parent(direction(&tags), carried);
        let checkpoint = checkpoint(node, parent, now);
        p.current = Some(checkpoint.context);
        checkpoint
    });

    if AGENT_SUPPORTS_DSM.load(Ordering::Acquire) {
        record(
            transport.as_mut(),
            identity,
            &DsmStatsPointInput {
                hash: checkpoint.context.hash,
                parent_hash: checkpoint.parent_hash,
                edge_tags: &tags,
                pathway_latency_ns: checkpoint.pathway_latency_ns,
                edge_latency_ns: checkpoint.edge_latency_ns,
                payload_size_bytes,
            },
        );
    }

    let encoded = checkpoint.context.encode();
    if out.is_null() || encoded.len() > out_cap {
        return 0;
    }
    std::ptr::copy_nonoverlapping(encoded.as_ptr(), out, encoded.len());
    encoded.len()
}

#[cfg(test)]
mod tests {
    use super::*;

    fn sorted<'a>(tags: &[&'a str]) -> Vec<&'a str> {
        let mut tags = tags.to_vec();
        tags.sort_unstable();
        tags
    }

    /// The hashes system-tests expects of a Kafka produce and consume, for `DD_SERVICE=weblog`
    /// and `DD_ENV=system-tests` with process tags off (tests/integrations/test_dsm.py).
    #[test]
    fn hashes_match_system_tests() {
        let hash = |tags: &[&str], parent| {
            pathway_hash(
                node_hash("weblog", "system-tests", &sorted(tags), &[], ""),
                parent,
            )
        };
        let topic = "topic:dsm-system-tests-queue";

        let produce = hash(&["direction:out", topic, "type:kafka"], 0);
        assert_eq!(produce, 4463699290244539355);
        let consume = hash(
            &["type:kafka", topic, "group:testgroup1", "direction:in"],
            produce,
        );
        assert_eq!(consume, 3735318893869752335);

        let cluster = "kafka_cluster_id:5L6g3nShT-eMCtK--X86sw";
        let produce = hash(&["direction:out", cluster, topic, "type:kafka"], 0);
        assert_eq!(produce, 14216899112169674443);
        let consume = hash(
            &["direction:in", "group:testgroup1", cluster, topic, "type:kafka"],
            produce,
        );
        assert_eq!(consume, 4247242616665718048);
    }

    /// The Go tracer's golden base hash: FNV-1 over "svc", "env" and container hash "ch".
    #[test]
    fn node_hash_matches_go_base_hash() {
        assert_eq!(
            node_hash("svc", "env", &[], &[], "ch") as i64,
            -9046347542012827189
        );
    }

    /// Bytes from the Java tracer's `DefaultPathwayContextTest`: hash 0x14f6daf31b43e92f, both
    /// start times 50 ms.
    #[test]
    fn contexts_encode_as_the_java_tracer_does() {
        let context = PathwayContext {
            hash: 0x14f6_daf3_1b43_e92f,
            pathway_start_ns: 50_000_000,
            edge_start_ns: 50_000_000,
        };
        assert_eq!(context.encode(), "L+lDG/Pa9hRkZA==");
        assert_eq!(PathwayContext::decode(b"L+lDG/Pa9hRkZA=="), Some(context));
        assert_eq!(PathwayContext::decode(b"L+lDG/Pa9hRkZA"), Some(context));
    }

    /// The context system-tests hands a tracer to extract (Test_DsmContext_Extraction_Base64).
    #[test]
    fn the_system_tests_context_decodes() {
        let context = PathwayContext::decode(b"nMKD2ZEAtFOy/f/K5mOy/f/K5mM=").unwrap();
        assert_eq!(context.hash, 6031446427375485596);
        assert_eq!(context.pathway_start_ns, context.edge_start_ns);
        assert_eq!(context.encode(), "nMKD2ZEAtFOy/f/K5mOy/f/K5mM=");
    }

    #[test]
    fn varints_round_trip_through_the_ninth_byte() {
        for v in [0, 1, 127, 128, 1 << 42, u64::MAX >> 1, u64::MAX] {
            let mut bytes = Vec::new();
            put_varint(&mut bytes, v);
            assert!(bytes.len() <= 9);
            assert_eq!(get_varint(&mut bytes.as_slice()), Some(v), "{v}");
        }
        for v in [0, -1, 1, i64::MIN, i64::MAX] {
            assert_eq!(unzigzag(zigzag(v)), v);
        }
        let longest = PathwayContext {
            hash: u64::MAX,
            pathway_start_ns: i64::MIN,
            edge_start_ns: i64::MIN,
        };
        assert!(longest.encode().len() <= DSM_CONTEXT_MAX_LEN);
    }

    #[test]
    fn malformed_contexts_do_not_decode() {
        assert_eq!(PathwayContext::decode(b""), None);
        assert_eq!(PathwayContext::decode(b"not base64!"), None);
        // Seven bytes: shorter than the hash.
        assert_eq!(PathwayContext::decode(b"AAAAAAAAAA=="), None);
        // A hash with no timestamps.
        assert_eq!(PathwayContext::decode(b"AAAAAAAAAAA="), None);
    }

    fn at(hash: u64, ms: i64) -> PathwayContext {
        PathwayContext {
            hash,
            pathway_start_ns: ms * 1_000_000,
            edge_start_ns: ms * 1_000_000,
        }
    }

    #[test]
    fn an_inbound_checkpoint_takes_its_parent_only_from_the_message() {
        let mut p = RequestPathway {
            current: Some(at(1, 0)),
            ..Default::default()
        };
        assert_eq!(p.parent(Some(Direction::In), None), None);
        assert_eq!(p.parent(Some(Direction::In), Some(at(2, 0))), Some(at(2, 0)));
    }

    /// Producing twice after consuming links both produces to the consume, not the second
    /// produce to the first.
    #[test]
    fn consecutive_outbound_checkpoints_share_a_parent() {
        let consumed = at(7, 100);
        let mut p = RequestPathway::default();
        assert_eq!(p.parent(Some(Direction::In), Some(consumed)), Some(consumed));
        p.current = Some(at(8, 100));

        assert_eq!(p.parent(Some(Direction::Out), None), Some(at(8, 100)));
        p.current = Some(at(9, 100));
        assert_eq!(p.parent(Some(Direction::Out), None), Some(at(8, 100)));
        p.current = Some(at(10, 100));

        // A new inbound checkpoint ends the run.
        assert_eq!(p.parent(Some(Direction::In), None), None);
        p.current = Some(at(11, 200));
        assert_eq!(p.parent(Some(Direction::Out), None), Some(at(11, 200)));
    }

    #[test]
    fn a_first_checkpoint_starts_a_pathway() {
        let c = checkpoint(5, None, 1_000);
        assert_eq!(c.parent_hash, 0);
        assert_eq!(c.context.hash, pathway_hash(5, 0));
        assert_eq!((c.context.pathway_start_ns, c.context.edge_start_ns), (1_000, 1_000));
        assert_eq!((c.pathway_latency_ns, c.edge_latency_ns), (0, 0));

        let next = checkpoint(6, Some(c.context), 4_000);
        assert_eq!(next.parent_hash, c.context.hash);
        assert_eq!(next.context.pathway_start_ns, 1_000);
        assert_eq!(next.context.edge_start_ns, 4_000);
        assert_eq!((next.pathway_latency_ns, next.edge_latency_ns), (3_000, 3_000));
    }

    #[test]
    fn the_agent_endpoint_list_gates_recording() {
        apply_agent_endpoints(None);
        assert!(!AGENT_SUPPORTS_DSM.load(Ordering::Acquire));
        apply_agent_endpoints(Some(&["/v0.4/traces".into(), "/v0.1/pipeline_stats".into()]));
        assert!(AGENT_SUPPORTS_DSM.load(Ordering::Acquire));
        apply_agent_endpoints(Some(&["v0.1/pipeline_stats".into()]));
        assert!(AGENT_SUPPORTS_DSM.load(Ordering::Acquire));
        apply_agent_endpoints(Some(&["/v0.4/traces".into()]));
        assert!(!AGENT_SUPPORTS_DSM.load(Ordering::Acquire));
    }
}
