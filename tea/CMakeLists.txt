cmake_minimum_required(VERSION 3.19)

project(
  Tea
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

add_library(tea-libphp INTERFACE)
set_target_properties(tea-libphp PROPERTIES EXPORT_NAME PHP)
target_link_libraries(tea-libphp INTERFACE "${PHP_LIB}")
target_include_directories(tea-libphp INTERFACE
  "${PHP_PREFIX_PATH}/include/php" "${PHP_PREFIX_PATH}/include/php/TSRM"
  "${PHP_PREFIX_PATH}/include/php/Zend" "${PHP_PREFIX_PATH}/include/php/main")

add_library(Tea src/extension.c src/error.c src/frame.c src/exceptions.c src/ini.c src/io.c src/sapi.c)

target_include_directories(
  Tea PUBLIC $<BUILD_INTERFACE:${CMAKE_CURRENT_SOURCE_DIR}/>
             $<BUILD_INTERFACE:${CMAKE_CURRENT_SOURCE_DIR}/include>
             $<INSTALL_INTERFACE:${CMAKE_INSTALL_INCLUDEDIR}>)

target_compile_features(Tea PUBLIC c_std_99)

target_link_libraries(Tea PUBLIC tea-libphp)

set_target_properties(Tea PROPERTIES VERSION ${PROJECT_VERSION})

add_library(Tea::Tea ALIAS Tea)

option(BUILD_TEA_TESTING "Enable TEA tests" OFF)
if(${BUILD_TEA_TESTING})

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
    FILES ${CMAKE_CURRENT_SOURCE_DIR}/include/testing/catch2.hpp
    DESTINATION ${CMAKE_INSTALL_INCLUDEDIR}/tea/testing)
endif()

# Copy the include files on install
install(
  FILES ${CMAKE_CURRENT_SOURCE_DIR}/include/common.h
        ${CMAKE_CURRENT_SOURCE_DIR}/include/extension.h
        ${CMAKE_CURRENT_SOURCE_DIR}/include/frame.h
        ${CMAKE_CURRENT_SOURCE_DIR}/include/error.h
        ${CMAKE_CURRENT_SOURCE_DIR}/include/exceptions.h
        ${CMAKE_CURRENT_SOURCE_DIR}/include/sapi.h
  DESTINATION ${CMAKE_INSTALL_INCLUDEDIR}/tea)
# Copy the static library on install
install(
  TARGETS Tea tea-libphp
  EXPORT TeaTargets
  ARCHIVE)
# Copy the .cmake files on install
export(
  TARGETS Tea tea-libphp
  NAMESPACE Tea::
  FILE ${CMAKE_CURRENT_BINARY_DIR}/TeaTargets.cmake)
install(
  EXPORT TeaTargets
  NAMESPACE Tea::
  FILE TeaTargets.cmake
  DESTINATION ${CMAKE_INSTALL_PREFIX}/cmake)
include(CMakePackageConfigHelpers)
configure_package_config_file(
  ${CMAKE_CURRENT_SOURCE_DIR}/cmake/TeaConfig.cmake.in
  ${CMAKE_CURRENT_BINARY_DIR}/TeaConfig.cmake
  INSTALL_DESTINATION ${CMAKE_INSTALL_PREFIX}/cmake)
write_basic_package_version_file(
  ${CMAKE_CURRENT_BINARY_DIR}/TeaConfigVersion.cmake
  VERSION ${CMAKE_PROJECT_VERSION}
  COMPATIBILITY SameMajorVersion)
install(FILES ${CMAKE_CURRENT_BINARY_DIR}/TeaConfig.cmake
              ${CMAKE_CURRENT_BINARY_DIR}/TeaConfigVersion.cmake
        DESTINATION ${CMAKE_INSTALL_PREFIX}/cmake)
