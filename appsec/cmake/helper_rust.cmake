include(ExternalProject)

set(HELPER_RUST_DIR "${CMAKE_SOURCE_DIR}/helper-rust")
set(HELPER_RUST_STAMP_FILE "${CMAKE_BINARY_DIR}/helper-rust.stamp")

add_custom_target(helper-rust-stamp
    COMMAND ${CMAKE_COMMAND}
        "-DHELPER_RUST_DIR=${HELPER_RUST_DIR}"
        "-DSTAMP_FILE=${HELPER_RUST_STAMP_FILE}"
        -P "${CMAKE_CURRENT_LIST_DIR}/update_helper_rust_stamp.cmake"
    BYPRODUCTS ${HELPER_RUST_STAMP_FILE}
)

set(CARGO_BUILD_CMD "cargo build")
set(CARGO_BUILD_ENV "")

if(CMAKE_BUILD_TYPE STREQUAL "Release")
    set(CARGO_BUILD_CMD "${CARGO_BUILD_CMD} --release")
elseif(CMAKE_BUILD_TYPE STREQUAL "RelWithDebInfo")
    set(CARGO_BUILD_CMD "${CARGO_BUILD_CMD} --release")
    set(CARGO_BUILD_ENV RUSTFLAGS='-C\ debuginfo=2')
endif()

if(CMAKE_BUILD_TYPE STREQUAL "Debug")
    set(HELPER_RUST_BUILD_LOCATION ${CMAKE_BINARY_DIR}/helper-rust/debug)
else()
    set(HELPER_RUST_BUILD_LOCATION ${CMAKE_BINARY_DIR}/helper-rust/release)
endif()

ExternalProject_Add(helper-rust-proj
    PREFIX ${CMAKE_BINARY_DIR}/helper-rust
    SOURCE_DIR ${HELPER_RUST_DIR}
    CONFIGURE_COMMAND ""
    BUILD_COMMAND sh -c ${CARGO_BUILD_ENV}\ ${CARGO_BUILD_CMD}\ --target-dir=${CMAKE_BINARY_DIR}/helper-rust
    INSTALL_COMMAND ""
    DEPENDS helper-rust-stamp
    BUILD_IN_SOURCE TRUE
)

# The crate produces a cdylib: libddappsec_helper_rust.so (Linux) or
# libddappsec_helper_rust.dylib (macOS)
add_library(helper-rust SHARED IMPORTED GLOBAL)
if(APPLE)
    set(_helper_rust_artifact ${HELPER_RUST_BUILD_LOCATION}/libddappsec_helper_rust.dylib)
else()
    set(_helper_rust_artifact ${HELPER_RUST_BUILD_LOCATION}/libddappsec_helper_rust.so)
endif()
set_property(TARGET helper-rust PROPERTY IMPORTED_LOCATION ${_helper_rust_artifact})
add_dependencies(helper-rust helper-rust-proj)
