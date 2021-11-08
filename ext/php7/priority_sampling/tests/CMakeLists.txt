if(${BUILD_ZAI_TESTING})
    add_executable(priority_sampling ../priority_sampling.c priority_sampling.cc)

    target_include_directories(
        priority_sampling PUBLIC $<BUILD_INTERFACE:${PROJECT_SOURCE_DIR}>
        $<INSTALL_INTERFACE:include>)

    target_compile_features(priority_sampling PUBLIC c_std_99)

    set_target_properties(priority_sampling PROPERTIES EXPORT_NAME PrioritySampling
            VERSION ${PROJECT_VERSION})

    set(THREADS_PREFER_PTHREAD_FLAG ON)
    find_package(Threads REQUIRED)

    target_link_libraries(priority_sampling PUBLIC catch2_main Zai::Sapi Threads::Threads)

    catch_discover_tests(priority_sampling)
endif()