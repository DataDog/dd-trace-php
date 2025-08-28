add_executable(exceptions exceptions.cc)

target_link_libraries(exceptions PUBLIC catch2_main Tea::Tea Zai::Exceptions Zai::Sandbox)

file(COPY ${CMAKE_CURRENT_SOURCE_DIR}/stubs
     DESTINATION ${CMAKE_CURRENT_BINARY_DIR})

catch_discover_tests(exceptions)
