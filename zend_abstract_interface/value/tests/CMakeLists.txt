add_executable(value "value.cc")

target_link_libraries(value PUBLIC catch2_main Tea::Tea Zai::Value)

catch_discover_tests(value)
