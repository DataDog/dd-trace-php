#include "mocks.hpp"
#include <atomic>
#include <fcntl.h>
#include <string>
#include <sys/mman.h>

namespace dds::remote_config::mock {
remote_config::config get_config(
    std::string_view product_name, const std::string &content)
{
    static std::atomic<std::uint32_t> id{0};

    const int cur_id = id.fetch_add(1, std::memory_order_relaxed);

    std::string shm_path{"/test-rc-file-" + std::to_string(cur_id)};
    ::shm_unlink(shm_path.c_str());
    int shm_fd = ::shm_open(shm_path.c_str(), O_CREAT | O_RDWR, 0600);
    if (shm_fd == -1) {
        std::abort();
    }
    ::ftruncate(shm_fd, content.size());

    if (content.size() > 0) {
        void *mem =
            ::mmap(nullptr, content.size(), PROT_WRITE, MAP_SHARED, shm_fd, 0);
        if (mem == MAP_FAILED) {
            std::abort();
        }
        std::copy_n(content.data(), content.size(), static_cast<char *>(mem));

        if (::munmap(mem, content.size()) != 0) {
            std::abort();
        }
    }

    ::close(shm_fd);

    return {shm_path, std::string{"datadog/2/"} + std::string{product_name} +
                          "/foobar_" + std::to_string(cur_id) + "/config"};
}
} // namespace dds::remote_config::mock
