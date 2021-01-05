cmake_minimum_required(VERSION 3.10)
project(DDProf
  LANGUAGES C CXX
  VERSION 0.1.0
)

add_library(DDProf src/string_table.cc src/arena.c)
target_compile_features(DDProf PUBLIC cxx_std_11 c_std_99)

target_include_directories(DDProf
  PUBLIC
    $<INSTALL_INTERFACE:include>
    $<BUILD_INTERFACE:${PROJECT_SOURCE_DIR}/include>
)

set_target_properties(DDProf PROPERTIES
  OUTPUT_NAME ddprof # It should be named libddprof.{a,so}
  VERSION ${PROJECT_VERSION}
)

#[[ We want to be able to use the namespaced name everywhere, including in this
    project's tests; this is a pattern described in the talk Effective CMake
    that enables it.
#]]
add_library(DDProf::DDProf ALIAS DDProf)

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

# Add infrastructure for enabling tests
option(BUILD_DDPROF_TESTING "Enable tests" OFF)
if (${BUILD_DDPROF_TESTING})
  enable_testing()
  add_subdirectory(test)
endif()
