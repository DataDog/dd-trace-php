add_executable(headers "headers.cc")

target_link_libraries(headers PUBLIC catch2_main Tea::Tea Zai::Headers)

catch_discover_tests(headers)
