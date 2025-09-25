# Need CMake 3.19 for Tea.
cmake_minimum_required(VERSION 3.19)

project(
  zend-abstract-interface
  VERSION 0.1.0
  LANGUAGES C)

# Get our FindPhpConfig helper.
list(APPEND CMAKE_MODULE_PATH "${CMAKE_CURRENT_LIST_DIR}/../cmake/Modules")

option(BUILD_ZAI_TESTING "Enable tests" OFF)
if(${BUILD_ZAI_TESTING})
  # Tests uses the C++ testing framework Catch2
  enable_language(CXX)

  # The Catch2::Catch2 target has been available since 2.1.2 We are unsure of
  # the true minimum, but have tested 2.4
  find_package(Catch2 2.4 REQUIRED)

  include(components_rs.cmake)

  #[[ This file takes a while to build, so we do it once here and every test
      executable can link to it to save time.
  ]]
  add_library(catch2_main catch2_main.cc)
  target_link_libraries(catch2_main PUBLIC Catch2::Catch2 components_rs)
  target_compile_features(catch2_main PUBLIC cxx_std_11)

  include(Catch)
  enable_testing()
endif()

option(RUN_SHARED_EXTS_TESTS "Enable shared extension tests" OFF)
if(${RUN_SHARED_EXTS_TESTS})
  add_definitions(-DRUN_SHARED_EXTS_TESTS)
endif()

include(GNUInstallDirs)

add_library(zai_zend_abstract_interface INTERFACE)

find_package(PhpConfig REQUIRED)

if(PhpConfig_VERNUM LESS "70000")
  set(PHP_VERSION_DIRECTORY "php5")
  message(STATUS "Detected PHP 5 from version id: ${PhpConfig_VERNUM}")
elseif(PhpConfig_VERNUM LESS "80000")
  message(STATUS "Detected PHP 7 from version id: ${PhpConfig_VERNUM}")
  set(PHP_VERSION_DIRECTORY "php7")
elseif(PhpConfig_VERNUM LESS "90000")
  message(STATUS "Detected PHP 8 from version id: ${PhpConfig_VERNUM}")
  set(PHP_VERSION_DIRECTORY "php8")
else()
  message(FATAL_ERROR "Unsupported PHP version '${PhpConfig_VERNUM}'.")
endif()

find_package(Tea 0.1.0 REQUIRED)
if(NOT TARGET Tea::Tea)
  message(FATAL_ERROR "TEA is required but not found")
endif()

if(NOT TARGET Tea::Php)
  message(FATAL_ERROR "TEA is required but Tea::Php not found")
endif()

add_subdirectory(env)
add_subdirectory(exceptions)
add_subdirectory(config)
add_subdirectory(json)
if(PhpConfig_VERNUM GREATER_EQUAL "80000")
  add_subdirectory(jit_utils)
endif()
add_subdirectory(hook)
add_subdirectory(interceptor)
add_subdirectory(headers)
add_subdirectory(sandbox)
add_subdirectory(uri_normalization)
add_subdirectory(zai_string)
add_subdirectory(zai_assert)

install(
  EXPORT ZendAbstractInterfaceTargets
  FILE ZendAbstractInterfaceTargets.cmake
  NAMESPACE Zai::
  DESTINATION ${CMAKE_INSTALL_LIBDIR}/cmake)
