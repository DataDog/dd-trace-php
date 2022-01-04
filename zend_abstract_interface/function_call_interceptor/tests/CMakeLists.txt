add_executable(function_call_interceptor "function_call_interceptor.cc")

target_link_libraries(function_call_interceptor PUBLIC catch2_main ZaiSapi::ZaiSapi Zai::FunctionCallInterceptor)

file(COPY ${CMAKE_CURRENT_SOURCE_DIR}/stubs
     DESTINATION ${CMAKE_CURRENT_BINARY_DIR})

catch_discover_tests(function_call_interceptor)
