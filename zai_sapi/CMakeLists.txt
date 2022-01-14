cmake_minimum_required(VERSION 3.19)

project(
  ZaiSapi
  VERSION 0.1.0
  LANGUAGES C)

include(GNUInstallDirs)

# Get the PHP prefix path from php-config
execute_process(
  COMMAND ${PHP_CONFIG} --prefix
  OUTPUT_VARIABLE PHP_PREFIX_PATH
  OUTPUT_STRIP_TRAILING_WHITESPACE
  RESULT_VARIABLE ret)

if(NOT ret EQUAL "0")
  message(
    FATAL_ERROR
      "Failed to execute 'php-config'. Check that PHP_CONFIG is set to the 'php-config' executable."
  )
endif()

find_library(
  PHP_LIB
  # Before PHP 8 the lib was named, 'libphp<version>.so'
  NAMES php php7 php5
  PATHS "${PHP_PREFIX_PATH}/lib"
  NO_DEFAULT_PATH
  # 'REQUIRED' added in cmake v3.18
  REQUIRED)

include_directories(
  "${PHP_PREFIX_PATH}/include/php/" "${PHP_PREFIX_PATH}/include/php/TSRM"
  "${PHP_PREFIX_PATH}/include/php/Zend" "${PHP_PREFIX_PATH}/include/php/ext"
  "${PHP_PREFIX_PATH}/include/php/main")

#[[ Get the PHP version number from php-config. This is used to compile the
    version-specific source files.
]]
execute_process(
  COMMAND ${PHP_CONFIG} --vernum
  OUTPUT_VARIABLE PHP_VERSION_ID
  OUTPUT_STRIP_TRAILING_WHITESPACE
  RESULT_VARIABLE ret)

if(NOT ret EQUAL "0")
  message(FATAL_ERROR "Failed to get the PHP version number from 'php-config'.")
endif()

if(PHP_VERSION_ID LESS "70000")
  set(PHP_VERSION_DIRECTORY "php5")
elseif(PHP_VERSION_ID LESS "80000")
  set(PHP_VERSION_DIRECTORY "php7")
elseif(PHP_VERSION_ID LESS "90000")
  set(PHP_VERSION_DIRECTORY "php8")
else()
  message(FATAL_ERROR "Unsupported PHP version '${PHP_VERSION_ID}'.")
endif()

add_library(ZaiSapi zai_sapi_extension.c zai_sapi_functions.c zai_sapi_ini.c
                    zai_sapi_io.c "${PHP_VERSION_DIRECTORY}/zai_sapi.c")

target_include_directories(
  ZaiSapi PUBLIC $<BUILD_INTERFACE:${CMAKE_CURRENT_SOURCE_DIR}/..>
                 $<INSTALL_INTERFACE:${CMAKE_INSTALL_INCLUDEDIR}>)

target_compile_features(ZaiSapi PUBLIC c_std_99)

target_link_libraries(ZaiSapi PUBLIC "${PHP_LIB}")

set_target_properties(ZaiSapi PROPERTIES VERSION ${PROJECT_VERSION})

add_library(ZaiSapi::ZaiSapi ALIAS ZaiSapi)

option(BUILD_ZAI_SAPI_TESTING "Enable ZAI SAPI tests" OFF)
if(${BUILD_ZAI_SAPI_TESTING})

  # Tests use the C++ testing framework Catch2
  enable_language(CXX)

  # The Catch2::Catch2 target has been available since 2.1.2 We are unsure of
  # the true minimum, but have tested 2.4
  find_package(Catch2 2.4 REQUIRED)

  include(Catch)

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

  if(NOT TARGET Catch2::Catch2WithMain)
    message(FATAL_ERROR "Catch2WithMain not found and polyfill failed.")
  endif()

  enable_testing()
  add_subdirectory(tests)

  install(
    FILES ${CMAKE_CURRENT_SOURCE_DIR}/testing/catch2.hpp
    DESTINATION ${CMAKE_INSTALL_INCLUDEDIR}/zai_sapi/testing)
endif()

# Copy the include files on install
install(
  FILES ${CMAKE_CURRENT_SOURCE_DIR}/zai_sapi.h
        ${CMAKE_CURRENT_SOURCE_DIR}/zai_sapi_extension.h
        ${CMAKE_CURRENT_SOURCE_DIR}/zai_sapi_functions.h
        ${CMAKE_CURRENT_SOURCE_DIR}/zai_sapi_ini.h
        ${CMAKE_CURRENT_SOURCE_DIR}/zai_sapi_io.h
  DESTINATION ${CMAKE_INSTALL_INCLUDEDIR}/zai_sapi)
# Copy the static library on install
install(
  TARGETS ZaiSapi
  EXPORT ZaiSapiTargets
  ARCHIVE)
# Copy the .cmake files on install
export(
  TARGETS ZaiSapi
  NAMESPACE ZaiSapi::
  FILE ${CMAKE_CURRENT_BINARY_DIR}/ZaiSapiTargets.cmake)
install(
  EXPORT ZaiSapiTargets
  NAMESPACE ZaiSapi::
  FILE ZaiSapiTargets.cmake
  DESTINATION ${CMAKE_INSTALL_PREFIX}/cmake)
include(CMakePackageConfigHelpers)
configure_package_config_file(
  ${CMAKE_CURRENT_SOURCE_DIR}/cmake/ZaiSapiConfig.cmake.in
  ${CMAKE_CURRENT_BINARY_DIR}/ZaiSapiConfig.cmake
  INSTALL_DESTINATION ${CMAKE_INSTALL_PREFIX}/cmake)
write_basic_package_version_file(
  ${CMAKE_CURRENT_BINARY_DIR}/ZaiSapiConfigVersion.cmake
  VERSION ${CMAKE_PROJECT_VERSION}
  COMPATIBILITY SameMajorVersion)
install(FILES ${CMAKE_CURRENT_BINARY_DIR}/ZaiSapiConfig.cmake
              ${CMAKE_CURRENT_BINARY_DIR}/ZaiSapiConfigVersion.cmake
        DESTINATION ${CMAKE_INSTALL_PREFIX}/cmake)
