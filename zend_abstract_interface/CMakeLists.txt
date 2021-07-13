# Need CMake 3.18 for using 'REQUIRED' with find_library().
cmake_minimum_required(VERSION 3.18)

project(zend-abstract-interface VERSION 0.1.0 LANGUAGES C)

option(BUILD_ZAI_TESTING "Enable tests" OFF)
if (${BUILD_ZAI_TESTING})
  # Tests uses the C++ testing framework Catch2
  enable_language(CXX)

  # The Catch2::Catch2 target has been available since 2.1.2
  # We are unsure of the true minimum, but have tested 2.4
  find_package(Catch2 2.4 REQUIRED)

  #[[ This file takes a while to build, so we do it once here and every test
      executable can link to it to save time.
  ]]
  add_library(catch2_main catch2_main.cc)
  target_link_libraries(catch2_main PUBLIC Catch2::Catch2)
  target_compile_features(catch2_main PUBLIC cxx_std_11)

  include(Catch)
  enable_testing()
endif()

option(BUILD_ZAI_ASAN "Enable ASAN" OFF)
if (${BUILD_ZAI_ASAN})
  set(CMAKE_C_FLAGS "${CMAKE_C_FLAGS} -fsanitize=address -fno-omit-frame-pointer")
  set(CMAKE_CXX_FLAGS "${CMAKE_CXX_FLAGS} -fsanitize=address -fno-omit-frame-pointer")
endif()

include(GNUInstallDirs)

add_library(zai_zend_abstract_interface INTERFACE)

# Get the PHP prefix path from php-config
execute_process(COMMAND ${PHP_CONFIG} --prefix
                OUTPUT_VARIABLE PHP_PREFIX_PATH
                OUTPUT_STRIP_TRAILING_WHITESPACE
                RESULT_VARIABLE ret)

if(NOT ret EQUAL "0")
  message(FATAL_ERROR
    "Failed to execute 'php-config'. Check that PHP_CONFIG is set to the 'php-config' executable.")
endif()

find_library(PHP_LIB
             # Before PHP 8 the lib was named, 'libphp<version>.so'
             NAMES php php7 php5 
             PATHS "${PHP_PREFIX_PATH}/lib"
             NO_DEFAULT_PATH
             # 'REQUIRED' added in cmake v3.18
             REQUIRED)

include_directories("${PHP_PREFIX_PATH}/include/php/"
                    "${PHP_PREFIX_PATH}/include/php/TSRM"
                    "${PHP_PREFIX_PATH}/include/php/Zend"
                    "${PHP_PREFIX_PATH}/include/php/ext"
                    "${PHP_PREFIX_PATH}/include/php/main")

#[[ Get the PHP version number from php-config. This is used to compile the
    version-specific source files.
]]
execute_process(COMMAND ${PHP_CONFIG} --vernum
                OUTPUT_VARIABLE PHP_VERSION_ID
                OUTPUT_STRIP_TRAILING_WHITESPACE
                RESULT_VARIABLE ret)

if(NOT ret EQUAL "0")
  message(FATAL_ERROR
          "Failed to get the PHP version number from 'php-config'.")
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

add_subdirectory(zai_sapi)

# All tests depend on zai_sapi
add_subdirectory(config)
add_subdirectory(env)
if(PHP_VERSION_DIRECTORY STREQUAL "php8")
  # TODO Support PHP 5 & PHP 7
  add_subdirectory(functions)
endif()
if(PHP_VERSION_DIRECTORY STREQUAL "php5")
  # TODO Support PHP 7 & PHP 8
  add_subdirectory(methods)
endif()
add_subdirectory(sandbox)
add_subdirectory(zai_string)
add_subdirectory(zai_assert)
if(PHP_VERSION_DIRECTORY STREQUAL "php8")
  # should support php7 as well, but depends on functions ZAI to test, disabling for now
  add_subdirectory(properties)
  add_subdirectory(exceptions)
endif()
if (PHP_VERSION_DIRECTORY STREQUAL "php7" OR PHP_VERSION_DIRECTORY STREQUAL "php8")
  add_subdirectory(headers)
endif()

install(EXPORT ZendAbstractInterfaceTargets
        FILE ZendAbstractInterfaceTargets.cmake
        NAMESPACE Zai::
        DESTINATION ${CMAKE_INSTALL_LIBDIR}/cmake)
