add_executable(zai_sapi_tests zai_sapi_ini.cc zai_sapi_io.cc)

target_link_libraries(zai_sapi_tests PUBLIC catch2_main Zai::Sapi)

catch_discover_tests(zai_sapi_tests)
