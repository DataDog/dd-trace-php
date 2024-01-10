enable_language(CXX)

set(CMAKE_CXX_STANDARD 11)

add_executable(tea_benchmarks benchmark.cc)

# Google Benchmark is included as a git submodule.
# It depends on Google Test, which it will download if this option is set.
set(BENCHMARK_DOWNLOAD_DEPENDENCIES ON)
# Don't build Google Benchmark's unit tests.
set(BENCHMARK_ENABLE_TESTING OFF)
add_subdirectory(google-benchmark)

target_link_libraries(tea_benchmarks PUBLIC benchmark::benchmark Tea::Tea)
