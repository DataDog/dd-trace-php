include(FetchContent)
FetchContent_Declare(
  benchmark
  GIT_REPOSITORY https://github.com/google/benchmark.git
  GIT_TAG v1.9.1
)

set(BENCHMARK_ENABLE_TESTING OFF CACHE BOOL "Disable benchmark testing" FORCE)
set(BENCHMARK_ENABLE_GTEST_TESTS OFF CACHE BOOL "Disable gtest in benchmark" FORCE)
set(BENCHMARK_ENABLE_INSTALL OFF CACHE BOOL "Disable benchmark install" FORCE)

FetchContent_MakeAvailable(benchmark)

add_executable(timed_set_benchmark timed_set_bench.cpp)
target_link_libraries(timed_set_benchmark PRIVATE
    benchmark::benchmark helper_objects libddwaf_objects pthread spdlog)
target_include_directories(timed_set_benchmark PRIVATE ${CMAKE_SOURCE_DIR}/third_party/)
