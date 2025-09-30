cmake_minimum_required(VERSION 3.14)

get_cmake_property(vars CACHE_VARIABLES)
foreach(var ${vars})
    get_property(helpString CACHE "${var}" PROPERTY HELPSTRING)
    if("${helpString}" MATCHES "No help, variable specified on the command line." OR "${helpString}" STREQUAL "")
        list(APPEND CMAKE_CLI_ARGS "-D${var}=${${var}}")
    endif()
endforeach()

list(APPEND CMAKE_MODULE_PATH "${CMAKE_CURRENT_LIST_DIR}/../cmake/Modules")

file(READ "../VERSION" appsec_version)
string(STRIP "${appsec_version}" appsec_version)
set(CMAKE_APPSEC_VERSION ${appsec_version})
string(REGEX MATCH "^[^+a-z]*" appsec_short_version "${appsec_version}")
project(ddappsec VERSION ${appsec_short_version})

include(CheckCXXCompilerFlag)

set(CMAKE_EXPORT_COMPILE_COMMANDS ON)
set(CMAKE_C_STANDARD 11)
set(CMAKE_C_STANDARD_REQUIRED ON)
set(CMAKE_CXX_STANDARD 17)
set(CMAKE_CXX_STANDARD_REQUIRED ON)

cmake_policy(SET CMP0083 NEW) # make PIE executables when PIC property is set
if(POLICY CMP0144)
    cmake_policy(SET CMP0144 NEW) # consider RAPIDJSON_ROOT, not just RapidJSON_ROOT
endif()
if(POLICY CMP0153)
    cmake_policy(SET CMP0153 OLD) # allow exec_program
endif()

option(DD_APPSEC_BUILD_HELPER "Whether to builder the helper" ON)
option(DD_APPSEC_BUILD_EXTENSION "Whether to builder the extension" ON)
option(DD_APPSEC_ENABLE_COVERAGE "Whether to enable coverage calculation" OFF)
option(DD_APPSEC_TESTING "Whether to enable testing" ON)
option(DD_APPSEC_DDTRACE_ALT "Whether to build appsec with cmake" OFF)
option(DD_APPSEC_EXTENSION_STATIC_LIBSTDCXX "Whether to link the extension with -static-libstdc++ (not available on macOS)" OFF)

add_subdirectory(third_party EXCLUDE_FROM_ALL)

include("cmake/patchelf.cmake")
include("cmake/coverage.cmake")
include("cmake/boost.cmake")

if(DD_APPSEC_BUILD_EXTENSION OR DD_APPSEC_DDTRACE_ALT)
    find_package(PhpConfig REQUIRED)
    message(STATUS "PHP version: ${PhpConfig_VERSION}")
    message(STATUS "PHP include directories: ${PhpConfig_INCLUDE_DIRS}")
    message(STATUS "PHP libraries: ${PhpConfig_LIBRARIES}")
    include("cmake/ext_asan.cmake")
endif()

if(DD_APPSEC_BUILD_EXTENSION)
    include("cmake/extension.cmake")
endif()

if(DD_APPSEC_BUILD_HELPER)
    include ("cmake/helper.cmake")
endif()

if(DD_APPSEC_DDTRACE_ALT)
    include(cmake/ddtrace.cmake)
endif()

include(cmake/clang-tidy.cmake)
include(cmake/clang-format.cmake)

# When you enable this option. The file cmake/local.cmake will be included as part of the build.
# The file cmake/local.cmake is ignored by git. Feel free to add anything you need to support
# your local build. One example this is used for is to tell Clion where to find PHP Sources
option(DD_APPSEC_LOCAL_CONFIGURATIONS "Whether to include the local.cmake file content as part of the build process" OFF)
if(DD_APPSEC_LOCAL_CONFIGURATIONS)
    include(cmake/local.cmake)
endif()
