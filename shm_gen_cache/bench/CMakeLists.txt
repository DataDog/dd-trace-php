# sgc_bench: the cache's throughput suite as a C++ program driving this
# crate's staticlib through its C API (../shm_gen_cache.h). See README.md.
#
#   cmake -S bench -B build [-DCMAKE_CXX_COMPILER=clang++-21]
#   cmake --build build -j

cmake_minimum_required(VERSION 3.28)
project(sgc_bench LANGUAGES CXX)
set(CMAKE_CXX_STANDARD 23)
set(CMAKE_CXX_STANDARD_REQUIRED ON)
set(CMAKE_CXX_EXTENSIONS OFF)
if(NOT CMAKE_BUILD_TYPE)
    set(CMAKE_BUILD_TYPE RelWithDebInfo CACHE STRING "" FORCE)
endif()

set(SGC_CARGO_PROFILE "tracer-release" CACHE STRING "cargo profile")

find_package(Threads REQUIRED)

get_filename_component(_crate_dir "${CMAKE_CURRENT_SOURCE_DIR}/.." ABSOLUTE)
set(_target_dir "${CMAKE_CURRENT_BINARY_DIR}/cargo")
set(_rustflags "$ENV{RUSTFLAGS}")
set(_lib "${_target_dir}/${SGC_CARGO_PROFILE}/libshm_gen_cache.a")
# Always re-run cargo: it knows its own dependencies.
add_custom_target(shm_gen_cache_rs
    COMMAND ${CMAKE_COMMAND} -E env "RUSTFLAGS=${_rustflags}"
            "CARGO_TARGET_DIR=${_target_dir}"
            cargo build --manifest-path "${_crate_dir}/Cargo.toml"
            -p shm_gen_cache --profile ${SGC_CARGO_PROFILE}
    WORKING_DIRECTORY "${_crate_dir}"
    BYPRODUCTS "${_lib}"
    USES_TERMINAL
    VERBATIM)
add_library(shm_gen_cache_rs_lib STATIC IMPORTED)
set_target_properties(shm_gen_cache_rs_lib PROPERTIES IMPORTED_LOCATION "${_lib}")
add_dependencies(shm_gen_cache_rs_lib shm_gen_cache_rs)

add_executable(sgc_bench sgc_bench.cpp)
add_dependencies(sgc_bench shm_gen_cache_rs)
target_include_directories(sgc_bench PRIVATE "${_crate_dir}")
target_link_libraries(sgc_bench PRIVATE shm_gen_cache_rs_lib Threads::Threads)
if(CMAKE_SYSTEM_NAME STREQUAL "Linux")
    # rustc --print native-static-libs
    target_link_libraries(sgc_bench PRIVATE gcc_s util rt pthread m dl c)
endif()

# No exceptions or RTTI (sgc_bench.cpp #errors otherwise): the dialect every
# recorded result was measured in, so the harness's own code generation stays
# comparable across runs.
target_compile_options(sgc_bench PRIVATE
    -fno-exceptions -fno-rtti -fno-threadsafe-statics
    -fno-unwind-tables -fno-asynchronous-unwind-tables)
