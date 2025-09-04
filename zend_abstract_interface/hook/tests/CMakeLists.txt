add_executable(hooks
    internal/static.cc
    internal/request.cc
)

target_link_libraries(hooks PUBLIC catch2_main Tea::Tea Zai::Sandbox Zai::Hook)
if(PhpConfig_VERNUM GREATER_EQUAL "80000")
  target_link_libraries(hooks PUBLIC Zai::JitUtils)
endif()

file(COPY ${CMAKE_CURRENT_SOURCE_DIR}/stubs
     DESTINATION ${CMAKE_CURRENT_BINARY_DIR})

catch_discover_tests(hooks)
