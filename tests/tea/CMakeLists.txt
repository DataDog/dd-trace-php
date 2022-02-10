cmake_minimum_required(VERSION 3.19)
project(ExtTeaTests LANGUAGES C CXX)

# The Catch2::Catch2 target has been available since 2.1.2 We are unsure of the
# true minimum, but have tested 2.4
find_package(Catch2 2.4 REQUIRED)

set(CMAKE_C_VISIBILITY_PRESET "hidden")
set(CMAKE_CXX_VISIBILITY_PRESET "hidden")
set(CMAKE_POSITION_INDEPENDENT_CODE on)

#[[ This file takes a while to build, so we do it once here and every test
executable can link to it to save time.
]]
if(NOT TARGET Catch2::Catch2WithMain AND TARGET Catch2::Catch2)
  #[[ The build of catch2 we are using wasn't configured with
      `CATCH_BUILD_STATIC_LIBRARY`; let's polyfill it.
  ]]
  file(WRITE ${CMAKE_CURRENT_BINARY_DIR}/catch2withmain.cc
       "#define CATCH_CONFIG_MAIN\n" "#include <catch2/catch.hpp>\n")

  add_library(Catch2WithMain ${CMAKE_CURRENT_BINARY_DIR}/catch2withmain.cc)
  target_compile_features(Catch2WithMain INTERFACE cxx_std_11)
  target_link_libraries(Catch2WithMain PUBLIC Catch2::Catch2)
  add_library(Catch2::Catch2WithMain ALIAS Catch2WithMain)
endif()

include(Catch)
enable_testing()

find_package(Tea 0.1.0 REQUIRED)
if(NOT TARGET Tea::Tea)
  message(FATAL_ERROR "TEA is required but not found")
endif()

add_library(ext-tea-tests INTERFACE)
target_link_libraries(ext-tea-tests INTERFACE Catch2::Catch2WithMain Tea::Tea)

# Need components, for example stack-sample.
add_subdirectory(../../components ${CMAKE_CURRENT_BINARY_DIR}/components)

# Profiling doesn't support PHP 5, so there haven't been any changes to the
# tracer on those versions to support it.
if(PHP_VERSION_ID GREATER_EQUAL "70000")
  add_subdirectory(profiling)
endif()
