cmake_minimum_required(VERSION 3.10)
project(DatadogStringTable
  LANGUAGES C CXX
  VERSION 0.1.0
)

# We try to use installed versions first; fallback to relative directories
find_package(DatadogArena QUIET)
if (NOT DatadogArena_FOUND)
  add_subdirectory(../DatadogArena DatadogArena)
endif ()

find_package(DatadogMemHash QUIET)
if (NOT DatadogMemHash_FOUND)
  add_subdirectory(../DatadogMemHash DatadogMemHash)
endif ()

add_library(DatadogStringTable  string_table.cc)
target_compile_features(DatadogStringTable PUBLIC cxx_std_14)

target_include_directories(DatadogStringTable
  PUBLIC
    $<INSTALL_INTERFACE:include>
    $<BUILD_INTERFACE:${PROJECT_SOURCE_DIR}/include>
)

target_link_libraries(DatadogStringTable
  PUBLIC Datadog::Arena
  PRIVATE Datadog::CxxMemHash
)

set_target_properties(DatadogStringTable PROPERTIES
  EXPORT_NAME StringTable
)

set_target_properties(DatadogStringTable PROPERTIES
  OUTPUT_NAME datadog_stringtable # It should be named libdatadog_stringtable.{a,so}
  VERSION ${PROJECT_VERSION}
  EXPORT_NAME StringTable
)

#[[ We want to be able to use the namespaced name everywhere, including in this
    project's tests; this is a pattern described in the talk Effective CMake
    that enables it.
#]]
add_library(Datadog::StringTable ALIAS DatadogStringTable)

install(
  TARGETS DatadogStringTable
  EXPORT DatadogStringTableTargets
  ARCHIVE DESTINATION lib
  LIBRARY DESTINATION lib
  RUNTIME DESTINATION bin
  PUBLIC_HEADER DESTINATION include
  INCLUDES DESTINATION include
)

install(
  EXPORT DatadogStringTableTargets
  NAMESPACE Datadog::
  DESTINATION share/cmake/DatadogStringTable
)

# This allows the exports to be used from the build tree, instead of only after installing
export(
  TARGETS DatadogStringTable
  FILE DatadogStringTableExports.cmake
)

include(CMakePackageConfigHelpers)

write_basic_package_version_file("DatadogStringTableVersion.cmake"
  VERSION ${PROJECT_VERSION}
  COMPATIBILITY ExactVersion
)

install(
  FILES "${PROJECT_BINARY_DIR}/DatadogStringTableVersion.cmake"
  DESTINATION share/cmake/DatadogStringTable
)

install(
  DIRECTORY include/
  DESTINATION include
)

# Add infrastructure for enabling tests
option(BUILD_STRINGTABLE_TESTING "Enable tests" OFF)
if (${BUILD_STRINGTABLE_TESTING})
  enable_testing()
  add_subdirectory(test)
endif()
