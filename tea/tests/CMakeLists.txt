add_executable(tea_tests testing.cc extension.cc sapi.cc error.cc ini.cc io.cc)

target_link_libraries(tea_tests PUBLIC Catch2::Catch2WithMain Tea::Tea)

file(COPY ${CMAKE_CURRENT_SOURCE_DIR}/stubs
     DESTINATION ${CMAKE_CURRENT_BINARY_DIR})

catch_discover_tests(tea_tests)
