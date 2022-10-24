include(FindPackageHandleStandardArgs)

#[[ As of CMake 3.12, the environment variable <Package>_ROOT holds prefixes
    set by the user that should be searched in for <Package>.
 ]]
if(DEFINED ENV{PhpConfig_ROOT})
  set(PhpConfig_ROOT "$ENV{PhpConfig_ROOT}")
elseif(NOT PhpConfig_ROOT)
  set(PhpConfig_ROOT
      ""
      CACHE STRING "Directory containing bin/php-config")
endif()

find_program(
  PhpConfig_EXECUTABLE
  NAMES php-config
  HINTS ${PhpConfig_ROOT} # not quoted! Need it to expand to a list.
)

#[[ The following variables are common for find modules, and are documented:
    https://cmake.org/cmake/help/latest/manual/cmake-developer.7.html
    - <PackageName>_INCLUDE_DIRS (used)
    - <PackageName>_LIBRARIES (used)
    - <PackageName>_DEFINITIONS (not used; should probably set ZTS this way)
    - <PackageName>_EXECUTABLE (used)
    - <PackageName>_LIBRARY_DIRS (used)
    - <PackageName>_ROOT_DIR (used, this is php-config --prefix)
    - <PackageName>_VERSION (used; full version string may include rc1, etc)
    - <PackageName>_VERSION_MAJOR (used)
    - <PackageName>_VERSION_MINOR (used)
    - <PackageName>_VERSION_PATCH (used)

    The following variables are defined as well:
    - PhpConfig_PHP_BINARY (php-config --php-binary)
    - PhpConfig_VERNUM (php-config --vernum)
 ]]

if(PhpConfig_EXECUTABLE)
  execute_process(
    COMMAND ${PhpConfig_EXECUTABLE} --prefix
    RESULT_VARIABLE PhpConfig_PREFIX_RESULT
    OUTPUT_VARIABLE PhpConfig_ROOT_DIR
    OUTPUT_STRIP_TRAILING_WHITESPACE COMMAND_ERROR_IS_FATAL ANY)

  execute_process(
    COMMAND ${PhpConfig_EXECUTABLE} --includes
    RESULT_VARIABLE PhpConfig_INCLUDES_RESULT
    OUTPUT_VARIABLE PhpConfig_INCLUDE_DIRS
    OUTPUT_STRIP_TRAILING_WHITESPACE COMMAND_ERROR_IS_FATAL ANY)

  execute_process(
    COMMAND ${PhpConfig_EXECUTABLE} --ldflags
    RESULT_VARIABLE PhpConfig_LDFLAGS_RESULT
    OUTPUT_VARIABLE PhpConfig_LIBRARY_DIRS
    OUTPUT_STRIP_TRAILING_WHITESPACE COMMAND_ERROR_IS_FATAL ANY)

  execute_process(
    COMMAND ${PhpConfig_EXECUTABLE} --libs
    RESULT_VARIABLE PhpConfig_LIBS_RESULT
    OUTPUT_VARIABLE PhpConfig_LIBRARIES
    OUTPUT_STRIP_TRAILING_WHITESPACE COMMAND_ERROR_IS_FATAL ANY)

  execute_process(
    COMMAND ${PhpConfig_EXECUTABLE} --version
    RESULT_VARIABLE PhpConfig_VERSION_RESULT
    OUTPUT_VARIABLE PhpConfig_VERSION
    OUTPUT_STRIP_TRAILING_WHITESPACE COMMAND_ERROR_IS_FATAL ANY)

  execute_process(
    COMMAND ${PhpConfig_EXECUTABLE} --vernum
    RESULT_VARIABLE PhpConfig_VERNUM_RESULT
    OUTPUT_VARIABLE PhpConfig_VERNUM
    OUTPUT_STRIP_TRAILING_WHITESPACE COMMAND_ERROR_IS_FATAL ANY)

  string(REGEX REPLACE "^([0-9]+)[0-9][0-9][0-9][0-9]$" "\\1"
                       PhpConfig_VERSION_MAJOR "${PhpConfig_VERNUM}")
  string(REGEX REPLACE "^0([0-9])$" "\\1" PhpConfig_VERSION_MAJOR
                       "${PhpConfig_VERSION_MAJOR}")

  string(REGEX REPLACE "[0-9]+([0-9][0-9])[0-9][0-9]$" "\\1"
                       PhpConfig_VERSION_MINOR "${PhpConfig_VERNUM}")
  string(REGEX REPLACE "^0([0-9])$" "\\1" PhpConfig_VERSION_MINOR
                       "${PhpConfig_VERSION_MINOR}")

  string(REGEX REPLACE "[0-9]+([0-9][0-9])$" "\\1" PhpConfig_VERSION_PATCH
                       "${PhpConfig_VERNUM}")
  string(REGEX REPLACE "^0([0-9])$" "\\1" PhpConfig_VERSION_PATCH
                       "${PhpConfig_VERSION_PATCH}")

  execute_process(
    COMMAND ${PhpConfig_EXECUTABLE} --php-binary
    RESULT_VARIABLE PhpConfig_PHP_BINARY_RESULT
    OUTPUT_VARIABLE PhpConfig_PHP_BINARY
    OUTPUT_STRIP_TRAILING_WHITESPACE COMMAND_ERROR_IS_FATAL ANY)
endif()

find_package_handle_standard_args(
  PhpConfig
  REQUIRED_VARS PhpConfig_EXECUTABLE PhpConfig_ROOT_DIR
  VERSION_VAR PhpConfig_VERSION HANDLE_VERSION_RANGE)

if(PhpConfig_FOUND)
  separate_arguments(PhpConfig_INCLUDE_DIRS)
  separate_arguments(PhpConfig_LIBRARY_DIRS)

  string(REPLACE "-I" "" PhpConfig_INCLUDE_DIRS "${PhpConfig_INCLUDE_DIRS}")
  string(REPLACE "-L" "" PhpConfig_LIBRARY_DIRS "${PhpConfig_LIBRARY_DIRS}")

  mark_as_advanced(PhpConfig_EXECUTABLE)

  #[[ Produce a target for users to link with, so they don't have to add all
      these settings, which may change over time. Example:
      target_link_libraries(mylib PRIVATE PhpConfig::PhpConfig)
   ]]
  add_library(PhpConfig INTERFACE)
  target_include_directories(PhpConfig INTERFACE ${PhpConfig_INCLUDE_DIRS})
  target_link_directories(PhpConfig INTERFACE ${PhpConfig_LIBRARY_DIRS})
  target_compile_features(PhpConfig INTERFACE c_std_99)

  #[[ Do not link these automatically, as they are probably unused and can
      cause extra runtime linking dependencies.
  ]]
  # target_link_libraries(PhpConfig INTERFACE ${PhpConfig_LIBRARIES})

  #[[ On Linux, undefined symbols do not cause the compile time linker to error,
      but the Darwin linker will. The PHP ecosystem does not link against a
      common library like libzend.so and the symbols are provided by the PHP
      SAPI instead. So, when compiling a module for PHP allow for undefined
      symbols (sadly, as this means you don't get an error until runtime linking
      instead).
   ]]
  if(${CMAKE_SYSTEM_NAME} MATCHES "Darwin")
    target_link_options(PhpConfig INTERFACE -undefined dynamic_lookup)
  endif()

  add_library(PhpConfig::PhpConfig ALIAS PhpConfig)
endif()
