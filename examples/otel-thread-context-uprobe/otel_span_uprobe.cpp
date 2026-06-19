#include "otel_span_uprobe.h"
#include "otel_span_uprobe.skel.h"

#include <bpf/bpf.h>
#include <bpf/libbpf.h>
#include <fcntl.h>
#include <signal.h>
#include <sys/stat.h>
#include <sys/uio.h>
#include <unistd.h>

#include <algorithm>
#include <cerrno>
#include <climits>
#include <cstdarg>
#include <cinttypes>
#include <cstddef>
#include <cstdint>
#include <cstdio>
#include <cstdlib>
#include <cstring>
#include <optional>
#include <set>
#include <stdexcept>
#include <string>
#include <string_view>
#include <unordered_map>
#include <utility>
#include <vector>

namespace {

volatile sig_atomic_t exiting = 0;

struct Mapping {
    uintptr_t start = 0;
    uintptr_t end = 0;
    std::string addr_token;
    std::string perms;
    std::string dev;
    std::string inode;
    std::string path;
};

struct OTelMappingHeader {
    char signature[8];
    uint32_t version;
    uint32_t payload_size;
    uint64_t monotonic_published_at_ns;
    uint64_t payload_ptr;
} __attribute__((packed));

static_assert(sizeof(OTelMappingHeader) == 32);

struct AttributeValue {
    enum class Type {
        None,
        String,
        Int,
    };

    Type type = Type::None;
    std::string string_value;
    int64_t int_value = 0;
};

using Attributes = std::unordered_map<std::string, AttributeValue>;

struct BinaryLocation {
    std::string path;
    std::string display_path;
};

std::string trim(std::string value)
{
    size_t start = value.find_first_not_of(" \t");
    if (start == std::string::npos) {
        return {};
    }

    size_t end = value.find_last_not_of(" \t");
    return value.substr(start, end - start + 1);
}

std::string strip_deleted_suffix(std::string path)
{
    constexpr std::string_view suffix = " (deleted)";
    if (path.size() >= suffix.size() &&
        path.compare(path.size() - suffix.size(), suffix.size(), suffix) == 0) {
        path.resize(path.size() - suffix.size());
    }
    return path;
}

std::runtime_error errno_error(const std::string &message)
{
    return std::runtime_error(message + ": " + std::strerror(errno));
}

void handle_signal(int)
{
    exiting = 1;
}

pid_t parse_pid(const char *arg)
{
    char *end = nullptr;
    errno = 0;
    long value = std::strtol(arg, &end, 10);
    if (errno || !end || *end != '\0' || value <= 0 || value > INT32_MAX) {
        throw std::runtime_error("expected a positive pid");
    }
    return static_cast<pid_t>(value);
}

uintptr_t parse_hex_addr(std::string_view value)
{
    std::string copy(value);
    char *end = nullptr;
    errno = 0;
    unsigned long long parsed = std::strtoull(copy.c_str(), &end, 16);
    if (errno || !end || *end != '\0') {
        throw std::runtime_error("failed to parse address in /proc maps");
    }
    return static_cast<uintptr_t>(parsed);
}

std::vector<Mapping> read_maps(pid_t pid)
{
    std::string path = "/proc/" + std::to_string(pid) + "/maps";
    FILE *file = std::fopen(path.c_str(), "re");
    if (!file) {
        throw errno_error("failed to open " + path);
    }

    std::vector<Mapping> mappings;
    char *line = nullptr;
    size_t line_cap = 0;
    while (getline(&line, &line_cap, file) != -1) {
        char addr[64] = {};
        char perms[8] = {};
        char offset[32] = {};
        char dev[32] = {};
        char inode[32] = {};
        int consumed = 0;
        if (std::sscanf(line, "%63s %7s %31s %31s %31s %n", addr, perms, offset, dev, inode,
                        &consumed) < 5) {
            continue;
        }

        std::string addr_token(addr);
        size_t dash = addr_token.find('-');
        if (dash == std::string::npos) {
            continue;
        }

        Mapping mapping;
        mapping.addr_token = addr_token;
        mapping.start = parse_hex_addr(std::string_view(addr_token).substr(0, dash));
        mapping.end = parse_hex_addr(std::string_view(addr_token).substr(dash + 1));
        mapping.perms = perms;
        mapping.dev = dev;
        mapping.inode = inode;
        mapping.path = strip_deleted_suffix(trim(line + consumed));
        mappings.push_back(std::move(mapping));
    }

    std::free(line);
    std::fclose(file);
    return mappings;
}

bool is_otel_mapping_name(const std::string &path)
{
    return path.rfind("/memfd:OTEL_CTX", 0) == 0 || path.rfind("[anon_shmem:OTEL_CTX]", 0) == 0 ||
           path.rfind("[anon:OTEL_CTX]", 0) == 0;
}

void read_process_memory(pid_t pid, uintptr_t remote_addr, void *local_buf, size_t size)
{
    auto *dst = static_cast<uint8_t *>(local_buf);
    size_t done = 0;

    while (done < size) {
        iovec local = {};
        local.iov_base = dst + done;
        local.iov_len = size - done;

        iovec remote = {};
        remote.iov_base = reinterpret_cast<void *>(remote_addr + done);
        remote.iov_len = size - done;

        ssize_t nread = process_vm_readv(pid, &local, 1, &remote, 1, 0);
        if (nread < 0) {
            throw errno_error("process_vm_readv failed");
        }
        if (nread == 0) {
            throw std::runtime_error("short process_vm_readv read");
        }
        done += static_cast<size_t>(nread);
    }
}

class ProtoReader {
  public:
    explicit ProtoReader(std::string_view input) : input_(input) {}

    bool eof() const { return pos_ >= input_.size(); }

    bool read_key(uint32_t *field_number, uint32_t *wire_type)
    {
        uint64_t key = 0;
        if (!read_varint(&key)) {
            return false;
        }
        *field_number = static_cast<uint32_t>(key >> 3);
        *wire_type = static_cast<uint32_t>(key & 0x7);
        return *field_number != 0;
    }

    bool read_varint(uint64_t *value)
    {
        uint64_t result = 0;
        for (uint32_t shift = 0; shift < 64 && pos_ < input_.size(); shift += 7) {
            uint8_t byte = static_cast<uint8_t>(input_[pos_++]);
            result |= static_cast<uint64_t>(byte & 0x7f) << shift;
            if ((byte & 0x80) == 0) {
                *value = result;
                return true;
            }
        }
        return false;
    }

    bool read_length_delimited(std::string_view *value)
    {
        uint64_t size = 0;
        if (!read_varint(&size) || size > input_.size() - pos_) {
            return false;
        }
        *value = input_.substr(pos_, static_cast<size_t>(size));
        pos_ += static_cast<size_t>(size);
        return true;
    }

    bool skip(uint32_t wire_type)
    {
        uint64_t ignored = 0;
        std::string_view ignored_bytes;

        switch (wire_type) {
        case 0:
            return read_varint(&ignored);
        case 1:
            if (input_.size() - pos_ < 8) {
                return false;
            }
            pos_ += 8;
            return true;
        case 2:
            return read_length_delimited(&ignored_bytes);
        case 5:
            if (input_.size() - pos_ < 4) {
                return false;
            }
            pos_ += 4;
            return true;
        default:
            return false;
        }
    }

  private:
    std::string_view input_;
    size_t pos_ = 0;
};

AttributeValue parse_any_value(std::string_view message)
{
    ProtoReader reader(message);
    AttributeValue result;

    while (!reader.eof()) {
        uint32_t field = 0;
        uint32_t wire = 0;
        if (!reader.read_key(&field, &wire)) {
            break;
        }

        if (field == 1 && wire == 2) {
            std::string_view value;
            if (!reader.read_length_delimited(&value)) {
                break;
            }
            result.type = AttributeValue::Type::String;
            result.string_value.assign(value);
        } else if (field == 3 && wire == 0) {
            uint64_t value = 0;
            if (!reader.read_varint(&value)) {
                break;
            }
            result.type = AttributeValue::Type::Int;
            result.int_value = static_cast<int64_t>(value);
        } else if (!reader.skip(wire)) {
            break;
        }
    }

    return result;
}

std::optional<std::pair<std::string, AttributeValue>> parse_key_value(std::string_view message)
{
    ProtoReader reader(message);
    std::string key;
    AttributeValue value;

    while (!reader.eof()) {
        uint32_t field = 0;
        uint32_t wire = 0;
        if (!reader.read_key(&field, &wire)) {
            break;
        }

        if (field == 1 && wire == 2) {
            std::string_view key_view;
            if (!reader.read_length_delimited(&key_view)) {
                break;
            }
            key.assign(key_view);
        } else if (field == 2 && wire == 2) {
            std::string_view value_view;
            if (!reader.read_length_delimited(&value_view)) {
                break;
            }
            value = parse_any_value(value_view);
        } else if (!reader.skip(wire)) {
            break;
        }
    }

    if (key.empty()) {
        return std::nullopt;
    }

    return std::make_pair(std::move(key), std::move(value));
}

void parse_resource_attributes(std::string_view message, Attributes *attributes)
{
    ProtoReader reader(message);

    while (!reader.eof()) {
        uint32_t field = 0;
        uint32_t wire = 0;
        if (!reader.read_key(&field, &wire)) {
            break;
        }

        if (field == 1 && wire == 2) {
            std::string_view kv_view;
            if (!reader.read_length_delimited(&kv_view)) {
                break;
            }
            auto kv = parse_key_value(kv_view);
            if (kv) {
                attributes->insert_or_assign(std::move(kv->first), std::move(kv->second));
            }
        } else if (!reader.skip(wire)) {
            break;
        }
    }
}

Attributes parse_process_context(std::string_view payload)
{
    ProtoReader reader(payload);
    Attributes attributes;

    while (!reader.eof()) {
        uint32_t field = 0;
        uint32_t wire = 0;
        if (!reader.read_key(&field, &wire)) {
            break;
        }

        if (field == 1 && wire == 2) {
            std::string_view resource;
            if (!reader.read_length_delimited(&resource)) {
                break;
            }
            parse_resource_attributes(resource, &attributes);
        } else if (!reader.skip(wire)) {
            break;
        }
    }

    return attributes;
}

const AttributeValue &require_attr(const Attributes &attributes, const std::string &key)
{
    auto it = attributes.find(key);
    if (it == attributes.end()) {
        throw std::runtime_error("missing OTel process-context attribute: " + key);
    }
    return it->second;
}

int64_t require_int_attr(const Attributes &attributes, const std::string &key)
{
    const AttributeValue &value = require_attr(attributes, key);
    if (value.type != AttributeValue::Type::Int) {
        throw std::runtime_error("OTel process-context attribute is not an int: " + key);
    }
    return value.int_value;
}

std::string require_string_attr(const Attributes &attributes, const std::string &key)
{
    const AttributeValue &value = require_attr(attributes, key);
    if (value.type != AttributeValue::Type::String) {
        throw std::runtime_error("OTel process-context attribute is not a string: " + key);
    }
    return value.string_value;
}

tls_reader_config read_tls_config_from_process_context(pid_t pid)
{
    std::vector<Mapping> mappings = read_maps(pid);
    auto mapping = std::find_if(mappings.begin(), mappings.end(), [](const Mapping &candidate) {
        return is_otel_mapping_name(candidate.path);
    });
    if (mapping == mappings.end()) {
        throw std::runtime_error("could not find an OTEL_CTX mapping in the target process");
    }

    OTelMappingHeader header = {};
    read_process_memory(pid, mapping->start, &header, sizeof(header));

    if (std::memcmp(header.signature, "OTEL_CTX", 8) != 0) {
        throw std::runtime_error("OTEL_CTX mapping has an invalid signature");
    }
    if (header.version != 2) {
        throw std::runtime_error("unsupported OTEL_CTX version: " + std::to_string(header.version));
    }
    if (header.monotonic_published_at_ns == 0) {
        throw std::runtime_error("OTEL_CTX mapping is currently being published");
    }
    if (header.payload_size == 0 || header.payload_size > 1024 * 1024) {
        throw std::runtime_error("OTEL_CTX payload size is invalid");
    }

    std::vector<uint8_t> payload(header.payload_size);
    read_process_memory(pid, static_cast<uintptr_t>(header.payload_ptr), payload.data(), payload.size());

    Attributes attributes = parse_process_context(
        std::string_view(reinterpret_cast<const char *>(payload.data()), payload.size()));

    int64_t module_id = require_int_attr(attributes, "threadlocal.tls_module_id");
    int64_t block_offset = require_int_attr(attributes, "threadlocal.tls_block_offset");
    std::string libc = require_string_attr(attributes, "threadlocal.libc");

    if (module_id <= 0) {
        throw std::runtime_error("threadlocal.tls_module_id must be positive");
    }
    if (block_offset < 0) {
        throw std::runtime_error("threadlocal.tls_block_offset must be non-negative");
    }

    tls_reader_config cfg = {};
    cfg.tls_module_id = static_cast<uint64_t>(module_id);
    cfg.tls_block_offset = block_offset;
    cfg.target_tgid = static_cast<uint32_t>(pid);
    if (libc == "glibc") {
        cfg.libc_kind = LIBC_KIND_GLIBC;
    } else if (libc == "musl") {
        cfg.libc_kind = LIBC_KIND_MUSL;
    } else {
        throw std::runtime_error("unsupported threadlocal.libc value: " + libc);
    }

    return cfg;
}

std::vector<std::string> candidate_paths_for_mapping(pid_t pid, const Mapping &mapping)
{
    std::vector<std::string> paths;
    if (!mapping.path.empty() && mapping.path[0] == '/') {
        paths.push_back("/proc/" + std::to_string(pid) + "/root" + mapping.path);
        paths.push_back(mapping.path);
    }

    paths.push_back("/proc/" + std::to_string(pid) + "/map_files/" + mapping.addr_token);
    return paths;
}

bool is_libphp_mapping(const Mapping &mapping)
{
    if (mapping.perms.size() < 3 || mapping.perms[2] != 'x' || mapping.path.empty()) {
        return false;
    }

    std::string_view path(mapping.path);
    size_t slash = path.find_last_of('/');
    std::string_view basename = slash == std::string_view::npos ? path : path.substr(slash + 1);
    return basename.find("libphp") != std::string_view::npos;
}

BinaryLocation find_libphp(pid_t pid)
{
    std::vector<Mapping> mappings = read_maps(pid);
    std::set<std::string> seen_files;

    for (const Mapping &mapping : mappings) {
        if (!is_libphp_mapping(mapping)) {
            continue;
        }

        std::string file_key = mapping.dev + ":" + mapping.inode;
        if (!seen_files.insert(file_key).second) {
            continue;
        }

        for (const std::string &path : candidate_paths_for_mapping(pid, mapping)) {
            if (access(path.c_str(), R_OK) != 0) {
                continue;
            }

            BinaryLocation location;
            location.path = path;
            location.display_path = mapping.path;
            return location;
        }
    }

    throw std::runtime_error("could not find an executable libphp mapping in the target process");
}

const char *status_name(uint32_t status)
{
    switch (status) {
    case READ_STATUS_OK:
        return "ok";
    case READ_STATUS_NO_CONFIG:
        return "no-config";
    case READ_STATUS_TGID_MISMATCH:
        return "tgid-mismatch";
    case READ_STATUS_NO_THREAD_POINTER:
        return "no-thread-pointer";
    case READ_STATUS_BAD_LIBC:
        return "bad-libc";
    case READ_STATUS_BAD_TLS_OFFSET:
        return "bad-tls-offset";
    case READ_STATUS_NO_DTV:
        return "no-dtv";
    case READ_STATUS_BAD_MODULE_ID:
        return "bad-module-id";
    case READ_STATUS_NO_TLS_BLOCK:
        return "no-tls-block";
    case READ_STATUS_NO_TLS_SLOT:
        return "no-tls-slot";
    case READ_STATUS_NO_RECORD:
        return "no-record";
    case READ_STATUS_INVALID_RECORD:
        return "invalid-record";
    case READ_STATUS_READ_FAILED:
        return "read-failed";
    default:
        return "unknown";
    }
}

const char *libc_name(uint32_t libc_kind)
{
    switch (libc_kind) {
    case LIBC_KIND_GLIBC:
        return "glibc";
    case LIBC_KIND_MUSL:
        return "musl";
    default:
        return "unknown";
    }
}

int handle_event(void *, void *data, size_t)
{
    const auto *event = static_cast<const span_event *>(data);

    if (event->status == READ_STATUS_OK) {
        std::printf("pid=%u tid=%u comm=%.*s span_id=%" PRIu64 " span_id_hex=%016" PRIx64
                    " slot=0x%" PRIx64 " record=0x%" PRIx64 "\n",
                    event->tgid, event->tid, 16, event->comm, event->span_id, event->span_id,
                    event->tls_slot_addr, event->record_addr);
    } else {
        std::printf("pid=%u tid=%u comm=%.*s status=%s libc=%s slot=0x%" PRIx64
                    " record=0x%" PRIx64 "\n",
                    event->tgid, event->tid, 16, event->comm, status_name(event->status),
                    libc_name(event->libc_kind), event->tls_slot_addr, event->record_addr);
    }

    std::fflush(stdout);
    return 0;
}

int libbpf_print(enum libbpf_print_level level, const char *format, va_list args)
{
    if (level == LIBBPF_DEBUG) {
        return 0;
    }
    return std::vfprintf(stderr, format, args);
}

} // namespace

int main(int argc, char **argv)
{
    if (argc != 2) {
        std::fprintf(stderr, "Usage: %s <pid>\n", argv[0]);
        return 1;
    }

    try {
        pid_t pid = parse_pid(argv[1]);
        tls_reader_config cfg = read_tls_config_from_process_context(pid);
        BinaryLocation libphp = find_libphp(pid);

        std::printf("target pid=%d libphp=%s symbol=zif_sleep"
                    " tls_module_id=%" PRIu64 " tls_block_offset=%" PRId64 " libc=%s\n",
                    pid, libphp.display_path.c_str(), cfg.tls_module_id, cfg.tls_block_offset,
                    libc_name(cfg.libc_kind));

        libbpf_set_strict_mode(LIBBPF_STRICT_ALL);
        libbpf_set_print(libbpf_print);

        otel_span_uprobe_bpf *skel = otel_span_uprobe_bpf__open_and_load();
        if (!skel) {
            throw std::runtime_error("failed to open/load BPF skeleton");
        }

        uint32_t key = 0;
        if (bpf_map_update_elem(bpf_map__fd(skel->maps.tls_config_map), &key, &cfg, BPF_ANY) != 0) {
            otel_span_uprobe_bpf__destroy(skel);
            throw errno_error("failed to update config map");
        }

        LIBBPF_OPTS(bpf_uprobe_opts, uprobe_opts, .func_name = "zif_sleep");
        bpf_link *link = bpf_program__attach_uprobe_opts(skel->progs.handle_zif_sleep, pid,
                                                         libphp.path.c_str(), 0, &uprobe_opts);
        long link_error = libbpf_get_error(link);
        if (link_error) {
            errno = static_cast<int>(-link_error);
            otel_span_uprobe_bpf__destroy(skel);
            throw errno_error("failed to attach uprobe to zif_sleep");
        }

        ring_buffer *rb =
            ring_buffer__new(bpf_map__fd(skel->maps.events), handle_event, nullptr, nullptr);
        if (!rb) {
            bpf_link__destroy(link);
            otel_span_uprobe_bpf__destroy(skel);
            throw errno_error("failed to create ring buffer");
        }

        signal(SIGINT, handle_signal);
        signal(SIGTERM, handle_signal);

        while (!exiting) {
            int err = ring_buffer__poll(rb, 250);
            if (err == -EINTR) {
                continue;
            }
            if (err < 0) {
                throw std::runtime_error("ring_buffer__poll failed: " + std::to_string(err));
            }
        }

        ring_buffer__free(rb);
        bpf_link__destroy(link);
        otel_span_uprobe_bpf__destroy(skel);
    } catch (const std::exception &ex) {
        std::fprintf(stderr, "error: %s\n", ex.what());
        return 1;
    }

    return 0;
}
