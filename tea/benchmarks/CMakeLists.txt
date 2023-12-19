# Microbenchmarks depends on Google Benchmarks, which was installed globally
enable_language(CXX)

find_package(benchmark REQUIRED)

# Include the Google Benchmark Directories
include_directories(${benchmark_INCLUDE_DIRS})

set(CMAKE_CXX_STANDARD 11)


add_executable(tea_benchmarks benchmark.cc)

target_link_libraries(tea_benchmarks PUBLIC benchmark::benchmark Tea::Tea)