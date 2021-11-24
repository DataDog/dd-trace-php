add_executable(config ext_zai_config.cc config.cc config_decode.cc
                      config_ini.cc)

set(THREADS_PREFER_PTHREAD_FLAG ON)
find_package(Threads REQUIRED)

target_link_libraries(config PUBLIC catch2_main Zai::Sapi Zai::Config Zai::Json Threads::Threads)

catch_discover_tests(config)
