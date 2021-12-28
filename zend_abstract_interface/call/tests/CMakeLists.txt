add_executable(call call.cc)

target_link_libraries(call PUBLIC catch2_main Zai::Sapi Zai::Call)

file(COPY ${CMAKE_CURRENT_SOURCE_DIR}/stubs
     DESTINATION ${CMAKE_CURRENT_BINARY_DIR})

catch_discover_tests(call)
