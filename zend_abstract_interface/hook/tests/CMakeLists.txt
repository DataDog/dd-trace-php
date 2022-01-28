add_executable(internal 
    internal/static.cc
##    internal/request.cc
)

add_executable(user
    user/function.cc
)

target_link_libraries(internal PUBLIC catch2_main Tea::Tea Zai::Value Zai::Symbols Zai::Hook)
target_link_libraries(user PUBLIC catch2_main Tea::Tea Zai::Value Zai::Symbols Zai::Hook)

file(COPY ${CMAKE_CURRENT_SOURCE_DIR}/stubs
     DESTINATION ${CMAKE_CURRENT_BINARY_DIR})

catch_discover_tests(internal)
catch_discover_tests(user)
