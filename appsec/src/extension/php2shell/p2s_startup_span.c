// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2026 Datadog, Inc.

// The startup span (see DESIGN.md, section 11). A deferred fatal from a startup
// window fails the request startup before any module RINIT, so the request has
// no root span, and php-fpm or php-cgi exit right after. Before raising, under
// the finite limit, the request builds $_SERVER and copies what its span needs
// into one slot of shared memory, with the identity of the failed process
// (process id, runtime id, hostname), which the span reports instead of the
// sending process's. The next request that reaches AppSec's RINIT with tracing
// and root span generation on takes the event and has the tracer add the span
// as a trace of its own.
//
// The slots live in one anonymous shared mapping created at module startup,
// before php-fpm, Apache or any other SAPI forks its workers: a directory and
// one slot for each of up to DD_P2S_POOLS pools. A pool is told apart by the
// SAPI, the effective user and, under php-fpm, the pool name read from the
// process title. At its first activate, after php-fpm or Apache switched to the
// pool's user and before any request data or user code, each forked process
// finds or claims its pool's entry in the directory and unmaps the directory
// and every other slot, as php-fpm does with the scoreboards of other pools.
// A request can therefore read or send only an event of its own pool, and PHP
// code running in a pool cannot reach the other pools' slots or redirect their
// future workers. A forked process still maps everything until its first
// activate, which comes with its first request, so the mapping is kept out of
// core dumps. The process that created the mapping keeps all of it for the
// processes it forks later.
//
// Saving runs no tracer code beyond configuration reads and a copy of the
// runtime id, and builds only $_SERVER. It never allocates once the slot is
// claimed, writes at most the slot's size and never waits for another worker:
// every transition of the slot is one compare-and-swap on a single state word,
// retried a bounded number of times.
//
// The state word holds the phase (empty, writing, full, reading), the time of
// the last claim (to reclaim the slot from a worker that died in the middle of
// an operation), and a generation incremented on every claim (so that a worker
// declared dead and resumed later cannot publish over the new owner). An event
// is taken by exactly one reader (the transition to reading), so its span is
// sent at most once. A separate counter, incremented without a loop, holds the
// startup fatals that could not be saved, mostly because the slot was taken
// (the dropped count); the reader that sends a span takes it as a tag of that
// span. The writer computes the payload's checksum from the data it copies,
// not from the slot, so the stray stores of a resumed writer can only make a
// newer event fail the check, and such an event is dropped and counted.

#include "p2s_startup_span.h"

#include <SAPI.h>
#include <errno.h>
#include <fcntl.h>
#include <inttypes.h>
#include <php.h>
#include <stdatomic.h>
#include <stdint.h>
#include <string.h>
#include <sys/mman.h>
#include <time.h>
#include <unistd.h>
#ifdef ZTS
#    include <pthread.h>
#endif

#include <ext/compatibility.h>
#include <ext/standard/crc32.h>
#include <zai_string/string.h>

#include "../configuration.h" // get_DD_*
#include "../ddappsec.h"      // PG
#include "../dddefs.h"        // ARRAY_SIZE
#include "../logging.h"
#include "../tracer_bridge.h"
#include "p2s_oom_guard.h" // DD_P2S_DEFERRED_FORMAT, dd_deferred_phase
#include "p2s_policy.h"    // dd_p2s_policy_armed
#include "p2s_report.h"    // DD_P2S_TAG_DEFERRED

#define DD_P2S_SLOT_SIZE ((size_t)64 * 1024)

enum {
    // The state word, the dropped count, the payload length and its checksum.
    DD_SLOT_HEADER_SIZE = 24,
    DD_PAYLOAD_HEADER_SIZE = 40,
    DD_PAYLOAD_HEADER_PAD = 3,
    DD_RECORD_HEADER_SIZE = 8,
    DD_STARTUP_SPAN_STATUS = 500,
};
#define DD_TAG_STARTUP_DROPPED "_dd.appsec.php2shell.startup_dropped"
// Not web.request: the span's duration includes reading and parsing the
// request data, which no other request's root span covers, so trace metrics
// must not mix it into the endpoint's web.request series.
#define DD_STARTUP_SPAN_NAME "web.request.startup_failure"

// A lock-based atomic would protect nothing across processes.
_Static_assert(ATOMIC_LONG_LOCK_FREE == 2 && ATOMIC_LLONG_LOCK_FREE == 2,
    "64-bit atomics must be lock free");

// Layout of the shared mapping. Host byte order throughout: it never leaves
// the machine and is read by the same binary.
//
//   Region, mapped once at module startup, before the workers fork:
//
//   offset 0 +--------------------------------------+
//            | dd_p2s_directory                     |  keys[64]: the key of
//            |                                      |  the pool owning slot i,
//            |                                      |  0 while unclaimed;
//            |                                      |  full_warned
//            +--------------------------------------+  rounded up to a page
//            | slot 0                        64 KiB |
//            +--------------------------------------+
//            | slot 1                        64 KiB |
//            +--------------------------------------+
//            :  ...                                 :
//            +--------------------------------------+
//            | slot 63                       64 KiB |
//            +--------------------------------------+
//
//   After its first activation, a forked process keeps only its pool's slot
//   mapped (the creating process keeps the whole region).
//
//   Slot (dd_p2s_slot):
//
//   0          8          16       20         24                       64 KiB
//   +----------+----------+--------+----------+------------ ... ----------+
//   | state    | dropped  | len    | checksum | payload (len bytes)       |
//   | u64      | u64      | u32    | u32      |                           |
//   +----------+----------+--------+----------+------------ ... ----------+
//
//   State word (dd_slot_state):
//
//   63         40 39      32 31                              2 1     0
//   +------------+----------+---------------------------------+-------+
//   | unused     | gen      | since: CLOCK_MONOTONIC seconds  | phase |
//   |            |          | at the last claim, mod 2^30     |       |
//   +------------+----------+---------------------------------+-------+
//   phase: 0 empty, 1 writing, 2 full, 3 reading
//
//   Payload:
//
//    0 +-------------------+-------------------+
//      | version     u16   | flags       u16   |  flags: a record was left
//    4 +-------------------+-------------------+  out; the URI or query was
//      | record_count                    u32   |  cut
//    8 +---------------------------------------+
//      | start_ns: Unix epoch, ns        u64   |
//   16 +---------------------------------------+
//      | duration_ns: at least 1         u64   |
//   24 +---------------------------------------+
//      | breached_limit: bytes           u64   |
//   32 +---------+-------------+---------------+
//      | phase u8| pad[3]      | process_id u32|
//   40 +---------+-------------+---------------+
//      | record 1                              |
//      +----+-----+-----------+----------------+
//      |kind| pad | key_len   | value_len      |  dd_record_header, 8 bytes
//      | u8 | u8  | u16       | u32            |
//      +----+-----+-----------+----------------+
//      | key bytes, then value bytes           |  no NUL, no alignment
//      +---------------------------------------+
//      | record 2 ...                          |
//      :                                       :
//
//   Records in write order (a record is written whole or left out):
//     fields (kind 0): method, service, env, version, runtime_id, hostname
//     $_SERVER entries (kind 1): REQUEST_METHOD, HTTPS, SERVER_NAME,
//       REMOTE_ADDR; the distributed tracing headers; REQUEST_URI and
//       QUERY_STRING (room reserved, cut if needed); the headers the root
//       span reads; then every other HTTP_* header that fits.
typedef struct {
    // The state word (a dd_slot_state); all zero (empty) when the slot is
    // mapped.
    _Atomic(uint64_t) state;
    // Startup fatals not saved since the last event was taken.
    _Atomic(uint64_t) dropped;
    // Payload bytes and their CRC-32, stored before the event is published.
    uint32_t len;
    uint32_t checksum;
    uint8_t payload[DD_P2S_SLOT_SIZE - DD_SLOT_HEADER_SIZE];
} dd_p2s_slot;
_Static_assert(sizeof(dd_p2s_slot) == DD_P2S_SLOT_SIZE, "slot size");

// The pools of one server that can each have a slot. The processes of any
// further pool send no startup span.
enum { DD_P2S_POOLS = 64 };

// The start of the mapping, followed by the slots. Entry i is the key of the
// pool that owns slot i (see _pool_key), or 0 while unclaimed. Entries are
// claimed with one compare-and-swap and never cleared, so the claimed entries
// are always a prefix and a key never appears twice: a second claimant would
// have seen the first one's entry while scanning.
typedef struct {
    _Atomic(uint64_t) keys[DD_P2S_POOLS];
    // Set by the first process that finds the directory full, which warns.
    _Atomic(uint32_t) full_warned;
} dd_p2s_directory;

// The phase of the slot.
typedef enum {
    DD_SLOT_EMPTY = 0,
    DD_SLOT_WRITING = 1,
    DD_SLOT_FULL = 2,
    DD_SLOT_READING = 3,
} dd_slot_phase;

// The state word. Its layout is the compiler's, which is fine: the slot is only
// read by the same binary. `unused` is named so that initializers zero it.
typedef union {
    uint64_t word;
    struct {
        uint64_t phase : 2; // a dd_slot_phase
        // The seconds of CLOCK_MONOTONIC at the last claim, modulo 2^30.
        uint64_t since : 30;
        uint64_t gen : 8;
        uint64_t unused : 24;
    };
} dd_slot_state;
_Static_assert(sizeof(dd_slot_state) == sizeof(uint64_t), "state word size");

enum {
    DD_STATE_SINCE_MASK = (1U << 30U) - 1,
    // A slot left writing or reading for this long belongs to a worker that
    // died or was stopped in the middle of the operation.
    DD_STALE_SECONDS = 10,
    // Bound of every compare-and-swap loop: the slot never blocks a worker.
    DD_CAS_ATTEMPTS = 64,
    DD_PAYLOAD_VERSION = 1,
    // Payload flags: a record was left out; the URI or the query was cut.
    DD_FLAG_LEFT_OUT = 1,
    DD_FLAG_URI_CUT = 2,
    // Record kinds: a field of the request; a $_SERVER entry.
    DD_RECORD_FIELD = 0,
    DD_RECORD_SERVER = 1,
    DD_NS_PER_SEC = 1000000000,
    // HOST_NAME_MAX on Linux, plus the terminating NUL.
    DD_HOSTNAME_SIZE = 256,
    // Bytes of the process title read to find the php-fpm pool name.
    DD_TITLE_SIZE = 256,
    // The effective user's place in a pool key.
    DD_KEY_UID_SHIFT = 32,
};

// The payload starts with this header, followed by `record_count` records,
// each a dd_record_header, then the key bytes, then the value bytes (no NUL).
// Host byte order and layout: the payload never leaves the machine and is read
// by the same binary. Read and written with memcpy (no alignment assumption).
typedef struct {
    uint16_t version;
    uint16_t flags;
    uint32_t record_count;
    // Unix epoch of the request start, in nanoseconds.
    uint64_t start_ns;
    // From the request start to the raise; at least 1.
    uint64_t duration_ns;
    // The memory_limit that was crossed, in bytes.
    uint64_t breached_limit;
    uint8_t phase;
    uint8_t pad[DD_PAYLOAD_HEADER_PAD];
    // The process that failed, which a php-fpm or php-cgi worker is no longer
    // by the time another request sends its span.
    uint32_t process_id;
} dd_payload_header;
_Static_assert(
    sizeof(dd_payload_header) == DD_PAYLOAD_HEADER_SIZE, "payload header size");

typedef struct {
    uint8_t kind;
    uint8_t pad;
    uint16_t key_len;
    uint32_t value_len;
} dd_record_header;
_Static_assert(
    sizeof(dd_record_header) == DD_RECORD_HEADER_SIZE, "record header size");

// A bounded cursor over the payload. A record is written whole or not at all,
// leaving `reserve` bytes for the records that must follow (the URI and the
// query string). `crc` is the running CRC-32 of the records written so far,
// computed from the source data.
typedef struct {
    uint8_t *nonnull buf;
    size_t cap;
    size_t pos;
    size_t reserve;
    uint32_t count;
    uint16_t flags;
    uint32_t crc;
} dd_writer;

// The request fields (records of kind DD_RECORD_FIELD), and their keys.
typedef enum {
    DD_FIELD_METHOD,
    DD_FIELD_SERVICE,
    DD_FIELD_ENV,
    DD_FIELD_VERSION,
    DD_FIELD_RUNTIME_ID,
    // Present only if the failed request reported its hostname.
    DD_FIELD_HOSTNAME,
    DD_FIELD_COUNT,
} dd_field;

static const zai_str _field_keys[DD_FIELD_COUNT] = {
    [DD_FIELD_METHOD] = ZAI_STRL("method"),
    [DD_FIELD_SERVICE] = ZAI_STRL("service"),
    [DD_FIELD_ENV] = ZAI_STRL("env"),
    [DD_FIELD_VERSION] = ZAI_STRL("version"),
    [DD_FIELD_RUNTIME_ID] = ZAI_STRL("runtime_id"),
    [DD_FIELD_HOSTNAME] = ZAI_STRL("hostname"),
};

// An event read back from a payload.
typedef struct {
    dd_payload_header hdr;
    zval server;
    zend_string *nullable fields[DD_FIELD_COUNT];
} dd_event;

// The headers that ddtrace_read_distributed_tracing_ids() reads (see
// tracer/distributed_tracing_headers.c). They are saved before the URI and
// every other header, so that the incoming trace context survives a
// truncation.
static const zai_str _dt_headers[] = {
    ZAI_STRL("HTTP_X_DATADOG_TRACE_ID"),
    ZAI_STRL("HTTP_X_DATADOG_PARENT_ID"),
    ZAI_STRL("HTTP_X_DATADOG_ORIGIN"),
    ZAI_STRL("HTTP_X_DATADOG_SAMPLING_PRIORITY"),
    ZAI_STRL("HTTP_X_DATADOG_TAGS"),
    ZAI_STRL("HTTP_B3"),
    ZAI_STRL("HTTP_X_B3_TRACEID"),
    ZAI_STRL("HTTP_X_B3_SPANID"),
    ZAI_STRL("HTTP_X_B3_SAMPLED"),
    ZAI_STRL("HTTP_X_B3_FLAGS"),
    ZAI_STRL("HTTP_TRACEPARENT"),
    ZAI_STRL("HTTP_TRACESTATE"),
    ZAI_STRL("HTTP_BAGGAGE"),
};

// The headers the root span's properties read, after the distributed tracing
// ones: host, user agent, referrer, and the client IP headers (see
// tracer/ip_extraction.c); the configured client IP header comes with them.
static const zai_str _root_headers[] = {
    ZAI_STRL("HTTP_HOST"),
    ZAI_STRL("HTTP_USER_AGENT"),
    ZAI_STRL("HTTP_REFERER"),
    ZAI_STRL("HTTP_X_FORWARDED_FOR"),
    ZAI_STRL("HTTP_X_REAL_IP"),
    ZAI_STRL("HTTP_FORWARDED"),
    ZAI_STRL("HTTP_TRUE_CLIENT_IP"),
    ZAI_STRL("HTTP_X_CLIENT_IP"),
    ZAI_STRL("HTTP_X_FORWARDED"),
    ZAI_STRL("HTTP_FORWARDED_FOR"),
    ZAI_STRL("HTTP_X_CLUSTER_CLIENT_IP"),
    ZAI_STRL("HTTP_FASTLY_CLIENT_IP"),
    ZAI_STRL("HTTP_CF_CONNECTING_IP"),
    ZAI_STRL("HTTP_CF_CONNECTING_IPV6"),
};

// $_SERVER entries other than headers: the scheme, the host without a Host
// header, and the client IP without any client IP header.
static const zai_str _server_fields[] = {
    ZAI_STRL("REQUEST_METHOD"),
    ZAI_STRL("HTTPS"),
    ZAI_STRL("SERVER_NAME"),
    ZAI_STRL("REMOTE_ADDR"),
};

// The initial value of a running CRC-32; the final value is its complement.
static const uint32_t _crc_init = ~0U;

// The slot of this process's pool, or NULL. Set at the first activate of each
// process (see _bind), cleared at MSHUTDOWN.
static dd_p2s_slot *nullable _slot;

// What this process maps, and the user it was bound as. Written at module
// startup and MSHUTDOWN, which are single-threaded, and while binding, under
// _bind_mutex on ZTS. A thread reads it only after its own activate saw
// _bound_pid equal to its process id, which orders it after the binding.
static struct {
    // The whole mapping (directory and slots) while `table` is set; after the
    // binding of a forked process, only its slot, or nothing.
    uint8_t *nullable base;
    size_t size;
    // The offset of slot 0, past the directory, rounded to the page size.
    size_t dir_size;
    bool table;
    pid_t creator_pid;
    uid_t bound_uid;
} _region;

// The process that last bound _slot: a process forked after that (by php-fpm,
// Apache or pcntl_fork) sees another pid and binds again at its first activate.
// A pid rather than a once flag: fork() copies a once flag.
static _Atomic(pid_t) _bound_pid;
#ifdef ZTS
static pthread_mutex_t _bind_mutex = PTHREAD_MUTEX_INITIALIZER;
#endif

// The start of the current request, recorded at activate.
ZEND_TLS struct {
    uint64_t real_ns;
    uint64_t mono_ns;
} _request_start;

// ---- forward declarations (implementation details) ------------------------

static bool _bind(void);
static void _bind_locked(pid_t pid);
static int _find_or_claim(dd_p2s_directory *nonnull dir, uint64_t key);
static void _keep_only(dd_p2s_slot *nullable slot);
static uint64_t _pool_key(uid_t euid);
static ssize_t _fpm_title(char *nonnull buf, size_t cap);
static bool _same_user(void);

static void _drop(const char *nonnull reason);
static zend_array *nullable _build_server(void);
static bool _may_claim(void);
static void _serialize(dd_writer *nonnull w, zend_array *nonnull server);
static void _put_identity(dd_writer *nonnull w);
static void _put_server_entries(
    dd_writer *nonnull w, zend_array *nonnull server);
static void _put(dd_writer *nonnull w, uint8_t kind, const char *nonnull key,
    size_t key_len, const char *nullable value, size_t value_len);
static void _put_field(dd_writer *nonnull w, dd_field field,
    const char *nullable value, size_t value_len);
static void _put_uri_field(
    dd_writer *nonnull w, zend_array *nonnull server, zai_str name);
static void _put_headers(dd_writer *nonnull w, zend_array *nonnull server,
    const zai_str *nonnull names, size_t count);
static void _put_header(dd_writer *nonnull w, zend_array *nonnull server,
    const char *nonnull name, size_t name_len);
static bool _is_listed(zend_string *nonnull key, zend_string *nullable ip_key);
static bool _in_list(
    zend_string *nonnull key, const zai_str *nonnull names, size_t count);
static bool _claim(uint8_t *nonnull gen);
static void _publish(uint8_t gen);
static bool _take(
    uint8_t *nonnull buf, size_t *nonnull len, uint64_t *nonnull dropped);
static void _count_dropped(uint64_t count);
static bool _parse(
    const uint8_t *nonnull buf, size_t len, dd_event *nonnull event);
static void _parse_field(dd_event *nonnull event, zai_str key,
    const char *nonnull value, size_t value_len);
static void _event_free(dd_event *nonnull event);
static bool _add_span(dd_event *nonnull event, uint64_t dropped);
static uint32_t _now_seconds(void);
static bool _claimable(dd_slot_state s, uint32_t now);
static uint64_t _clock_ns(clockid_t clock);
static uint32_t _crc_update(uint32_t crc, const void *nonnull data, size_t len);
static uint32_t _payload_checksum(const uint8_t *nonnull payload, size_t len);

// ---- lifecycle -------------------------------------------------------------

void dd_p2s_startup_span_create(void)
{
    // Every startup maps new slots: Apache runs module startup again after a
    // restart, and statics may survive it; a pending event of the previous
    // generation is lost.
    memset(&_region, 0, sizeof(_region));
    _slot = NULL;
    atomic_store_explicit(&_bound_pid, 0, memory_order_relaxed);

    // A forked process unmaps every slot but its own, so slots must start and
    // end on page boundaries.
    long page = sysconf(_SC_PAGESIZE);
    if (page <= 0 || DD_P2S_SLOT_SIZE % (size_t)page != 0) {
        mlog(dd_log_warning,
            "PHP2Shell mitigation: unsupported page size %ld; requests failed "
            "in the startup window will send no span",
            page);
        return;
    }
    size_t dir_size = (sizeof(dd_p2s_directory) + (size_t)page - 1) /
                      (size_t)page * (size_t)page;
    size_t size = dir_size + (DD_P2S_POOLS * sizeof(dd_p2s_slot));
    void *mem = mmap(
        NULL, size, PROT_READ | PROT_WRITE, MAP_SHARED | MAP_ANONYMOUS, -1, 0);
    if (mem == MAP_FAILED) {
        mlog_err(dd_log_warning,
            "PHP2Shell mitigation: could not map the startup span slots; "
            "requests failed in the startup window will send no span");
        return;
    }
#ifdef MADV_DONTDUMP
    // The slots hold the request headers of every pool: keep them out of core
    // dumps, which ddtrace has include shared anonymous memory. Forked
    // processes inherit this.
    (void)madvise(mem, size, MADV_DONTDUMP);
#endif
    _region.base = mem;
    _region.size = size;
    _region.dir_size = dir_size;
    _region.table = true;
    _region.creator_pid = getpid();
}

void dd_p2s_startup_span_destroy(void)
{
    // Only this process's view goes: a php-fpm worker whose startup failed
    // runs MSHUTDOWN right after saving its event, which another worker sends.
    if (_region.base != NULL) {
        (void)munmap(_region.base, _region.size);
    }
    memset(&_region, 0, sizeof(_region));
    _slot = NULL;
    atomic_store_explicit(&_bound_pid, 0, memory_order_relaxed);
}

void dd_p2s_startup_span_activate(void)
{
    if (!_bind()) {
        return;
    }
    // The start is only needed if the startup window can arm, and nothing
    // changes the WAF's decision before that parse. Clear it otherwise, so
    // that no earlier request's start is ever used.
    if (!dd_p2s_policy_armed()) {
        _request_start.real_ns = 0;
        _request_start.mono_ns = 0;
        return;
    }
    _request_start.real_ns = _clock_ns(CLOCK_REALTIME);
    _request_start.mono_ns = _clock_ns(CLOCK_MONOTONIC);
}

// ---- binding a process to its pool's slot ---------------------------------

// Bind this process to its pool's slot if it has not done so yet; whether it
// has a slot. The first activate of a forked process runs after php-fpm or
// Apache switched to the pool's user and applied its settings, and before any
// request data is parsed or any user code runs, so no PHP code of the pool sees
// the other pools' slots.
static bool _bind(void)
{
    if (_region.creator_pid == 0) {
        // No mapping was created: the feature is off.
        return false;
    }
    pid_t pid = getpid();
    if (atomic_load_explicit(&_bound_pid, memory_order_acquire) != pid) {
#ifdef ZTS
        // The other threads of a fresh process wait for the binding.
        (void)pthread_mutex_lock(&_bind_mutex);
#endif
        if (atomic_load_explicit(&_bound_pid, memory_order_relaxed) != pid) {
            _bind_locked(pid);
            atomic_store_explicit(&_bound_pid, pid, memory_order_release);
        }
#ifdef ZTS
        (void)pthread_mutex_unlock(&_bind_mutex);
#endif
    }
    return _slot != NULL;
}

static void _bind_locked(pid_t pid)
{
    if (!_region.table) {
        // Forked by pcntl_fork() from a bound process, or a process with no
        // slot: it keeps what its parent had.
        return;
    }
    uid_t euid = geteuid();
    dd_p2s_directory *dir = (dd_p2s_directory *)_region.base;
    uint64_t key = _pool_key(euid);
    int index = key != 0 ? _find_or_claim(dir, key) : -1;
    dd_p2s_slot *slot = NULL;
    if (index >= 0) {
        slot = (dd_p2s_slot *)(_region.base + _region.dir_size +
                               ((size_t)index * sizeof(dd_p2s_slot)));
    } else if (key != 0 && atomic_exchange_explicit(&dir->full_warned, 1,
                               memory_order_relaxed) == 0) {
        mlog(dd_log_warning,
            "PHP2Shell mitigation: more than %d pools share this server; "
            "requests of the others failed in the startup window will send "
            "no span",
            DD_P2S_POOLS);
    }
    _region.bound_uid = euid;

    if (pid == _region.creator_pid) {
        // A server that handles requests in the process that created the
        // mapping (php -S, FrankenPHP, httpd -X, opcache preload in a php-fpm
        // master): it keeps the directory and every slot for the processes
        // it forks later.
        _slot = slot;
        return;
    }
    _keep_only(slot);
}

// Find this pool's entry in the directory, or claim the first free one; -1 if
// the directory is full.
static int _find_or_claim(dd_p2s_directory *nonnull dir, uint64_t key)
{
    for (int i = 0; i < DD_P2S_POOLS; i++) {
        uint64_t cur =
            atomic_load_explicit(&dir->keys[i], memory_order_acquire);
        if (cur == 0) {
            uint64_t expected = 0;
            if (atomic_compare_exchange_strong_explicit(&dir->keys[i],
                    &expected, key, memory_order_acq_rel,
                    memory_order_acquire)) {
                return i;
            }
            cur = expected;
        }
        if (cur == key) {
            return i;
        }
    }
    return -1;
}

// Unmap the directory and every slot but `slot` (all of them if NULL), so that
// the pool's code can neither read the other pools' events nor redirect their
// future processes through the directory.
static void _keep_only(dd_p2s_slot *nullable slot)
{
    uint8_t *base = _region.base;
    uint8_t *end = base + _region.size;
    _region.table = false;
    if (slot != NULL) {
        uint8_t *start = (uint8_t *)slot;
        uint8_t *stop = start + sizeof(dd_p2s_slot);
        if (munmap(base, (size_t)(start - base)) == 0) {
            if (stop == end || munmap(stop, (size_t)(end - stop)) == 0) {
                _region.base = start;
                _region.size = sizeof(dd_p2s_slot);
                _slot = slot;
                return;
            }
            // Other threads may already have mapped memory where the
            // directory and the slots before ours were: unmap only what is
            // still ours.
            base = start;
        }
        mlog_err(dd_log_warning,
            "PHP2Shell mitigation: could not unmap the startup span slots of "
            "other pools; requests failed in the startup window will send no "
            "span");
    }
    (void)munmap(base, (size_t)(end - base));
    _region.base = NULL;
    _region.size = 0;
    _slot = NULL;
}

// The pool of this process: the effective user in the high half, and in the
// low half the low 32 bits of PHP's string hash of the php-fpm worker's title
// (which holds the pool name) or, under another SAPI or without the title, of
// the SAPI name. A hash collision can only merge two pools of the same user.
// 0 for a php-fpm process that is not a pool's worker, such as the opcache
// preload process: it serves no request, and must not use up one of the
// directory's entries.
static uint64_t _pool_key(uid_t euid)
{
    const char *name = sapi_module.name != NULL ? sapi_module.name : "";
    size_t len = strlen(name);
    char title[DD_TITLE_SIZE];
    if (strcmp(name, "fpm-fcgi") == 0) {
        ssize_t title_len = _fpm_title(title, sizeof(title));
        if (title_len < 0) {
            return 0;
        }
        if (title_len > 0) {
            name = title;
            len = (size_t)title_len;
        }
    }
    uint32_t hash = (uint32_t)zend_inline_hash_func(name, len);
    if (hash == 0) {
        hash = 1;
    }
    return ((uint64_t)euid << DD_KEY_UID_SHIFT) | hash;
}

// The title php-fpm gives its workers, "php-fpm: pool <name>", read from
// /proc/self/cmdline (the same format from PHP 7.0 to 8.5); its length. 0 if
// the title cannot be read, as without /proc in a chroot: the pools of one user
// then share a slot. -1 if the title is not a worker's.
static ssize_t _fpm_title(char *nonnull buf, size_t cap)
{
    static const char prefix[] = "php-fpm: pool ";
    int fd;
    do {
        fd = open("/proc/self/cmdline", O_RDONLY | O_CLOEXEC);
    } while (fd < 0 && errno == EINTR);
    if (fd < 0) {
        mlog_err(dd_log_debug, "PHP2Shell mitigation: could not open the "
                               "process title; the startup span slot is "
                               "shared by the pools of this user");
        return 0;
    }
    ssize_t read_len;
    do {
        read_len = read(fd, buf, cap - 1);
    } while (read_len < 0 && errno == EINTR);
    (void)close(fd);
    if (read_len <= 0) {
        mlog(dd_log_debug, "PHP2Shell mitigation: could not read the process "
                           "title; the startup span slot is shared by the "
                           "pools of this user");
        return 0;
    }
    buf[(size_t)read_len] = '\0';
    size_t len = strlen(buf);
    if (len < sizeof(prefix) || strncmp(buf, prefix, sizeof(prefix) - 1) != 0) {
        mlog(dd_log_debug,
            "PHP2Shell mitigation: process title '%s' is not a php-fpm "
            "worker's; this process gets no startup span slot",
            buf);
        return -1;
    }
    return (ssize_t)len;
}

// Whether this process still runs as the user it was bound as. A process that
// changes user between requests (mod_ruid2) saves and sends events only for
// the first user it served.
static bool _same_user(void) { return geteuid() == _region.bound_uid; }

// ---- saving ----------------------------------------------------------------

void dd_p2s_startup_span_save(size_t breached_limit)
{
    // The configuration of the failed request is already resolved: the
    // zend_extension activate hook ran zai_config_rinit() before the startup
    // parse. These are configuration reads, not tracer code.
    if (_slot == NULL || !_same_user() || !get_DD_TRACE_ENABLED() ||
        !get_DD_TRACE_GENERATE_ROOT_SPAN()) {
        return;
    }

    // A slot that holds an event, or another worker's event being written or
    // read, cannot take this one: count it without building $_SERVER.
    if (!_may_claim()) {
        _drop("the startup span slot is taken");
        return;
    }

    zend_array *server = _build_server();
    if (server == NULL) {
        _drop("could not build $_SERVER for the startup span");
        return;
    }

    uint64_t now_mono = _clock_ns(CLOCK_MONOTONIC);
    uint64_t start_ns = _request_start.real_ns;
    uint64_t duration_ns = 1;
    if (start_ns != 0 && now_mono > _request_start.mono_ns) {
        duration_ns = now_mono - _request_start.mono_ns;
    } else if (start_ns == 0) {
        start_ns = _clock_ns(CLOCK_REALTIME);
    }

    // From the claim to the publish nothing allocates, so no bailout can leave
    // the slot claimed; a crash there is what the stale reclaim is for.
    uint8_t gen;
    if (!_claim(&gen)) {
        _drop("the startup span slot is taken");
        return;
    }

    dd_writer w = {
        .buf = _slot->payload,
        .cap = sizeof(_slot->payload),
        .pos = sizeof(dd_payload_header),
        .crc = _crc_init,
    };
    _serialize(&w, server);

    dd_payload_header hdr = {
        .version = DD_PAYLOAD_VERSION,
        .flags = w.flags,
        .record_count = w.count,
        .start_ns = start_ns,
        .duration_ns = duration_ns,
        .breached_limit = breached_limit,
        .phase = DD_PHASE_STARTUP,
        .process_id = (uint32_t)getpid(),
    };
    memcpy(_slot->payload, &hdr, sizeof(hdr));
    _slot->len = (uint32_t)w.pos;
    // From the local copies, not from the slot (see the top of the file).
    _slot->checksum = ~_crc_update(w.crc, &hdr, sizeof(hdr));

    _publish(gen);
    mlog(dd_log_debug,
        "PHP2Shell mitigation: saved the startup span (%u records, %zu bytes)",
        w.count, w.pos);
}

// Count a startup fatal that is not saved.
static void _drop(const char *nonnull reason)
{
    _count_dropped(1);
    mlog(dd_log_debug, "PHP2Shell mitigation: %s; counted as dropped", reason);
}

// $_SERVER, built now if it does not exist yet. No other superglobal is read:
// $_GET, $_POST or $_COOKIE may not exist yet, and building one would run the
// parse of the request data again, inside the raise.
static zend_array *nullable _build_server(void)
{
    zval *server = &PG(http_globals)[TRACK_VARS_SERVER];
    if (Z_TYPE_P(server) != IS_ARRAY) {
        // $_SERVER can be disarmed with nothing built. Building it disarms it
        // until zend_activate_auto_globals() re-arms it at the next request
        // startup, but this raise bails out of the $_GET, $_POST or $_COOKIE
        // callback in that very loop, before it reaches $_SERVER, so a request
        // that follows any request that built $_SERVER (a normal one, or an
        // earlier startup fatal here) finds it still disarmed. PHP 8.3.28,
        // 8.4.15 and later arm every auto global before running any callback.
        zend_auto_global *auto_global =
            zend_hash_str_find_ptr(CG(auto_globals), ZEND_STRL("_SERVER"));
        if (auto_global != NULL) {
            auto_global->armed = 1;
        }
    }

    bool ok = true;
    zend_try
    {
        // Not a parse of request data: no window opens for it (the treat_data
        // wrapper arms only GET, POST and COOKIE), and its size is bounded by
        // the request headers and the environment.
        (void)zend_is_auto_global_str((char *)ZEND_STRL("_SERVER"));
    }
    zend_catch { ok = false; }
    zend_end_try();
    if (!ok) {
        // The bailout may have come in the middle of an insertion, leaving the
        // array inconsistent: never destroy it. The request's memory is freed
        // as a whole when it ends.
        ZVAL_UNDEF(server);
        return NULL;
    }
    return Z_TYPE_P(server) == IS_ARRAY ? Z_ARRVAL_P(server) : NULL;
}

// Whether _claim() can succeed now.
static bool _may_claim(void)
{
    dd_slot_state s = {
        .word = atomic_load_explicit(&_slot->state, memory_order_relaxed)};
    return _claimable(s, _now_seconds());
}

static const zai_str _uri_name = ZAI_STRL("REQUEST_URI");
static const zai_str _query_name = ZAI_STRL("QUERY_STRING");

// In this order: the request fields, the $_SERVER entries that are not
// headers, the distributed tracing headers, the URI and the query string (cut
// only if they do not fit), the headers of the root span's properties, then
// every other header while there is room. Room for the URI and query records
// is reserved from the start, so they are always present, if empty, and the
// tracer never falls back to the carrier's own request info.
static void _serialize(dd_writer *nonnull w, zend_array *nonnull server)
{
    w->reserve =
        (2 * sizeof(dd_record_header)) + _uri_name.len + _query_name.len;

    const char *method = SG(request_info).request_method;
    if (method != NULL) {
        _put_field(w, DD_FIELD_METHOD, method, strlen(method));
    }
    zend_string *service = get_DD_SERVICE();
    _put_field(w, DD_FIELD_SERVICE, ZSTR_VAL(service), ZSTR_LEN(service));
    zend_string *env = get_DD_ENV();
    _put_field(w, DD_FIELD_ENV, ZSTR_VAL(env), ZSTR_LEN(env));
    zend_string *version = get_DD_VERSION();
    _put_field(w, DD_FIELD_VERSION, ZSTR_VAL(version), ZSTR_LEN(version));
    _put_identity(w);

    _put_headers(w, server, _server_fields, ARRAY_SIZE(_server_fields));
    _put_headers(w, server, _dt_headers, ARRAY_SIZE(_dt_headers));
    _put_uri_field(w, server, _uri_name);
    _put_uri_field(w, server, _query_name);

    _put_server_entries(w, server);
}

// The runtime id of the failed process and, if the failed request reports it,
// its hostname, found as the tracer finds it for a root span (see
// ddtrace_set_root_span_properties). The process id is in the payload header.
static void _put_identity(dd_writer *nonnull w)
{
    char runtime_id[DD_TRACE_RUNTIME_ID_LEN];
    dd_trace_format_runtime_id(runtime_id);
    _put_field(w, DD_FIELD_RUNTIME_ID, runtime_id, sizeof(runtime_id));

    if (!get_DD_TRACE_REPORT_HOSTNAME()) {
        return;
    }
    zend_string *configured = get_DD_HOSTNAME();
    if (ZSTR_LEN(configured) > 0) {
        _put_field(
            w, DD_FIELD_HOSTNAME, ZSTR_VAL(configured), ZSTR_LEN(configured));
        return;
    }
    char hostname[DD_HOSTNAME_SIZE];
    if (gethostname(hostname, sizeof(hostname)) != 0) {
        return;
    }
    // POSIX does not promise a terminating NUL on truncation.
    hostname[sizeof(hostname) - 1] = '\0';
    _put_field(w, DD_FIELD_HOSTNAME, hostname, strlen(hostname));
}

// The headers after the URI: those of the root span's properties, then every
// other one while there is room.
static void _put_server_entries(
    dd_writer *nonnull w, zend_array *nonnull server)
{
    _put_headers(w, server, _root_headers, ARRAY_SIZE(_root_headers));
    // Already HTTP_<NAME> (see ddtrace_parse_client_ip_header_config).
    zend_string *ip_key = get_DD_TRACE_CLIENT_IP_HEADER();
    if (ZSTR_LEN(ip_key) == 0) {
        ip_key = NULL;
    } else if (!_is_listed(ip_key, NULL)) {
        _put_header(w, server, ZSTR_VAL(ip_key), ZSTR_LEN(ip_key));
    }

    zend_string *key;
    zval *value;
    ZEND_HASH_FOREACH_STR_KEY_VAL(server, key, value)
    {
        if (key == NULL || Z_TYPE_P(value) != IS_STRING ||
            ZSTR_LEN(key) == sizeof("HTTP_") - 1 ||
            !zend_string_starts_with_literal(key, "HTTP_") ||
            _is_listed(key, ip_key)) {
            continue;
        }
        _put(w, DD_RECORD_SERVER, ZSTR_VAL(key), ZSTR_LEN(key),
            Z_STRVAL_P(value), Z_STRLEN_P(value));
    }
    ZEND_HASH_FOREACH_END();
}

// Append a record if it fits whole, outside the reserved room; otherwise flag
// it as left out.
static void _put(dd_writer *nonnull w, uint8_t kind, const char *nonnull key,
    size_t key_len, const char *nullable value, size_t value_len)
{
    size_t need = sizeof(dd_record_header) + key_len + value_len;
    if (key_len > UINT16_MAX || value_len > UINT32_MAX ||
        need > w->cap - w->pos - w->reserve) {
        w->flags |= DD_FLAG_LEFT_OUT;
        return;
    }
    dd_record_header rec = {
        .kind = kind,
        .key_len = (uint16_t)key_len,
        .value_len = (uint32_t)value_len,
    };
    uint8_t *out = w->buf + w->pos;
    memcpy(out, &rec, sizeof(rec));
    out += sizeof(rec);
    memcpy(out, key, key_len);
    out += key_len;
    w->crc = _crc_update(w->crc, &rec, sizeof(rec));
    w->crc = _crc_update(w->crc, key, key_len);
    if (value_len > 0) {
        memcpy(out, value, value_len);
        w->crc = _crc_update(w->crc, value, value_len);
    }
    w->pos += need;
    w->count++;
}

static void _put_field(dd_writer *nonnull w, dd_field field,
    const char *nullable value, size_t value_len)
{
    zai_str key = _field_keys[field];
    _put(w, DD_RECORD_FIELD, key.ptr, key.len, value, value_len);
}

// REQUEST_URI or QUERY_STRING, in the room reserved for it: saved as an empty
// string if absent, and cut to the room left if it does not fit whole.
static void _put_uri_field(
    dd_writer *nonnull w, zend_array *nonnull server, zai_str name)
{
    size_t overhead = sizeof(dd_record_header) + name.len;
    w->reserve -= overhead;
    zval *value = zend_hash_str_find(server, name.ptr, name.len);
    const char *str = "";
    size_t len = 0;
    if (value != NULL && Z_TYPE_P(value) == IS_STRING) {
        str = Z_STRVAL_P(value);
        len = Z_STRLEN_P(value);
    }
    size_t room = w->cap - w->pos - w->reserve - overhead;
    if (len > room) {
        len = room;
        w->flags |= DD_FLAG_URI_CUT;
    }
    _put(w, DD_RECORD_SERVER, name.ptr, name.len, str, len);
}

static void _put_headers(dd_writer *nonnull w, zend_array *nonnull server,
    const zai_str *nonnull names, size_t count)
{
    for (size_t i = 0; i < count; i++) {
        _put_header(w, server, names[i].ptr, names[i].len);
    }
}

static void _put_header(dd_writer *nonnull w, zend_array *nonnull server,
    const char *nonnull name, size_t name_len)
{
    zval *value = zend_hash_str_find(server, name, name_len);
    if (value == NULL || Z_TYPE_P(value) != IS_STRING) {
        return;
    }
    _put(w, DD_RECORD_SERVER, name, name_len, Z_STRVAL_P(value),
        Z_STRLEN_P(value));
}

// Whether a header was already saved ahead of the others.
static bool _is_listed(zend_string *nonnull key, zend_string *nullable ip_key)
{
    return (ip_key != NULL && zend_string_equals(key, ip_key)) ||
           _in_list(key, _dt_headers, ARRAY_SIZE(_dt_headers)) ||
           _in_list(key, _root_headers, ARRAY_SIZE(_root_headers));
}

static bool _in_list(
    zend_string *nonnull key, const zai_str *nonnull names, size_t count)
{
    for (size_t i = 0; i < count; i++) {
        if (zend_string_equals_cstr(key, names[i].ptr, names[i].len)) {
            return true;
        }
    }
    return false;
}

// ---- the slot protocol -----------------------------------------------------

// Claim the slot to write an event: if it is empty, or left writing or reading
// by a worker that died. Otherwise, or when the attempts run out, false: the
// caller counts the event as dropped.
static bool _claim(uint8_t *nonnull gen)
{
    uint64_t word = atomic_load_explicit(&_slot->state, memory_order_acquire);
    for (int i = 0; i < DD_CAS_ATTEMPTS; i++) {
        dd_slot_state s = {.word = word};
        uint32_t now = _now_seconds();
        if (!_claimable(s, now)) {
            return false;
        }
        dd_slot_state next = {
            .phase = DD_SLOT_WRITING,
            .since = now,
            .gen = (uint8_t)(s.gen + 1),
        };
        if (atomic_compare_exchange_weak_explicit(&_slot->state, &word,
                next.word, memory_order_acq_rel, memory_order_acquire)) {
            // The event of a dead writer, or one a dead reader had taken, is
            // lost: count it. A reader that was only stopped and resumes
            // still sends it, so that event is then reported twice.
            if (s.phase != DD_SLOT_EMPTY) {
                _count_dropped(1);
            }
            *gen = next.gen;
            return true;
        }
    }
    return false;
}

// Wait-free: the count never contends with the transitions of the state word.
static void _count_dropped(uint64_t count)
{
    (void)atomic_fetch_add_explicit(
        &_slot->dropped, count, memory_order_relaxed);
}

// Make the written event visible to readers, unless the slot was reclaimed from
// us in the meantime (it then has another generation).
static void _publish(uint8_t gen)
{
    uint64_t word = atomic_load_explicit(&_slot->state, memory_order_relaxed);
    for (int i = 0; i < DD_CAS_ATTEMPTS; i++) {
        dd_slot_state s = {.word = word};
        if (s.phase != DD_SLOT_WRITING || s.gen != gen) {
            return;
        }
        s.phase = DD_SLOT_FULL;
        if (atomic_compare_exchange_weak_explicit(&_slot->state, &word, s.word,
                memory_order_release, memory_order_relaxed)) {
            return;
        }
    }
}

// Take the event: copy its payload into `buf` (the size of the payload area),
// clear it and empty the slot. `dropped` gets the startup fatals not saved
// since the previous event was taken. A corrupt event is counted with them,
// and they are left for the next event's span.
// NOLINTBEGIN(bugprone-easily-swappable-parameters)
static bool _take(
    uint8_t *nonnull buf, size_t *nonnull len, uint64_t *nonnull dropped)
// NOLINTEND(bugprone-easily-swappable-parameters)
{
    uint64_t word = atomic_load_explicit(&_slot->state, memory_order_relaxed);
    dd_slot_state taken = {0};
    bool won = false;
    for (int i = 0; i < DD_CAS_ATTEMPTS && !won; i++) {
        taken = (dd_slot_state){.word = word};
        if (taken.phase != DD_SLOT_FULL) {
            return false;
        }
        taken.phase = DD_SLOT_READING;
        taken.since = _now_seconds();
        won = atomic_compare_exchange_weak_explicit(&_slot->state, &word,
            taken.word, memory_order_acquire, memory_order_relaxed);
    }
    if (!won) {
        return false;
    }

    size_t stored_len = _slot->len;
    uint32_t stored_checksum = _slot->checksum;
    bool valid = stored_len >= sizeof(dd_payload_header) &&
                 stored_len <= sizeof(_slot->payload);
    if (valid) {
        memcpy(buf, _slot->payload, stored_len);
    }

    // The payload holds request headers: do not leave them in shared memory
    // once they are sent, unless the slot was declared stale and reclaimed by
    // a writer in the meantime.
    word = atomic_load_explicit(&_slot->state, memory_order_relaxed);
    dd_slot_state now_state = {.word = word};
    if (now_state.phase == DD_SLOT_READING && now_state.gen == taken.gen) {
        memset(
            _slot->payload, 0, valid ? stored_len : sizeof(dd_payload_header));
        _slot->len = 0;
        _slot->checksum = 0;
    }
    *dropped =
        atomic_exchange_explicit(&_slot->dropped, 0, memory_order_relaxed);
    for (int i = 0; i < DD_CAS_ATTEMPTS; i++) {
        dd_slot_state s = {.word = word};
        if (s.phase != DD_SLOT_READING || s.gen != taken.gen) {
            // Declared stale and reclaimed by a writer: the slot is no longer
            // ours to release.
            break;
        }
        dd_slot_state empty = {.phase = DD_SLOT_EMPTY, .gen = s.gen};
        if (atomic_compare_exchange_weak_explicit(&_slot->state, &word,
                empty.word, memory_order_release, memory_order_relaxed)) {
            break;
        }
    }

    if (!valid || _payload_checksum(buf, stored_len) != stored_checksum) {
        mlog(dd_log_debug, "PHP2Shell mitigation: the startup span slot held "
                           "a corrupt event; counted as dropped");
        _count_dropped(*dropped + 1);
        return false;
    }
    *len = stored_len;
    return true;
}

static uint32_t _now_seconds(void)
{
    return (uint32_t)((_clock_ns(CLOCK_MONOTONIC) / DD_NS_PER_SEC) &
                      DD_STATE_SINCE_MASK);
}

// Whether a slot in this state can be claimed: it is empty, or left writing or
// reading by a worker that died.
static bool _claimable(dd_slot_state s, uint32_t now)
{
    return s.phase == DD_SLOT_EMPTY ||
           (s.phase != DD_SLOT_FULL &&
               ((now - s.since) & DD_STATE_SINCE_MASK) >= DD_STALE_SECONDS);
}

static uint64_t _clock_ns(clockid_t clock)
{
    struct timespec ts;
    if (clock_gettime(clock, &ts) != 0) {
        return 0;
    }
    return ((uint64_t)ts.tv_sec * DD_NS_PER_SEC) + (uint64_t)ts.tv_nsec;
}

// PHP's CRC-32 (the one crc32() computes), continuing from the running value
// `crc`. Before PHP 8.1 the engine exports no function for it, so the table
// that the header defines is used directly.
static uint32_t _crc_update(uint32_t crc, const void *nonnull data, size_t len)
{
#if PHP_VERSION_ID >= 80100
    return php_crc32_bulk_update(crc, data, len);
#else
    const unsigned char *bytes = data;
    for (size_t i = 0; i < len; i++) { CRC32(crc, bytes[i]); }
    return crc;
#endif
}

// The checksum of a payload as the writer computes it: the records, then the
// header, which is written last.
static uint32_t _payload_checksum(const uint8_t *nonnull payload, size_t len)
{
    uint32_t crc = _crc_update(_crc_init, payload + sizeof(dd_payload_header),
        len - sizeof(dd_payload_header));
    return ~_crc_update(crc, payload, sizeof(dd_payload_header));
}

// ---- sending ---------------------------------------------------------------

void dd_p2s_startup_span_carry(void)
{
    if (_slot == NULL) {
        return;
    }
    // The state first: the slot is almost always empty, and the user check is
    // a system call.
    dd_slot_state s = {
        .word = atomic_load_explicit(&_slot->state, memory_order_relaxed)};
    if (s.phase != DD_SLOT_FULL || !_same_user()) {
        return;
    }
    // A request that cannot send the span leaves the event for a later one.
    if (!dd_trace_enabled() || !dd_trace_root_span_generation_enabled()) {
        return;
    }

    // Allocated before the event is taken, so that a bailout cannot leave the
    // slot in the reading phase.
    uint8_t *buf = emalloc(sizeof(_slot->payload));
    size_t len = 0;
    uint64_t dropped = 0;
    if (!_take(buf, &len, &dropped)) {
        efree(buf);
        return;
    }

    dd_event event;
    bool parsed = _parse(buf, len, &event);
    efree(buf);
    if (!parsed) {
        // Left for the next event's span, with this one.
        _count_dropped(dropped + 1);
        mlog(dd_log_debug, "PHP2Shell mitigation: could not parse the event "
                           "in the startup span slot; counted as dropped");
        return;
    }
    if (event.hdr.flags & DD_FLAG_LEFT_OUT) {
        mlog(dd_log_debug, "PHP2Shell mitigation: some request data of the "
                           "startup span did not fit in the slot");
    }
    if (event.hdr.flags & DD_FLAG_URI_CUT) {
        mlog(dd_log_debug, "PHP2Shell mitigation: the URI or the query string "
                           "of the startup span was cut to fit in the slot");
    }
    if (!_add_span(&event, dropped)) {
        _count_dropped(dropped + 1);
    }
    _event_free(&event);
}

// Read an event back, checking every length against the payload.
static bool _parse(
    const uint8_t *nonnull buf, size_t len, dd_event *nonnull event)
{
    memset(event, 0, sizeof(*event));
    memcpy(&event->hdr, buf, sizeof(event->hdr));
    if (event->hdr.version != DD_PAYLOAD_VERSION) {
        return false;
    }
    array_init(&event->server);

    size_t pos = sizeof(dd_payload_header);
    for (uint32_t i = 0; i < event->hdr.record_count; i++) {
        dd_record_header rec;
        if (len - pos < sizeof(rec)) {
            _event_free(event);
            return false;
        }
        memcpy(&rec, buf + pos, sizeof(rec));
        pos += sizeof(rec);
        if (len - pos < (size_t)rec.key_len + rec.value_len) {
            _event_free(event);
            return false;
        }
        const char *key = (const char *)buf + pos;
        const char *value = key + rec.key_len;
        pos += (size_t)rec.key_len + rec.value_len;

        if (rec.kind == DD_RECORD_FIELD) {
            _parse_field(
                event, zai_str_new(key, rec.key_len), value, rec.value_len);
        } else if (rec.kind == DD_RECORD_SERVER) {
            zval zv;
            ZVAL_STRINGL(&zv, value, rec.value_len);
            zend_hash_str_update(
                Z_ARRVAL(event->server), key, rec.key_len, &zv);
        }
    }
    return true;
}

static void _parse_field(dd_event *nonnull event, zai_str key,
    const char *nonnull value, size_t value_len)
{
    for (size_t i = 0; i < DD_FIELD_COUNT; i++) {
        if (zai_str_eq(key, _field_keys[i])) {
            if (event->fields[i] != NULL) {
                zend_string_release(event->fields[i]);
            }
            event->fields[i] = zend_string_init(value, value_len, 0);
            return;
        }
    }
}

static void _event_free(dd_event *nonnull event)
{
    zval_ptr_dtor(&event->server);
    ZVAL_UNDEF(&event->server);
    for (size_t i = 0; i < DD_FIELD_COUNT; i++) {
        if (event->fields[i] != NULL) {
            zend_string_release(event->fields[i]);
            event->fields[i] = NULL;
        }
    }
}

// The span of the failed request: its saved request data, the deferred fatal
// as its error, status 500, and the AppSec keep; false if the tracer refused
// it.
static bool _add_span(dd_event *nonnull event, uint64_t dropped)
{
    zval meta;
    array_init(&meta);
    const char *phase_name = dd_p2s_phase_name(DD_PHASE_STARTUP);
    add_assoc_str_ex(&meta, ZEND_STRL(DD_P2S_TAG_DEFERRED),
        zend_string_init(phase_name, strlen(phase_name), 0));
    if (dropped > 0) {
        add_assoc_str_ex(&meta, ZEND_STRL(DD_TAG_STARTUP_DROPPED),
            zend_strpprintf(0, "%" PRIu64, dropped));
    }
    // As on AppSec's own traces, whatever DD_TRACE_CLIENT_IP_ENABLED says.
    zend_string *client_ip = dd_ip_extraction_find(&event->server);
    if (client_ip != NULL) {
        add_assoc_str_ex(&meta, ZEND_STRL("http.client_ip"), client_ip);
    }

    zend_string *error_type = zend_string_init(ZEND_STRL("E_ERROR"), 0);
    zend_string *error_message = zend_strpprintf(
        0, DD_P2S_DEFERRED_FORMAT, (size_t)event->hdr.breached_limit);
    zend_string *method = event->fields[DD_FIELD_METHOD];

    ddtrace_detached_request_span req = {
        .server = Z_ARRVAL(event->server),
        .name = DD_STARTUP_SPAN_NAME,
        .method = method != NULL ? ZSTR_VAL(method) : NULL,
        .service = event->fields[DD_FIELD_SERVICE],
        .env = event->fields[DD_FIELD_ENV],
        .version = event->fields[DD_FIELD_VERSION],
        .start_ns = event->hdr.start_ns,
        .duration_ns = event->hdr.duration_ns,
        .status_code = DD_STARTUP_SPAN_STATUS,
        .error_type = error_type,
        .error_message = error_message,
        .meta = Z_ARRVAL(meta),
        .asm_keep = true,
        // The failed process's, not this one's.
        .process_id = event->hdr.process_id,
        .runtime_id = event->fields[DD_FIELD_RUNTIME_ID],
        .own_hostname = true,
        .hostname = event->fields[DD_FIELD_HOSTNAME],
    };
    bool added = dd_trace_add_startup_span(&req);
    if (added) {
        mlog(dd_log_debug,
            "PHP2Shell mitigation: added the startup span "
            "(dropped %" PRIu64 ")",
            dropped);
    } else {
        mlog(dd_log_debug, "PHP2Shell mitigation: the tracer refused the "
                           "startup span; counted as dropped");
    }

    zend_string_release(error_type);
    zend_string_release(error_message);
    zval_ptr_dtor(&meta);
    return added;
}
