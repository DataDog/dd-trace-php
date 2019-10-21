#[[
 We need to be able to set the C version in a standard way, which was not
 added until CMake 3.1 with the C_STANDARD property.

 The feature c_std_99 was added to CMake 3.8. The feature is more desriable
 than C_STANDARD because it's a minimum rather than an exact. This way if a
 compiler prefers a newer version it is free to do so, and most compilers on
 the latest releases do prefer a newer C standard.

              Operating System Matrix
 OS      OS_VERSION  OS_CODENAME   CMAKE_VERSION
 Debian              Jessie        3.0.2
 Debian              Stretch       3.7.2
 Debian              Buster        3.13
 CentOS  6                         2.8.12
 CentOS  7                         2.8.12
 CentOS  8                         3.11
 Ubuntu  14.04       Xenial        2.8.12
 Ubuntu  16.04       Xenial        3.5
 Ubuntu  18.04       Bionic        3.10

 I decided to pick the next lowest version after 3.8 that is supported by a
 major operating system: 3.10.
#]]
cmake_minimum_required(VERSION 3.10)
project(DogstatsdClient
  VERSION 0.1.0
  LANGUAGES C
)

add_library(DogstatsdClient client.c)
target_include_directories(DogstatsdClient PUBLIC
  $<BUILD_INTERFACE:${PROJECT_SOURCE_DIR}/>
  $<INSTALL_INTERFACE:include>
)
target_compile_features(DogstatsdClient PRIVATE c_std_99)

set_target_properties(DogstatsdClient PROPERTIES
  OUTPUT_NAME dogstatsd_client # It should be named libdogstatsd_client.{a,so}, not libDogstatsdClient.{a,so}
  PUBLIC_HEADER dogstatsd_client/client.h
  VERSION ${PROJECT_VERSION}
)

#[[
 We want to be able to use the namespaced name everywhere, including in this project's tests;
 this is a pattern described in the talk Effective CMake that allows that
#]]
add_library(DogstatsdClient::DogstatsdClient ALIAS DogstatsdClient)

# Add infrastructure for enabling tests
option(BUILD_TESTING "Enable tests" OFF)
include(CTest)
if (${BUILD_TESTING})
  enable_testing()
  add_subdirectory(test)
endif()

# Everything below this line is for usage in other projects via CMake or pkg-config

install(
  TARGETS DogstatsdClient
  EXPORT DogstatsdClientTargets
  ARCHIVE DESTINATION lib
  LIBRARY DESTINATION lib
  RUNTIME DESTINATION bin
  PUBLIC_HEADER DESTINATION include/dogstatsd_client
  INCLUDES DESTINATION include/dogstatsd_client
)

install(
  EXPORT DogstatsdClientTargets
  NAMESPACE DogstatsdClient::
  DESTINATION share/cmake/DogstatsdClient
)

# This allows the exports to be used from the build tree, instead of only after installing
export(
  TARGETS DogstatsdClient
  FILE DogstatsdClientExports.cmake
)

include(CMakePackageConfigHelpers)

# When the library is more stable, change ExactVersion to SameMajorVersion
write_basic_package_version_file("DogstatsdClientVersion.cmake"
  VERSION ${PROJECT_VERSION}
  COMPATIBILITY ExactVersion
)

install(
  FILES "${PROJECT_BINARY_DIR}/DogstatsdClientVersion.cmake"
  DESTINATION share/cmake/DogstatsdClient
)

# pkg-config support
configure_file(${PROJECT_SOURCE_DIR}/dogstatsd_client.pc.in
  ${PROJECT_BINARY_DIR}/share/pkgconfig/dogstatsd_client.pc @ONLY
)

install(
  FILES ${PROJECT_BINARY_DIR}/share/pkgconfig/dogstatsd_client.pc
  DESTINATION share/pkgconfig
)
