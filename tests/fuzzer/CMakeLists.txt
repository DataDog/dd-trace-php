if (CMAKE_CXX_COMPILER_ID STREQUAL "Clang" AND CMAKE_CXX_COMPILER_VERSION VERSION_GREATER_EQUAL 12.0.0)
    add_executable(ddappsec_helper_fuzzer ${HELPER_SOURCE} main.cpp mutators.cpp)
    set_target_properties(ddappsec_helper_fuzzer PROPERTIES COMPILE_FLAGS "-fsanitize=fuzzer-no-link,address,leak -fprofile-instr-generate -fcoverage-mapping")
    set_target_properties(ddappsec_helper_fuzzer PROPERTIES LINK_FLAGS "-fsanitize=fuzzer-no-link,address,leak -fprofile-instr-generate -fcoverage-mapping")
    target_include_directories(ddappsec_helper_fuzzer PRIVATE ${HELPER_INCLUDE_DIR})

    # Extract major version
    string(REGEX MATCH "^([0-9]+)\\.([0-9]+)\\.([0-9]+)" VERSION_MATCH ${CMAKE_CXX_COMPILER_VERSION})
    set(CXX_VERSION ${CMAKE_CXX_COMPILER_VERSION})
    set(CXX_MAJOR ${CMAKE_MATCH_1})

    message("/usr/lib/llvm-${CXX_MAJOR}/lib/clang/${CXX_VERSION}/lib/linux/")
    target_link_directories(ddappsec_helper_fuzzer PRIVATE 
        "/usr/lib/llvm-${CXX_MAJOR}/lib/clang/${CXX_VERSION}/lib/linux/")
    target_link_libraries(ddappsec_helper_fuzzer
        PRIVATE libddwaf_objects pthread spdlog msgpack_c lib_rapidjson Boost::system libclang_rt.fuzzer_no_main-x86_64.a)

    add_executable(corpus_generator corpus_generator.cpp)
    target_link_libraries(corpus_generator PRIVATE helper_objects libddwaf_objects pthread spdlog)
endif()
