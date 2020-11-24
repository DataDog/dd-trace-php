cmake_minimum_required(VERSION 3.10)
project(DatadogMemHash
  LANGUAGES C CXX
  VERSION 0.1.0
)

add_library(DatadogCxxMemHash INTERFACE)
target_compile_features(DatadogCxxMemHash INTERFACE cxx_std_14)

target_include_directories(DatadogCxxMemHash
  INTERFACE
    $<INSTALL_INTERFACE:include>
    $<BUILD_INTERFACE:${PROJECT_SOURCE_DIR}/include>
)

set_target_properties(DatadogCxxMemHash PROPERTIES
  EXPORT_NAME CxxMemHash
)

add_library(DatadogCMemHash memhash.cc)
target_compile_features(DatadogCMemHash
  INTERFACE c_std_99
)

target_include_directories(DatadogCMemHash
  PUBLIC
    $<BUILD_INTERFACE:${PROJECT_SOURCE_DIR}/include>
)

set_target_properties(DatadogCMemHash PROPERTIES
  OUTPUT_NAME datadog_memhash # It should be named libdatadog_memhash.{a,so}
  VERSION ${PROJECT_VERSION}
  EXPORT_NAME CMemHash
)

target_link_libraries(DatadogCMemHash PRIVATE DatadogCxxMemHash)

#[[ We want to be able to use the namespaced name everywhere, including in this
    project's tests; this is a pattern described in the talk Effective CMake
    that enables it.
#]]
add_library(Datadog::CMemHash ALIAS DatadogCMemHash)
add_library(Datadog::CxxMemHash ALIAS DatadogCxxMemHash)

install(
  TARGETS DatadogCMemHash DatadogCxxMemHash
  EXPORT DatadogMemHashTargets
  ARCHIVE DESTINATION lib
  LIBRARY DESTINATION lib
  RUNTIME DESTINATION bin
  PUBLIC_HEADER DESTINATION include
  INCLUDES DESTINATION include
)

install(
  EXPORT DatadogMemHashTargets
  NAMESPACE Datadog::
  DESTINATION share/cmake/DatadogMemHash
)

# This allows the exports to be used from the build tree, instead of only after installing
export(
  TARGETS DatadogCMemHash DatadogCxxMemHash
  FILE DatadogMemHashExports.cmake
)

include(CMakePackageConfigHelpers)

write_basic_package_version_file("DatadogMemHashVersion.cmake"
  VERSION ${PROJECT_VERSION}
  COMPATIBILITY ExactVersion
)

install(
  FILES "${PROJECT_BINARY_DIR}/DatadogMemHashVersion.cmake"
  DESTINATION share/cmake/DatadogMemHash
)

install(
  DIRECTORY include/
  DESTINATION include
)

# Add infrastructure for enabling tests
option(BUILD_TESTING "Enable tests" OFF)
include(CTest)
if (${BUILD_TESTING})
  enable_testing()
  add_subdirectory(test)
endif()
