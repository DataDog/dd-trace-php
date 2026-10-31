# Test-only PHP extension for the PHP2Shell mitigation .phpt tests (see
# p2s_stub.c). Built into the extension_dir the xtest target passes to the
# tests (${CMAKE_BINARY_DIR}/extensions), so a test can load it with
# -d extension=p2s_stub.so. It is never installed or packaged.
add_library(p2s_stub MODULE p2s_stub.c)
set_target_properties(p2s_stub PROPERTIES
    C_VISIBILITY_PRESET hidden
    OUTPUT_NAME p2s_stub
    DEBUG_POSTFIX ""
    PREFIX ""
    SUFFIX ".so"
    LIBRARY_OUTPUT_DIRECTORY ${CMAKE_BINARY_DIR}/extensions)
target_link_libraries(p2s_stub PRIVATE PhpConfig)
# The same warning flags as the extension (cmake/extension.cmake).
if(CMAKE_CXX_COMPILER_ID STREQUAL "GNU" AND CMAKE_CXX_COMPILER_VERSION VERSION_LESS 13)
    target_compile_options(p2s_stub PRIVATE -Wall)
else()
    target_compile_options(p2s_stub PRIVATE -Wall -Wextra
        $<$<COMPILE_LANGUAGE:C>:-pedantic> -Werror -Wno-nullability-extension
        -Wno-gnu-zero-variadic-macro-arguments -Wno-gnu-auto-type
        -Wno-language-extension-token)
endif()
