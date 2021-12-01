extern "C" {
#include <components/log/log.h>
}

#include <fcntl.h>
#include <pthread.h>
#include <sys/mman.h>
#include <unistd.h>

#include <catch2/catch.hpp>
#include <cerrno>
#include <climits>
#include <cstring>

int generate_memfd(void **mem, size_t len) {
    if (len > INT_MAX) return -1;

    FILE *file = tmpfile();
    int fd = fileno(file);
    *mem = nullptr;
    if (fd != -1) {
        // int cast is guarded above
        if (ftruncate(fd, (int)len)) {
            fclose(file);
            return -1;
        }

        int prot = PROT_READ | PROT_WRITE;
        int flags = MAP_SHARED | MAP_FILE;
        *mem = mmap(nullptr, len, prot, flags, fd, 0);
        if (*mem == MAP_FAILED) {
            fprintf(stderr, "%s\n", strerror(errno));
            *mem = nullptr;
            fclose(file);
            return -1;
        }
    }
    return fd;
}

TEST_CASE("logv", "[log]") {
    char *mem;
    size_t mem_len = 32;
    int fd = generate_memfd((void **)&mem, mem_len);
    REQUIRE(fd != -1);
    REQUIRE(mem);
    REQUIRE(mem != MAP_FAILED);

    pthread_mutex_t mutex;
    REQUIRE(pthread_mutex_init(&mutex, nullptr) == 0);

    datadog_php_logger logger = DATADOG_PHP_LOGGER_INIT;
    REQUIRE(datadog_php_logger_ctor(&logger, fd, DATADOG_PHP_LOG_DEBUG, &mutex));

    datadog_php_string_view messages[3] = {
        {datadog_php_string_view_from_cstr("[Datadog Profiling] ")},
        {datadog_php_string_view_from_cstr("Stack Collector failed to join; ")},
        {datadog_php_string_view_from_cstr("EDEADLK")},
    };
    auto written = datadog_php_logv(&logger, DATADOG_PHP_LOG_ERROR, 3, messages);
    CHECK(written >= 0);

    auto expect = datadog_php_string_view_from_cstr("[Datadog Profiling] Stack Collector failed to join; EDEADLK\n");
    auto actual = datadog_php_string_view{static_cast<size_t>(written), mem};
    CHECK(datadog_php_string_view_equal(expect, actual));

    datadog_php_logger_dtor(&logger);
    CHECK(munmap(mem, mem_len) == 0);
    CHECK(close(fd) == 0);
    CHECK(pthread_mutex_destroy(&mutex) == 0);
}
