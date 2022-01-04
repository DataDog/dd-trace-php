add_executable(env env.cc)

target_link_libraries(env PUBLIC catch2_main ZaiSapi::ZaiSapi Zai::Env)

catch_discover_tests(env)
