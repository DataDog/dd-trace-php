//! Data Streams Monitoring (DSM) checkpoints.
//!
//! A checkpoint hashes where a message is in its pathway, measures how long the pathway and its
//! last edge have taken, and records the checkpoint for the sidecar to aggregate. Checkpoints go
//! into the sidecar's shared-memory DSM checkpoint table once it exists, and over IPC until then,
//! the same way span stats reach their concentrator (see `stats.rs`).
//!
//! Backlogs - the offsets a Kafka producer has written up to, and a consumer group has
//! committed - take the same route into the sidecar's backlog table, which keeps the highest
//! offset per backlog for each bucket.
//!
//! ## Hashing
//! As in the Go tracer, and as system-tests expects of tracers other than Go and Node.js:
//! - node hash: FNV-1 64 over the service, the env, the edge tags sorted, and the base hash DBM
//!   also uses, which covers the process tags and the container tags hash, concatenated without
//!   separators;
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
//!
//! An integration that hands each message to a callback can push a fresh pathway before the
//! callback and pop it after, so a long-lived worker's checkpoints for one message neither take
//! the previous message's pathway nor leak it to what runs between messages.

use base64::alphabet;
use base64::engine::{DecodePaddingMode, GeneralPurpose, GeneralPurposeConfig};
use base64::Engine;
use datadog_sidecar::service::blocking::{
    add_dsm_backlog, add_dsm_checkpoint, set_kafka_cluster_id, SidecarTransport,
};
use datadog_sidecar::service::data_streams_flusher::{
    dsm_backlog_shm_path, dsm_checkpoint_shm_path, DsmTableKey,
};
use datadog_sidecar::service::kafka_cluster_ids::KafkaClusterIdReader;
use libdd_common_ffi::slice::{AsBytes, CharSlice};
use libdd_ipc::shm_dsm_checkpoint::{
    DsmCheckpointInput, OwnedDsmCheckpoint, ShmDsmCheckpointTable, MAX_EDGE_TAGS,
};
use libdd_ipc::shm_dsm_backlog::{DsmBacklogInput, ShmDsmBacklogTable};
use std::cell::RefCell;
use std::collections::HashMap;
use std::io;
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::{Arc, LazyLock, OnceLock, RwLock};
use std::time::{SystemTime, UNIX_EPOCH};
use tracing::trace;

/// Longest base64 pathway context: 8 hash bytes and two 9-byte varints.
pub const DSM_CONTEXT_MAX_LEN: usize = 36;

/// Agent endpoint whose presence in `/info` says the agent accepts DSM.
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
fn node_hash(service: &str, env: &str, edge_tags: &[&str], base_hash: &str) -> u64 {
    let mut hash = fnv1_64(FNV1_64_OFFSET, service.as_bytes());
    hash = fnv1_64(hash, env.as_bytes());
    for tag in edge_tags {
        hash = fnv1_64(hash, tag.as_bytes());
    }
    fnv1_64(hash, base_hash.as_bytes())
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
    /// Pathways set aside by `ddog_dsm_push_pathway`, innermost last. Pushes and pops must nest,
    /// so this isn't fiber safe: fibers that interleave on one thread restore each other's
    /// pathways. That's fine for now, as the only user, php-amqplib's `basic_consume`, calls back
    /// synchronously.
    static OUTER_PATHWAYS: RefCell<Vec<RequestPathway>> = RefCell::default();
    /// Kafka cluster ids by bootstrap servers, as the sidecar shares them between its processes;
    /// kept for the life of the process, unlike the request's pathway.
    static KAFKA_CLUSTER_IDS: RefCell<KafkaClusterIdReader> = RefCell::default();
}

/// A checkpoint, computed: what to record and the context to hand on.
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

/// Set once the agent's `/info` says it accepts DSM; checkpoints are dropped until then.
static AGENT_SUPPORTS_DSM: AtomicBool = AtomicBool::new(false);

pub(crate) fn apply_agent_endpoints(endpoints: Option<&[String]>) {
    let supported = endpoints.is_some_and(|endpoints| {
        endpoints
            .iter()
            .any(|e| e.trim_start_matches('/') == &PIPELINE_STATS_ENDPOINT[1..])
    });
    AGENT_SUPPORTS_DSM.store(supported, Ordering::Release);
}

/// A key's two tables, which the sidecar creates together.
struct DsmTables {
    checkpoints: ShmDsmCheckpointTable,
    backlogs: ShmDsmBacklogTable,
}

impl DsmTables {
    fn open(key: &DsmTableKey) -> Option<Self> {
        Some(DsmTables {
            checkpoints: ShmDsmCheckpointTable::open(&dsm_checkpoint_shm_path(key)).ok()?,
            backlogs: ShmDsmBacklogTable::open(&dsm_backlog_shm_path(key)).ok()?,
        })
    }
}

/// Opened after the first IPC checkpoint or backlog for a key. In-flight fallbacks can outlive a
/// cache clear.
static DSM_TABLES: LazyLock<RwLock<HashMap<String, Arc<OnceLock<DsmTables>>>>> =
    LazyLock::new(RwLock::default);

/// Drop cached tables so the next checkpoint for each key goes through IPC.
#[no_mangle]
pub extern "C" fn ddog_dsm_tables_clear() {
    DSM_TABLES.write().unwrap().clear();
}

/// Forget the request's pathway. Called at request shutdown.
#[no_mangle]
pub extern "C" fn ddog_dsm_reset_pathway() {
    REQUEST_PATHWAY.with(|p| *p.borrow_mut() = RequestPathway::default());
    OUTER_PATHWAYS.with(|o| o.borrow_mut().clear());
}

/// Set the request's pathway aside and start a fresh one, e.g. while a message's callback runs.
#[no_mangle]
pub extern "C" fn ddog_dsm_push_pathway() {
    let outer = REQUEST_PATHWAY.with(|p| std::mem::take(&mut *p.borrow_mut()));
    OUTER_PATHWAYS.with(|o| o.borrow_mut().push(outer));
}

/// Go back to the pathway the last `ddog_dsm_push_pathway` set aside, or to a fresh one if there
/// is none.
#[no_mangle]
pub extern "C" fn ddog_dsm_pop_pathway() {
    let outer = OUTER_PATHWAYS.with(|o| o.borrow_mut().pop()).unwrap_or_default();
    REQUEST_PATHWAY.with(|p| *p.borrow_mut() = outer);
}

/// The service a process's checkpoints are reported under, and what goes into their hashes.
#[repr(C)]
pub struct DsmIdentity<'a> {
    pub env: CharSlice<'a>,
    pub version: CharSlice<'a>,
    pub service: CharSlice<'a>,
    /// The base hash DBM injects (`datadog_process_tags_get_base_hash`), covering the process
    /// tags and the container tags hash; empty when process tags are disabled.
    pub base_hash: CharSlice<'a>,
}

fn str_of(s: CharSlice<'_>) -> &str {
    s.try_to_utf8().unwrap_or("")
}

/// Add to the identity's tables with `add`, or, until they are open, send over IPC with `send`
/// and open them once the sidecar has had the chance to create them.
fn record(
    transport: Option<&mut SidecarTransport>,
    identity: &DsmIdentity<'_>,
    add: impl Fn(&DsmTables) -> bool,
    send: impl FnOnce(&mut SidecarTransport, &DsmTableKey) -> io::Result<bool>,
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
    if reader.get().is_some_and(add) {
        return;
    }

    // Sending can reconnect and clear the cache; no cache lock is held here.
    let Some(transport) = transport else {
        return;
    };
    let key = DsmTableKey {
        env: env.to_owned(),
        version: version.to_owned(),
        service: service.to_owned(),
        primary_tag: String::new(),
    };
    match send(transport, &key) {
        Ok(true) if reader.get().is_none() => {
            if let Some(tables) = DsmTables::open(&key) {
                let _ = reader.set(tables);
            }
        }
        Ok(_) => {}
        Err(e) => trace!("Failed to send DSM data via IPC: {e}"),
    }
}

fn record_checkpoint(
    transport: Option<&mut SidecarTransport>,
    identity: &DsmIdentity<'_>,
    checkpoint: &DsmCheckpointInput<'_>,
) {
    record(
        transport,
        identity,
        |tables| tables.checkpoints.add_checkpoint(checkpoint),
        |transport, key| {
            let owned = OwnedDsmCheckpoint {
                hash: checkpoint.hash,
                parent_hash: checkpoint.parent_hash,
                edge_tags: checkpoint.edge_tags.iter().map(|t| (*t).to_owned()).collect(),
                pathway_latency_ns: checkpoint.pathway_latency_ns,
                edge_latency_ns: checkpoint.edge_latency_ns,
                payload_size_bytes: checkpoint.payload_size_bytes,
            };
            add_dsm_checkpoint(
                transport,
                key.env.clone(),
                key.version.clone(),
                key.service.clone(),
                key.primary_tag.clone(),
                owned,
            )
        },
    );
}

fn now_ns() -> i64 {
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map_or(0, |d| i64::try_from(d.as_nanos()).unwrap_or(i64::MAX))
}

/// Set a checkpoint on the request's pathway and record it.
///
/// `edge_tags` are `key:value` strings, at most `MAX_EDGE_TAGS` of which are kept; their order
/// does not matter. `carrier` is the `dd-pathway-ctx-base64` value the message arrived with, or
/// empty. The context to hand on with the message is written to `out` as base64, and its length
/// returned; `out` must hold `DSM_CONTEXT_MAX_LEN` bytes.
///
/// The checkpoint is only recorded once the agent has said it accepts DSM; the pathway is
/// tracked, and the context returned, either way.
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
    let node = node_hash(
        str_of(identity.service),
        str_of(identity.env),
        &tags,
        str_of(identity.base_hash),
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
        record_checkpoint(
            transport.as_mut(),
            identity,
            &DsmCheckpointInput {
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

/// Record `offset` for the backlog named by `tags`, e.g. a Kafka partition's produce offset or
/// a consumer group's committed one. The sidecar keeps the highest offset per backlog in each
/// bucket; negative offsets (unknown to librdkafka) are dropped.
///
/// `tags` are `key:value` strings in any order, each with a key `BacklogTag` knows (`topic`,
/// `type`, `kafka_cluster_id`, `consumer_group`, `partition`); every tag has a fixed position,
/// which is the order they are reported in. An offset with a tag of any other key is dropped
/// rather than reported under the tags that remain. Nothing is recorded until the agent has
/// said it accepts DSM.
///
/// # Safety
/// `transport` must be null or point to an exclusively borrowed `SidecarTransport`.
/// `tags` must point to `tag_count` valid slices.
#[no_mangle]
pub unsafe extern "C" fn ddog_dsm_track_backlog(
    transport: *mut SidecarTransport,
    identity: &DsmIdentity,
    tags: *const CharSlice,
    tag_count: usize,
    offset: i64,
) {
    if offset < 0 || !AGENT_SUPPORTS_DSM.load(Ordering::Acquire) {
        return;
    }
    let tags: &[CharSlice] = if tags.is_null() {
        &[]
    } else {
        std::slice::from_raw_parts(tags, tag_count)
    };
    let tags: Vec<&str> = tags
        .iter()
        .map(|t| str_of(*t))
        .filter(|t| !t.is_empty())
        .collect();
    let Some(backlog) = DsmBacklogInput::parse(&tags, offset) else {
        trace!("Dropping a DSM backlog with a tag it has no position for: {tags:?}");
        return;
    };
    record(
        transport.as_mut(),
        identity,
        |tables| tables.backlogs.add_backlog(&backlog),
        |transport, key| {
            add_dsm_backlog(
                transport,
                key.env.clone(),
                key.version.clone(),
                key.service.clone(),
                key.primary_tag.clone(),
                backlog.to_owned_backlog(),
            )
        },
    );
}

/// Write the id of the cluster the Kafka clients with `bootstrap_servers` reach to `out`, if this
/// process or another process of the sidecar has read it from such a client, and return its
/// length; 0 if no process has, or it does not fit in `out_cap` bytes.
///
/// # Safety
/// `out` must point to `out_cap` writable bytes.
#[no_mangle]
pub unsafe extern "C" fn ddog_dsm_get_kafka_cluster_id(
    bootstrap_servers: CharSlice,
    out: *mut u8,
    out_cap: usize,
) -> usize {
    KAFKA_CLUSTER_IDS.with(|ids| {
        let mut ids = ids.borrow_mut();
        match ids.get(str_of(bootstrap_servers)) {
            Some(id) if id.len() <= out_cap => {
                std::ptr::copy_nonoverlapping(id.as_ptr(), out, id.len());
                id.len()
            }
            _ => 0,
        }
    })
}

/// Record that the Kafka clients with `bootstrap_servers` reach the cluster `cluster_id`, as the
/// caller read it from one of them, and share it with the sidecar's other processes the first
/// time.
///
/// # Safety
/// `transport` must be null or point to an exclusively borrowed `SidecarTransport`.
#[no_mangle]
pub unsafe extern "C" fn ddog_dsm_set_kafka_cluster_id(
    transport: *mut SidecarTransport,
    bootstrap_servers: CharSlice,
    cluster_id: CharSlice,
) {
    let (bootstrap_servers, cluster_id) = (str_of(bootstrap_servers), str_of(cluster_id));
    let news = KAFKA_CLUSTER_IDS.with(|ids| ids.borrow_mut().learn(bootstrap_servers, cluster_id));
    if let (true, Some(transport)) = (news, transport.as_mut()) {
        let sent = set_kafka_cluster_id(transport, bootstrap_servers.into(), cluster_id.into());
        if !matches!(sent, Ok(true)) {
            trace!("Could not share the Kafka cluster id of {bootstrap_servers} with the sidecar");
        }
    }
}

/// Follow the sidecar the process now talks to, for the cluster ids it shares.
pub(crate) fn reconnect_kafka_cluster_ids() {
    KAFKA_CLUSTER_IDS.with(|ids| ids.borrow().reconnect());
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
                node_hash("weblog", "system-tests", &sorted(tags), ""),
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

    /// The hashes system-tests expects of RabbitMQ publishes and consumes through a direct, a
    /// topic and a fanout exchange (Test_DsmRabbitmq*), for the same service and env.
    #[test]
    fn rabbitmq_hashes_match_system_tests() {
        let hash = |tags: &[&str], parent| {
            pathway_hash(
                node_hash("weblog", "system-tests", &sorted(tags), ""),
                parent,
            )
        };
        let publish = |exchange: &str, has_routing_key| {
            hash(
                &[
                    "direction:out",
                    &format!("exchange:{exchange}"),
                    &format!("has_routing_key:{has_routing_key}"),
                    "type:rabbitmq",
                ],
                0,
            )
        };
        let consume = |queue: &str, parent| {
            hash(
                &["direction:in", &format!("topic:{queue}"), "type:rabbitmq"],
                parent,
            )
        };

        let direct = publish("dsm-system-tests-exchange", true);
        assert_eq!(direct, 8945717757344503539);
        assert_eq!(consume("dsm-system-tests-queue", direct), 247866491670975357);

        let topic = publish("systemTestTopicExchange", true);
        assert_eq!(topic, 14115675228093516275);
        assert_eq!(consume("systemTestRabbitmqTopicQueue1", topic), 17456221739323133462);
        assert_eq!(consume("systemTestRabbitmqTopicQueue2", topic), 6709599110187021152);
        assert_eq!(consume("systemTestRabbitmqTopicQueue3", topic), 4518514625593640902);

        let fanout = publish("systemTestFanoutExchange", false);
        assert_eq!(fanout, 528013466165804625);
        assert_eq!(consume("systemTestRabbitmqFanoutQueue1", fanout), 1551162056316679489);
        assert_eq!(consume("systemTestRabbitmqFanoutQueue2", fanout), 5919279740143028634);
        assert_eq!(consume("systemTestRabbitmqFanoutQueue3", fanout), 10096313447786601025);
    }

    /// The Go tracer's golden base hash: FNV-1 over "svc", "env" and "ch".
    #[test]
    fn node_hash_matches_go_base_hash() {
        assert_eq!(
            node_hash("svc", "env", &[], "ch") as i64,
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

    fn current() -> Option<PathwayContext> {
        REQUEST_PATHWAY.with(|p| p.borrow().current)
    }

    fn set_current(context: PathwayContext) {
        REQUEST_PATHWAY.with(|p| {
            let mut p = p.borrow_mut();
            p.parent(Some(Direction::In), Some(context));
            p.current = Some(context);
        });
    }

    /// A pushed pathway starts empty, and popping it brings back the outer pathway along with
    /// its run of outbound checkpoints.
    #[test]
    fn a_pushed_pathway_is_set_aside_until_popped() {
        // Consume at(1), then produce at(9) with at(1) as its parent.
        set_current(at(1, 0));
        REQUEST_PATHWAY.with(|p| {
            let mut p = p.borrow_mut();
            p.parent(Some(Direction::Out), None);
            p.current = Some(at(9, 0));
        });

        ddog_dsm_push_pathway();
        assert_eq!(current(), None);
        set_current(at(2, 0));
        ddog_dsm_push_pathway();
        set_current(at(3, 0));

        ddog_dsm_pop_pathway();
        assert_eq!(current(), Some(at(2, 0)));
        ddog_dsm_pop_pathway();
        assert_eq!(current(), Some(at(9, 0)));
        // The next produce is still in the run, so it shares the first produce's parent.
        let parent = REQUEST_PATHWAY.with(|p| p.borrow_mut().parent(Some(Direction::Out), None));
        assert_eq!(parent, Some(at(1, 0)));

        // With nothing pushed, popping leaves a fresh pathway.
        ddog_dsm_pop_pathway();
        assert_eq!(current(), None);
    }

    #[test]
    fn a_reset_drops_pushed_pathways() {
        set_current(at(1, 0));
        ddog_dsm_push_pathway();
        ddog_dsm_reset_pathway();
        ddog_dsm_pop_pathway();
        assert_eq!(current(), None);
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

    /// Without a sidecar, a process still uses the cluster ids it learned itself.
    #[test]
    fn a_learned_kafka_cluster_id_is_used() {
        let servers = CharSlice::from("kafka-test:9092");
        let mut out = [0u8; 64];
        let get = |out: &mut [u8]| unsafe {
            ddog_dsm_get_kafka_cluster_id(servers, out.as_mut_ptr(), out.len())
        };
        assert_eq!(get(&mut out), 0);

        unsafe {
            ddog_dsm_set_kafka_cluster_id(
                std::ptr::null_mut(),
                servers,
                CharSlice::from("5L6g3nShT-eMCtK--X86sw"),
            )
        };
        let len = get(&mut out);
        assert_eq!(&out[..len], b"5L6g3nShT-eMCtK--X86sw");
        assert_eq!(get(&mut out[..4]), 0, "too long for the buffer");
    }
}
