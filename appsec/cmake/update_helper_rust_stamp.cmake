cmake_minimum_required(VERSION 3.14)
# Called at build time via cmake -P with HELPER_RUST_DIR and STAMP_FILE
# defined on the command line. Touches STAMP_FILE only when at least one helper
# input is newer than the stamp, so that cargo is not re-run on every build when
# nothing has changed.
set(_LIBDDWAF_RUST_DIR "${HELPER_RUST_DIR}/../third_party/libddwaf-rust")
file(GLOB_RECURSE _sources
    "${HELPER_RUST_DIR}/*.rs"
    "${HELPER_RUST_DIR}/Cargo.toml"
    "${HELPER_RUST_DIR}/Cargo.lock"
    "${HELPER_RUST_DIR}/../recommended.json"
    "${_LIBDDWAF_RUST_DIR}/*.rs"
    "${_LIBDDWAF_RUST_DIR}/Cargo.toml"
    "${_LIBDDWAF_RUST_DIR}/Cargo.lock"
)
list(FILTER _sources EXCLUDE REGEX ".*/target/.*")
set(_newest "")
foreach(_src ${_sources})
    if(_newest STREQUAL "" OR "${_src}" IS_NEWER_THAN "${_newest}")
        set(_newest "${_src}")
    endif()
endforeach()
if(_newest STREQUAL "")
    return()
endif()
if(NOT EXISTS "${STAMP_FILE}" OR "${_newest}" IS_NEWER_THAN "${STAMP_FILE}")
    file(TOUCH "${STAMP_FILE}")
endif()
