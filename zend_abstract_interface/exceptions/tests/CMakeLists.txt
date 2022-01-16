add_executable(exceptions "${PHP_EXCEPTIONS_VERSION_DIRECTORY}/exceptions.cc")

target_link_libraries(exceptions PUBLIC catch2_main Tea::Tea Zai::Functions Zai::Exceptions)

file(COPY ${CMAKE_CURRENT_SOURCE_DIR}/stubs
     DESTINATION ${CMAKE_CURRENT_BINARY_DIR})

catch_discover_tests(exceptions)
