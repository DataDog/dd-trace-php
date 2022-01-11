add_executable(value "value.cc")

target_link_libraries(value PUBLIC catch2_main ZaiSapi::ZaiSapi Zai::Value)

catch_discover_tests(value)
