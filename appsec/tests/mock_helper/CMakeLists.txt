cmake_minimum_required(VERSION 3.11)
project(mock_helper)

if(POLICY CMP0153)
cmake_policy(SET CMP0153 OLD) # allow exec_program
endif()

add_executable(mock_helper
    mock_helper_main.cc mock_dd_agent.cc)
set_property(TARGET mock_helper
    PROPERTY CXX_STANDARD 17)
target_compile_definitions(mock_helper PRIVATE
    BOOST_ASIO_NO_DEPRECATED
    BOOST_STACKTRACE_LINK=1)
target_compile_options(mock_helper PRIVATE
    -Wall -pedantic -Werror)
target_link_libraries(mock_helper PRIVATE
    boost_coroutine
    boost_program_options
    boost_system
    boost_thread
    boost_context
    boost_stacktrace
    mpack_malloc
    spdlog
    rapidjson_appsec)

# vim: set et:
