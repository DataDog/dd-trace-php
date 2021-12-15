add_executable(ddappsec_helper_fuzzer ${HELPER_SOURCE} main.cpp mutators.cpp)
set_target_properties(ddappsec_helper_fuzzer PROPERTIES COMPILE_FLAGS "-fsanitize=fuzzer,address,leak -fprofile-instr-generate -fcoverage-mapping")
set_target_properties(ddappsec_helper_fuzzer PROPERTIES LINK_FLAGS "-fsanitize=fuzzer,address,leak -fprofile-instr-generate -fcoverage-mapping")
target_include_directories(ddappsec_helper_fuzzer PRIVATE ${HELPER_INCLUDE_DIR})
target_link_libraries(ddappsec_helper_fuzzer
    PRIVATE libddwaf_objects pthread spdlog msgpack_c lib_rapidjson Boost::system)

add_executable(corpus_generator corpus_generator.cpp)
target_link_libraries(corpus_generator PRIVATE helper_objects libddwaf_objects pthread spdlog)
