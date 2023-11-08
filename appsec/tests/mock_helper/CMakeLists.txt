cmake_minimum_required(VERSION 3.11)
project(mock_helper)

hunter_add_package(Boost COMPONENTS coroutine context program_options system thread)

find_package(Boost CONFIG REQUIRED
    COMPONENTS coroutine context program_options system thread)
# Unfortunaly, hunter does not support OPTIONAL_COMPONENTS
#    OPTIONAL_COMPONENTS stacktrace_backtrace stacktrace_basic)

hunter_add_package(RapidJSON)
find_package(RapidJSON CONFIG REQUIRED)

add_executable(mock_helper
    mock_helper_main.cc mock_dd_agent.cc)
set_property(TARGET mock_helper
    PROPERTY CXX_STANDARD 17)
target_compile_definitions(mock_helper PRIVATE
    BOOST_ASIO_NO_DEPRECATED SPDLOG_ACTIVE_LEVEL=0)
target_compile_options(mock_helper PRIVATE
    -Wall -pedantic -Werror)
target_link_libraries(mock_helper PRIVATE
    Boost::boost
    Boost::coroutine
    Boost::program_options
    Boost::system
    Boost::thread
    Boost::context
    mpack
    spdlog
    RapidJSON::rapidjson)

# vim: set et:
