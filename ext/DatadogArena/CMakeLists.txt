cmake_minimum_required(VERSION 3.10)
# todo: once this has been shipped without defects, bump this to 1.0.0
project(DatadogArena
  VERSION 0.9.0
  LANGUAGES C
)

add_library(DatadogArena arena.c)
target_include_directories(DatadogArena PUBLIC
  $<BUILD_INTERFACE:${PROJECT_SOURCE_DIR}/>
  $<INSTALL_INTERFACE:include>
)

target_compile_features(DatadogArena PRIVATE c_std_11)

set_target_properties(DatadogArena PROPERTIES
  # It should be named libdatadog_arena.{a,so}, not libDatadogArena.{a,so}
  OUTPUT_NAME datadog_arena
  PUBLIC_HEADER datadog/arena.h
  VERSION ${PROJECT_VERSION}
)

#[[
  We want to be able to use the namespaced name everywhere, including in this project's tests;
  this is a pattern described in the talk Effective CMake
#]]
add_library(Datadog::Arena ALIAS DatadogArena)

# Add infrastructure for enabling tests
option(BUILD_TESTING "Enable tests" ON)
include(CTest)
if (${BUILD_TESTING})
  enable_testing()
  add_subdirectory(test)
endif()

# todo: add stuff for installing the library
