cmake_minimum_required(VERSION 3.11)
project(bench_helper)

hunter_add_package(Boost COMPONENTS coroutine context program_options system thread)

find_package(Boost CONFIG REQUIRED
    COMPONENTS coroutine context program_options system thread)

hunter_add_package(RapidJSON)
find_package(RapidJSON CONFIG REQUIRED)

add_executable(bench_helper bench_helper.cc)
set_property(TARGET bench_helper
    PROPERTY CXX_STANDARD 17)
target_compile_definitions(bench_helper PRIVATE
    BOOST_ASIO_NO_DEPRECATED SPDLOG_ACTIVE_LEVEL=0)
target_compile_options(bench_helper PRIVATE
    -Wall -pedantic -Werror)
target_link_libraries(bench_helper PRIVATE
    Boost::boost
    Boost::coroutine
    Boost::program_options
    Boost::system
    Boost::thread
    Boost::context
    mpack
    spdlog)

# vim: set et:
