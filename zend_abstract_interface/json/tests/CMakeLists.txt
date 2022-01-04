add_executable(json json.cc)

target_link_libraries(json PUBLIC catch2_main ZaiSapi::ZaiSapi Zai::Json)

catch_discover_tests(json)
