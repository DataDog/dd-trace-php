cmake_minimum_required(VERSION 3.10)
project(DatadogShared
  VERSION 0.1.0
  LANGUAGES C
)

#[[
  DatadogContainerId
#]]
add_library(DatadogContainerId container_id.c)
target_include_directories(DatadogContainerId PUBLIC
  $<BUILD_INTERFACE:${PROJECT_SOURCE_DIR}/>
  $<INSTALL_INTERFACE:include>
)

target_compile_features(DatadogContainerId PRIVATE c_std_11)

set_target_properties(DatadogContainerId PROPERTIES
  OUTPUT_NAME datadog_container_id # libdatadog_container_id.{a,so}
  PUBLIC_HEADER datadog/container_id.h
  VERSION ${PROJECT_VERSION}
)

add_library(Datadog::ContainerId ALIAS DatadogContainerId)

#[[
  Testing infrastructure
#]]
option(BUILD_TESTING "Enable tests" ON)
include(CTest)
if (${BUILD_TESTING})
  enable_testing()
  add_subdirectory(test)
endif()
