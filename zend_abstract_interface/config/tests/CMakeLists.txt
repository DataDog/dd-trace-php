add_executable(config ext_zai_config.cc default.cc env.cc id.cc decode.cc ini.cc)

set(THREADS_PREFER_PTHREAD_FLAG ON)
find_package(Threads REQUIRED)

target_link_libraries(config PUBLIC catch2_main ZaiSapi::ZaiSapi Zai::Config Zai::Json Threads::Threads)

catch_discover_tests(config)
