add_executable(env host.cc error.cc sapi.cc)

target_link_libraries(env PUBLIC catch2_main ZaiSapi::ZaiSapi Zai::Env)

catch_discover_tests(env)
