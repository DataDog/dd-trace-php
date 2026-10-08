# A/B benchmark: the same sgc_bench.cpp driving either the C++ library
# (~/repos/shm_gen_cache) or this crate's staticlib through its C API.
# See README.md for the exact commands.
#
#   cmake -S bench -B build/cpp  -DSGC_BENCH_BACKEND=cpp  [-DSGC_SOURCE_DIR=...]
#   cmake -S bench -B build/rust -DSGC_BENCH_BACKEND=rust

cmake_minimum_required(VERSION 3.28)
project(sgc_bench_ab LANGUAGES CXX)
set(CMAKE_CXX_STANDARD 23)
set(CMAKE_CXX_STANDARD_REQUIRED ON)
set(CMAKE_CXX_EXTENSIONS OFF)
if(NOT CMAKE_BUILD_TYPE)
    set(CMAKE_BUILD_TYPE RelWithDebInfo CACHE STRING "" FORCE)
endif()

set(SGC_BENCH_BACKEND "rust" CACHE STRING "cpp or rust")
set_property(CACHE SGC_BENCH_BACKEND PROPERTY STRINGS cpp rust)

find_package(Threads REQUIRED)

add_executable(sgc_bench sgc_bench.cpp)
target_link_libraries(sgc_bench PRIVATE Threads::Threads)

# The library's dialect (cmake/SgcFlags.cmake of the C++ project); applied to
# the harness for both backends, so the harness compiles identically.
set(_sgc_dialect
    -fno-exceptions -fno-rtti -fno-threadsafe-statics
    -fno-unwind-tables -fno-asynchronous-unwind-tables)

if(SGC_BENCH_BACKEND STREQUAL "cpp")
    set(SGC_SOURCE_DIR "$ENV{HOME}/repos/shm_gen_cache"
        CACHE PATH "C++ shm_gen_cache source tree")
    set(SGC_BUILD_BENCH OFF CACHE BOOL "" FORCE)
    set(SGC_WERROR OFF CACHE BOOL "" FORCE)
    add_subdirectory("${SGC_SOURCE_DIR}" sgc EXCLUDE_FROM_ALL)
    target_include_directories(sgc_bench PRIVATE "${SGC_SOURCE_DIR}/src")
    target_link_libraries(sgc_bench PRIVATE sgc::sgc)
    target_compile_definitions(sgc_bench PRIVATE SGC_BENCH_BACKEND_RUST=0)
elseif(SGC_BENCH_BACKEND STREQUAL "rust")
    get_filename_component(_crate_dir "${CMAKE_CURRENT_SOURCE_DIR}/.." ABSOLUTE)
    set(SGC_CARGO_PROFILE "tracer-release" CACHE STRING "cargo profile")
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
    add_dependencies(sgc_bench shm_gen_cache_rs)
    target_include_directories(sgc_bench PRIVATE "${_crate_dir}")
    target_link_libraries(sgc_bench PRIVATE shm_gen_cache_rs_lib)
    if(CMAKE_SYSTEM_NAME STREQUAL "Linux")
        # rustc --print native-static-libs
        target_link_libraries(sgc_bench PRIVATE gcc_s util rt pthread m dl c)
    endif()
    target_compile_definitions(sgc_bench PRIVATE SGC_BENCH_BACKEND_RUST=1)
else()
    message(FATAL_ERROR "SGC_BENCH_BACKEND must be cpp or rust")
endif()

target_compile_options(sgc_bench PRIVATE ${_sgc_dialect})
