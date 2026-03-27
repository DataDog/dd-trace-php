cmake_minimum_required(VERSION 3.14)
# Called at build time via cmake -P with COMPONENTS_RS, LIBDATADOG, STAMP_FILE
# defined on the command line. Touches STAMP_FILE only when at least one Rust
# source file (*.rs, Cargo.toml, Cargo.lock) is newer than the stamp, so that
# cargo is not re-run on every build when nothing has changed.
file(GLOB_RECURSE _sources
    "${COMPONENTS_RS}/*.rs"
    "${COMPONENTS_RS}/Cargo.toml"
    "${COMPONENTS_RS}/Cargo.lock"
    "${LIBDATADOG}/*.rs"
    "${LIBDATADOG}/Cargo.toml"
    "${LIBDATADOG}/Cargo.lock"
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
