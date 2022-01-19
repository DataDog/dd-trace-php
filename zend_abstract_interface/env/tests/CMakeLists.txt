add_executable(env host.cc error.cc sapi.cc)

target_link_libraries(env PUBLIC catch2_main Tea::Tea Zai::Env)

catch_discover_tests(env)
