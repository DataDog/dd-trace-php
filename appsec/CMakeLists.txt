cmake_minimum_required(VERSION 3.14)

list(APPEND CMAKE_MODULE_PATH "${CMAKE_CURRENT_LIST_DIR}/../cmake/Modules")

option(HUNTER_STATUS_DEBUG "Print Hunter debug info" OFF)

include("cmake/HunterGate.cmake")
HunterGate(
    URL "https://github.com/cpp-pm/hunter/archive/v0.23.314.tar.gz"
    SHA1 "95c47c92f68edb091b5d6d18924baabe02a6962a")

project(ddappsec VERSION 0.91.0)

include(CheckCCompilerFlag)
include(CheckCXXCompilerFlag)

set(CMAKE_EXPORT_COMPILE_COMMANDS ON)
set(CMAKE_C_STANDARD 11)
set(CMAKE_C_STANDARD_REQUIRED ON)
set(CMAKE_CXX_STANDARD 17)
set(CMAKE_CXX_STANDARD_REQUIRED ON)
cmake_policy(SET CMP0083 NEW) # make PIE executables when PIC property is set

option(DD_APPSEC_BUILD_HELPER "Whether to builder the helper" ON)
option(DD_APPSEC_BUILD_EXTENSION "Whether to builder the extension" ON)
option(DD_APPSEC_ENABLE_COVERAGE "Whether to enable coverage calculation" OFF)

add_subdirectory(third_party EXCLUDE_FROM_ALL)

if (DD_APPSEC_BUILD_EXTENSION)
    include("cmake/extension.cmake")
endif()

if (DD_APPSEC_BUILD_HELPER)
    include ("cmake/helper.cmake")
endif()
