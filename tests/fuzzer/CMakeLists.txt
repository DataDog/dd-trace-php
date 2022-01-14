if (CMAKE_CXX_COMPILER_ID STREQUAL "Clang" AND CMAKE_CXX_COMPILER_VERSION VERSION_GREATER_EQUAL 13.0.0)
    add_executable(ddappsec_helper_fuzzer ${HELPER_SOURCE} main.cpp mutators.cpp)
    set_target_properties(ddappsec_helper_fuzzer PROPERTIES COMPILE_FLAGS "-fsanitize=fuzzer-no-link,address,leak -fprofile-instr-generate -fcoverage-mapping")
    set_target_properties(ddappsec_helper_fuzzer PROPERTIES LINK_FLAGS "-fsanitize=fuzzer-no-link,address,leak -fprofile-instr-generate -fcoverage-mapping")
    target_include_directories(ddappsec_helper_fuzzer PRIVATE ${HELPER_INCLUDE_DIR})

    execute_process(
        COMMAND ${CMAKE_CXX_COMPILER} -print-runtime-dir
        OUTPUT_VARIABLE LLVM_RUNTIME_DIR
        OUTPUT_STRIP_TRAILING_WHITESPACE
    )

    target_link_directories(ddappsec_helper_fuzzer PRIVATE ${LLVM_RUNTIME_DIR})
    target_link_libraries(ddappsec_helper_fuzzer
        PRIVATE libddwaf_objects pthread spdlog msgpack_c lib_rapidjson Boost::system libclang_rt.fuzzer_no_main-x86_64.a)

    add_executable(corpus_generator corpus_generator.cpp)
    target_link_libraries(corpus_generator PRIVATE helper_objects libddwaf_objects pthread spdlog)
endif()
