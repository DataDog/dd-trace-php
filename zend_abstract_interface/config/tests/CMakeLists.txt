# --- Second consumer as an isolated shared library ---
# Compiles all zai_config sources into the .so so it gets its own independent globals.
# PHP/Tea symbols are NOT linked (not PIC); they resolve from the main binary at load time.
add_library(second_consumer_ext MODULE
    ext_second_consumer.cc
    ${PROJECT_SOURCE_DIR}/config/config.c
    ${PROJECT_SOURCE_DIR}/config/config_decode.c
    ${PROJECT_SOURCE_DIR}/config/config_ini.c
    ${PROJECT_SOURCE_DIR}/config/config_stable_file.c
    ${PROJECT_SOURCE_DIR}/config/config_runtime.c
)

set_target_properties(second_consumer_ext PROPERTIES
    POSITION_INDEPENDENT_CODE ON
    PREFIX ""
)

# Headers only — do not link any static library (they aren't PIC).
target_include_directories(second_consumer_ext PRIVATE
    ${PROJECT_SOURCE_DIR}
    ${PROJECT_SOURCE_DIR}/..
    $<TARGET_PROPERTY:Tea::Php,INTERFACE_INCLUDE_DIRECTORIES>
    $<TARGET_PROPERTY:Tea::Tea,INTERFACE_INCLUDE_DIRECTORIES>
    $<TARGET_PROPERTY:Zai::Env,INTERFACE_INCLUDE_DIRECTORIES>
    $<TARGET_PROPERTY:Zai::Json,INTERFACE_INCLUDE_DIRECTORIES>
    $<TARGET_PROPERTY:Zai::String,INTERFACE_INCLUDE_DIRECTORIES>
)

# PHP 7.0 headers (e.g. zend_string.h) use the `register` storage class specifier,
# which was removed in C++17. Cap at C++14 where it is only deprecated, not an error.
set_target_properties(second_consumer_ext PROPERTIES
    CXX_STANDARD 14
    CXX_STANDARD_REQUIRED YES
)
target_compile_features(second_consumer_ext PRIVATE c_std_11)

# Compile definitions from Tea::Php (e.g. ZEND_DEBUG, ZTS) must match the main binary's build.
target_compile_definitions(second_consumer_ext PRIVATE
    $<TARGET_PROPERTY:Tea::Php,INTERFACE_COMPILE_DEFINITIONS>
)

# Allow PHP/Tea/Zai symbols to remain undefined at link time; resolved at load time.
# macOS ld uses -undefined dynamic_lookup; GNU ld uses --unresolved-symbols=ignore-all.
target_link_options(second_consumer_ext PRIVATE
    $<$<PLATFORM_ID:Darwin>:-undefined dynamic_lookup>
    $<$<PLATFORM_ID:Linux>:-Wl,--unresolved-symbols=ignore-all>
)

add_executable(config
    ext_zai_config.cc
    # ext_second_consumer.cc removed — it is now the second_consumer_ext MODULE
    default.cc env.cc id.cc decode.cc ini.cc
)

set(THREADS_PREFER_PTHREAD_FLAG ON)
find_package(Threads REQUIRED)

target_link_libraries(config PUBLIC catch2_main Tea::Tea Zai::Config Threads::Threads)

# Make the .so path available at compile time.
target_compile_definitions(config PRIVATE
    SECOND_CONSUMER_SO_PATH="$<TARGET_FILE:second_consumer_ext>"
)

add_dependencies(config second_consumer_ext)

catch_discover_tests(config)
