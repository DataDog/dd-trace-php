#[[ TODO When we have a consistent API across all PHP versions with ZAI
    data-structure shims, move these tests out of PHP-versioned directories.
 ]]
add_executable(functions "${PHP_VERSION_DIRECTORY}/functions.cc")

target_link_libraries(functions PUBLIC catch2_main Zai::Sapi Zai::Functions)

file(COPY ${CMAKE_CURRENT_SOURCE_DIR}/stubs
     DESTINATION ${CMAKE_CURRENT_BINARY_DIR})

catch_discover_tests(functions)
