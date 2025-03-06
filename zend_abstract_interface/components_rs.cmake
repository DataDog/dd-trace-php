include(ExternalProject)

set(CARGO_BUILD_CMD cargo build)
set(CARGO_BUILD_ENV "") # Initialize to empty


if(CMAKE_BUILD_TYPE STREQUAL "Release")
    set(CARGO_BUILD_CMD ${CARGO_BUILD_CMD} --release)
elseif(CMAKE_BUILD_TYPE STREQUAL "RelWithDebInfo")
    set(CARGO_BUILD_CMD ${CARGO_BUILD_CMD} --release)
    # set(CARGO_BUILD_ENV RUSTFLAGS=-C\ debuginfo=2) # FIXME: does not work
endif()

set(LIBDATADOG_DIR "${CMAKE_SOURCE_DIR}/../libdatadog")
set(LIBDATADOG_STAMP_FILE "${CMAKE_BINARY_DIR}/libdatadog.stamp")
add_custom_target(libdatadog_stamp
    COMMAND ${CMAKE_COMMAND} -E touch ${LIBDATADOG_STAMP_FILE} #XXX: use a script to find modifications
    BYPRODUCT ${LIBDATADOG_STAMP_FILE}
)

if(${CMAKE_SYSTEM_NAME} STREQUAL "Linux")
set(EXPORTS_FILE "${CMAKE_BINARY_DIR}/ddtrace_exports.version")
add_custom_target(ddtrace_exports
    COMMAND bash -c "{ echo -e '{\\nglobal:'; sed 's/$/;/' '${CMAKE_SOURCE_DIR}'/../ddtrace.sym; echo -e 'local:\\n*;\\n};'; } > '${EXPORTS_FILE}'"
    BYPRODUCT ${EXPORTS_FILE}
    DEPENDS ${CMAKE_SOURCE_DIR}/../ddtrace.sym
    VERBATIM
)
elseif(APPLE)
set(EXPORTS_FILE "${CMAKE_BINARY_DIR}/ddtrace_exports.sym")
add_custom_target(ddtrace_exports
    COMMAND bash -c "sed 's/^/_/' '${CMAKE_SOURCE_DIR}'/../ddtrace.sym > '${EXPORTS_FILE}'"
    BYPRODUCT ${EXPORTS_FILE}
    DEPENDS ${CMAKE_SOURCE_DIR}/../ddtrace.sym
    VERBATIM
)
endif()

file(READ "${CMAKE_SOURCE_DIR}/../VERSION" VERSION_CONTENTS)
string(STRIP "${VERSION_CONTENTS}" PHP_DDTRACE_VERSION)
file(MAKE_DIRECTORY "${CMAKE_BINARY_DIR}/gen_ddtrace/ext")
set(VERSION_H_PATH "${CMAKE_BINARY_DIR}/gen_ddtrace/ext/version.h")

add_custom_command(
    OUTPUT "${VERSION_H_PATH}"
    COMMAND ${CMAKE_COMMAND} -E cmake_echo_color --switch= --green "Updating version.h"
    COMMAND ${CMAKE_COMMAND} -E remove -f "${VERSION_H_PATH}"
    COMMAND ${CMAKE_COMMAND} -E touch "${VERSION_H_PATH}"
    COMMAND printf "\\#ifndef PHP_DDTRACE_VERSION\\\\n\\#define PHP_DDTRACE_VERSION \"%s\"\\\\n\\#endif" "'\"${PHP_DDTRACE_VERSION}\"'" >> "${VERSION_H_PATH}"
    DEPENDS "${CMAKE_SOURCE_DIR}/../VERSION"
    COMMENT "Generating version.h"
)
add_custom_target(update_version_h ALL DEPENDS "${VERSION_H_PATH}")

ExternalProject_Add(components_rs_proj
    PREFIX ${CMAKE_BINARY_DIR}/components_rs
    SOURCE_DIR ${CMAKE_SOURCE_DIR}/../components-rs
    CONFIGURE_COMMAND ""
    BUILD_COMMAND RUSTC_BOOTSTRAP=1 ${CARGO_BUILD_ENV} ${CARGO_BUILD_CMD} --target-dir=${CMAKE_BINARY_DIR}/components_rs
    INSTALL_COMMAND ""
    DEPENDS libdatadog_stamp
    BUILD_IN_SOURCE TRUE
)

add_library(components_rs STATIC IMPORTED)
if(CMAKE_BUILD_TYPE STREQUAL "Debug")
    set(CARGO_BUILD_LOCATION ${CMAKE_BINARY_DIR}/components_rs/debug)
else()
    set(CARGO_BUILD_LOCATION ${CMAKE_BINARY_DIR}/components_rs/release)
endif()
set_property(TARGET components_rs PROPERTY IMPORTED_LOCATION ${CARGO_BUILD_LOCATION}/libddtrace_php.a)
add_dependencies(components_rs components_rs_proj)
