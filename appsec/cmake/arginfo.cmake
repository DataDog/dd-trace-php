# Generate arginfo headers from .stub.php files (make generate_arginfo).
#
# Uses php-src master's gen_stub.php, downloaded on every run, not the one of
# the PHP built against: master's knows the newest PHP versions, an older one
# drops their declarations. So the output changes occasionally, when
# gen_stub.php changes upstream. It downloads PHP-Parser next to itself on first
# use, and needs a PHP 8 interpreter with the tokenizer extension.
set(GEN_STUB_PHP_URL
    "https://raw.githubusercontent.com/php/php-src/master/build/gen_stub.php"
    CACHE STRING "URL of the gen_stub.php used to generate arginfo headers")
set(GEN_STUB_PHP_EXECUTABLE "${PhpConfig_PHP_BINARY}" CACHE FILEPATH
    "PHP interpreter running gen_stub.php (PHP 8+)")
set(_gen_stub_php "${CMAKE_BINARY_DIR}/gen_stub/gen_stub.php")

set(_gen_stub_commands "")
foreach(_stub_name tags entity_body ddappsec)
    list(APPEND _gen_stub_commands COMMAND ${GEN_STUB_PHP_EXECUTABLE}
        ${_gen_stub_php} ${CMAKE_CURRENT_SOURCE_DIR}/src/extension/${_stub_name}.stub.php)
endforeach()

add_custom_target(generate_arginfo
    COMMAND ${CMAKE_COMMAND} -DURL=${GEN_STUB_PHP_URL} -DDEST=${_gen_stub_php}
        -P ${CMAKE_CURRENT_LIST_DIR}/download_file.cmake
    ${_gen_stub_commands}
    WORKING_DIRECTORY ${CMAKE_CURRENT_SOURCE_DIR}/src/extension
    COMMENT "Generating arginfo headers with php-src master's gen_stub.php"
    VERBATIM)
