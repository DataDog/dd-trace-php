add_executable(strings strings.cc)

target_link_libraries(strings PUBLIC catch2_main Tea::Tea Zai::String)

catch_discover_tests(strings)
