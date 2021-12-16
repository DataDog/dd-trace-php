add_executable(string string.cc)

target_link_libraries(string PUBLIC catch2_main Zai::Sapi Zai::string)

catch_discover_tests(string)
