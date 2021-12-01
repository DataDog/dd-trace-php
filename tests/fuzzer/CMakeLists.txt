add_executable(ddappsec_helper_fuzzer main.cpp mutators.cpp)
set_target_properties(ddappsec_helper_fuzzer PROPERTIES COMPILE_FLAGS "-fsanitize=fuzzer,address,leak -fprofile-instr-generate -fcoverage-mapping")
set_target_properties(ddappsec_helper_fuzzer PROPERTIES LINK_FLAGS "-fsanitize=fuzzer,address,leak -fprofile-instr-generate -fcoverage-mapping")
target_link_libraries(ddappsec_helper_fuzzer
    PRIVATE helper_objects libddwaf_objects pthread spdlog)

add_executable(corpus_generator corpus_generator.cpp)
target_link_libraries(corpus_generator PRIVATE helper_objects libddwaf_objects pthread spdlog)
