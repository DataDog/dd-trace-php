add_executable(interceptor ext_interceptor.cc interceptor.cc)

target_link_libraries(interceptor PUBLIC catch2_main ZaiSapi::ZaiSapi Zai::Interceptor)

file(COPY ${CMAKE_CURRENT_SOURCE_DIR}/stubs
     DESTINATION ${CMAKE_CURRENT_BINARY_DIR})

catch_discover_tests(interceptor)
