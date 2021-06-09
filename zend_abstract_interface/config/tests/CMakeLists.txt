add_executable(config ext_zai_config.cc config.cc config_decode.cc
                      config_ini.cc)

target_link_libraries(config PUBLIC catch2_main Zai::Sapi Zai::Config)

catch_discover_tests(config)
