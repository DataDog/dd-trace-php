add_executable(sandbox error.cc exception.cc sandbox.cc)

target_link_libraries(sandbox PUBLIC catch2_main Tea::Tea Zai::Sandbox)

file(COPY ${CMAKE_CURRENT_SOURCE_DIR}/stubs
     DESTINATION ${CMAKE_CURRENT_BINARY_DIR})

catch_discover_tests(sandbox)
