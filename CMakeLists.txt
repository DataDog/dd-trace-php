cmake_minimum_required(VERSION 3.14)

option(HUNTER_STATUS_DEBUG "Print Hunter debug info" OFF)

include("cmake/HunterGate.cmake")
HunterGate(
    URL "https://github.com/cpp-pm/hunter/archive/v0.23.314.tar.gz"
    SHA1 "95c47c92f68edb091b5d6d18924baabe02a6962a")

project(ddappsec VERSION 0.3.1)

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
option(DD_APPSEC_ENABLE_TRACER "Whether to make the tracer  available" ON)
set(DD_APPSEC_INSTALL_RULES_FILE "" CACHE FILEPATH "Use this rules file instead of checking out appsec-event-rules")

option(DD_APPSEC_ENABLE_COVERAGE "Whether to enable coverage calculation" OFF)
option(DD_APPSEC_ENABLE_PATCHELF_LIBC "Whether to remove dependency on libc.so (musl)" OFF)

add_subdirectory(third_party EXCLUDE_FROM_ALL)

include(cmake/patchelf.cmake)
include(cmake/split_debug.cmake)

if(DD_APPSEC_BUILD_HELPER)
	include(cmake/helper.cmake)
endif()

if(DD_APPSEC_BUILD_EXTENSION)
	include(cmake/extension.cmake)
    if(DD_APPSEC_ENABLE_TRACER)
        include(cmake/tracer.cmake)
    endif()
endif()

include(cmake/clang-tidy.cmake)
include(cmake/clang-format.cmake)
