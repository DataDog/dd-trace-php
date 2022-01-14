add_executable(zai_sapi_tests zai_sapi_testing.cc zai_sapi.cc zai_sapi_functions.cc zai_sapi_ini.cc
                              zai_sapi_io.cc)

target_link_libraries(zai_sapi_tests PUBLIC Catch2::Catch2WithMain ZaiSapi::ZaiSapi)

file(COPY ${CMAKE_CURRENT_SOURCE_DIR}/stubs
     DESTINATION ${CMAKE_CURRENT_BINARY_DIR})

catch_discover_tests(zai_sapi_tests)
