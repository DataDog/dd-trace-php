add_executable(methods "methods.cc")

target_link_libraries(methods PUBLIC catch2_main Zai::Sapi Zai::Methods)

file(COPY ${CMAKE_CURRENT_SOURCE_DIR}/stubs
     DESTINATION ${CMAKE_CURRENT_BINARY_DIR})

catch_discover_tests(methods)
