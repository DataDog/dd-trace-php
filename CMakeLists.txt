cmake_minimum_required(VERSION 3.10)
project(DDProf
  LANGUAGES C
  VERSION 0.1.0
)

find_library(PROTOBUF_C protobuf-c)
if (NOT PROTOBUF_C)
  message(FATAL_ERROR "Project requires protobuf-c!")
endif ()

find_package(ZLIB REQUIRED)

add_library(DDProf
  src/native/dictionary.c
  src/native/pprof.c
  src/native/proto/profile.pb-c.c
  src/native/string_table.c
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

set_target_properties(DDProf PROPERTIES
  OUTPUT_NAME ddprof # It should be named libddprof.{a,so}
  VERSION ${PROJECT_VERSION}
  POSITION_INDEPENDENT_CODE ON # PHP always needs PIC, even in static libs
)

#[[ We want to be able to use the namespaced name everywhere, including in this
    project's tests; this is a pattern described in the talk Effective CMake
    that enables it.
#]]
add_library(DDProf::DDProf ALIAS DDProf)

#[[
install(
  TARGETS DDProf
  EXPORT DDProfTargets
  ARCHIVE DESTINATION lib
  LIBRARY DESTINATION lib
  RUNTIME DESTINATION bin
  PUBLIC_HEADER DESTINATION include
  INCLUDES DESTINATION include
)

install(
  EXPORT DDProfTargets
  NAMESPACE DDProf::
  DESTINATION share/cmake/DDProf
)

# This allows the exports to be used from the build tree, instead of only after installing
export(
  TARGETS DDProf
  FILE DDProfExports.cmake
)

include(CMakePackageConfigHelpers)

write_basic_package_version_file("DDProfVersion.cmake"
  VERSION ${PROJECT_VERSION}
  COMPATIBILITY ExactVersion
)

install(
  FILES "${PROJECT_BINARY_DIR}/DDProfVersion.cmake"
  DESTINATION share/cmake/DDProf
)

install(
  DIRECTORY include/
  DESTINATION include
)
]]

# Add infrastructure for enabling tests
#[[ currently not updated
option(BUILD_DDPROF_TESTING "Enable tests" OFF)
if (${BUILD_DDPROF_TESTING})
  enable_testing()
  add_subdirectory(test)
endif()
]]
