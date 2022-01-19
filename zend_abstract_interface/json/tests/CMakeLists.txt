add_executable(json json.cc)

target_link_libraries(json PUBLIC catch2_main Tea::Tea Zai::Json)

catch_discover_tests(json)
