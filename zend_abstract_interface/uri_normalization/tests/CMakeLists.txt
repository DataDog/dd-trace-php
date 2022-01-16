add_executable(uri_normalization uri_normalization.cc)

target_link_libraries(uri_normalization PUBLIC catch2_main Tea::Tea Zai::UriNormalization)

catch_discover_tests(uri_normalization)
