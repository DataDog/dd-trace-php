add_executable(functions "functions.cc")

target_link_libraries(functions PUBLIC catch2_main ZaiSapi::ZaiSapi Zai::Functions)

file(COPY ${CMAKE_CURRENT_SOURCE_DIR}/stubs
     DESTINATION ${CMAKE_CURRENT_BINARY_DIR})

catch_discover_tests(functions)
