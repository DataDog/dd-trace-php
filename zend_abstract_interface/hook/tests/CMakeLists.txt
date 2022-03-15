add_executable(hooks
    internal/static.cc
    internal/request.cc
)

target_link_libraries(hooks PUBLIC catch2_main Tea::Tea Zai::Value Zai::Symbols Zai::Hook)

file(COPY ${CMAKE_CURRENT_SOURCE_DIR}/stubs
     DESTINATION ${CMAKE_CURRENT_BINARY_DIR})

catch_discover_tests(hooks)
