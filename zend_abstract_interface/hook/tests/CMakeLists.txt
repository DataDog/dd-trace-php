add_executable(hooks
    internal/static.cc
    internal/request.cc
)

# Zai::Interceptor supplies zai_line_hook_*, which hook.c calls unconditionally; JitUtils comes with it.
target_link_libraries(hooks PUBLIC catch2_main Tea::Tea Zai::Sandbox Zai::Hook Zai::Interceptor)

file(COPY ${CMAKE_CURRENT_SOURCE_DIR}/stubs
     DESTINATION ${CMAKE_CURRENT_BINARY_DIR})

catch_discover_tests(hooks)
