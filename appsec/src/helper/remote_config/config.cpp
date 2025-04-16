#include "config.hpp"
#include "product.hpp"
#include <base64.h>
#include <charconv>
#include <regex>
#include <spdlog/spdlog.h>
#include <string>
#include <string_view>

extern "C" {
#include <fcntl.h>
#include <sys/mman.h>
#include <sys/stat.h>
}

using namespace std::literals;

namespace dds::remote_config {

config config::from_line(std::string_view line)
{
    // split by :
    auto pos = line.find(':');
    if (pos == std::string_view::npos) {
        throw std::runtime_error{"invalid shmem config line (no colon)"};
    }

    const std::string_view shm_path{line.substr(0, pos)};
    auto pos2 = line.find(':', pos + 1);
    if (pos2 == std::string_view::npos) {
        throw std::runtime_error{"invalid shmem config line (no second colon)"};
    }

    std::uint32_t limiter_idx;
    auto res =
        std::from_chars(line.data() + pos + 1, line.data() + pos2, limiter_idx);
    if (res.ec != std::errc{} || res.ptr != line.data() + pos2) {
        throw std::runtime_error{"invalid shmem config line (limiter_idx)"};
    }

    const std::string_view rc_path_encoded{line.substr(pos2 + 1)};
    // base64 decode rc_path (no padding):
    std::string rc_path = base64_decode(rc_path_encoded);

    return {std::string{shm_path}, std::move(rc_path)};
}

mapped_memory config::read() const
{
    // open shared memory segment at rc_path:
    const int fd = ::shm_open(shm_path.c_str(), O_RDONLY, 0);
    if (fd == -1) {
        throw std::runtime_error{
            "shm_open failed for " + shm_path + " : " + strerror_ts(errno)};
    }

    auto close_fs = defer{[fd]() { ::close(fd); }};

    // check that the uid of the shared memory segment is the same as ours
    struct ::stat shm_stat {};
    if (::fstat(fd, &shm_stat) == -1) {
        throw std::runtime_error{
            "Call to fstat on memory segment failed: " + strerror_ts(errno)};
    }
    if (shm_stat.st_uid != ::geteuid()) {
        throw std::runtime_error{"Shared memory segment does not have the "
                                 "expected owner. Expect our uid " +
                                 std::to_string(::geteuid()) + " but found " +
                                 std::to_string(shm_stat.st_uid)};
    }

    void *shm_ptr =
        ::mmap(nullptr, shm_stat.st_size, PROT_READ, MAP_SHARED, fd, 0);
    if (shm_ptr == MAP_FAILED) { // NOLINT
        throw std::runtime_error(
            "Failed to map shared memory: " + std::string{strerror_ts(errno)});
    }

    return mapped_memory{shm_ptr, static_cast<std::size_t>(shm_stat.st_size)};
}

namespace {
template <typename SubMatch>
std::string_view submatch_to_sv(const SubMatch &sub_match)
{
    return {&*sub_match.first, static_cast<std::size_t>(sub_match.length())};
}
} // namespace

void parsed_config_key::parse_config_key()
{
    static std::regex const rgx{
        "(?:datadog/(\\d+)|employee)/([^/]+)/([^/]+)/([^/]+)"};
    std::smatch smatch;
    if (!std::regex_match(key_, smatch, rgx)) {
        throw std::invalid_argument("Invalid config key: " + key_);
    }

    if (key_[0] == 'd') {
        source_ = "datadog";
        auto [ptr, ec] =
            std::from_chars(&*smatch[1].first, &*smatch[1].second, org_id_);
        if (ec != std::errc{} || ptr != &*smatch[1].second) {
            throw std::invalid_argument("Invalid org_id in config key " + key_ +
                                        ": " +
                                        std::string{submatch_to_sv(smatch[1])});
        }
    } else {
        source_ = "employee";
        org_id_ = 0;
    }

    std::string_view const product_sv{submatch_to_sv(smatch[2])};
    product_ = known_products::for_name(product_sv);
    config_id_ = submatch_to_sv(smatch[3]);
    name_ = submatch_to_sv(smatch[4]);
}
} // namespace dds::remote_config
