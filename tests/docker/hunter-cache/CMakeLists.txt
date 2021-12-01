cmake_minimum_required(VERSION 3.14)

set(CMAKE_BUILD_TYPE RelWithDebInfo)
set(CMAKE_TOOLCHAIN_FILE /build/Toolchain.cmake)

include("HunterGate.cmake")
set(HUNTER_STATUS_DEBUG ON)
HunterGate(
    URL "https://github.com/cpp-pm/hunter/archive/v0.23.314.tar.gz"
    SHA1 "95c47c92f68edb091b5d6d18924baabe02a6962a")

project(populate_hunter_cache)

hunter_add_package(Boost COMPONENTS coroutine context program_options system stacktrace thread)
hunter_add_package(RapidJSON)
hunter_add_package(gflags)
