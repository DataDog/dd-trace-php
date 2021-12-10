add_executable(headers "headers.cc")

target_link_libraries(headers PUBLIC catch2_main ZaiSapi::ZaiSapi Zai::Headers)

catch_discover_tests(headers)
