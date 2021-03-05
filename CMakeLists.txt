cmake_minimum_required(VERSION 3.14)
project(DDProf
  LANGUAGES C
  VERSION 0.1.0
)

find_library(PROTOBUF_C protobuf-c REQUIRED)
find_package(ZLIB REQUIRED)

add_library(DDProf
  src/append_string.c
  src/dd_send.c
  src/dictionary.c
  src/http.c
  src/pprof.c
  src/proto/profile.pb-c.c
  src/string_table.c
)

target_link_libraries(DDProf
  PUBLIC ZLIB::ZLIB
  PRIVATE protobuf-c
)

target_compile_features(DDProf
  PUBLIC c_std_99
  PRIVATE c_std_11
)

target_compile_definitions(DDProf PUBLIC -D_GNU_SOURCE)

target_include_directories(DDProf
  PUBLIC
    $<INSTALL_INTERFACE:include>
    $<INSTALL_INTERFACE:include/native>
    $<BUILD_INTERFACE:${PROJECT_SOURCE_DIR}/include>
    $<BUILD_INTERFACE:${PROJECT_SOURCE_DIR}/include/native>
)
target_compile_definitions(DDProf PRIVATE -DDD_DBG_PROFGEN)

set_target_properties(DDProf PROPERTIES
  OUTPUT_NAME ddprof # It should be named libddprof.{a,so}
  VERSION ${PROJECT_VERSION}
)

#[[ We want to be able to use the namespaced name everywhere, including in this
    project's tests; this is a pattern described in the talk Effective CMake
    that enables it.
#]]
add_library(DDProf::DDProf ALIAS DDProf)

# Add infrastructure for enabling tests
option(BUILD_DDPROF_TESTING "Enable tests" OFF)
if (${BUILD_DDPROF_TESTING})
  enable_testing()
  add_subdirectory(test)
endif()
